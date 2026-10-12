<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\RateBucketMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Privacy-preserving rate control (spec §6, C1, US-006).
 *
 * The bucket key is HMAC(ip ‖ kind ‖ window, rotating_salt). The salt rotates
 * daily and is never logged, so no stored row can identify a source address —
 * and each day's buckets unlink from the previous day's.
 *
 * Escalation ladder: normal → rate limited (429 + Retry-After) → PoW
 * escalation (challenge advertised, rate cap doubles after a valid proof).
 * Hits are read-modify-write under a per-bucket lock — concurrent floods
 * cannot overshoot the cap (spec C4).
 */
class RateLimitService
{
	/**
	 * Absolute ceiling as a multiple of the base limit — even with valid PoW.
	 * Spec wants escalation, never a hard block for shared-NAT reporters:
	 * above the base limit every request needs a fresh request-bound PoW
	 * (see PowService::verify $bind), which is the throttle. This multiplier
	 * is the last-resort bound against runaway floods.
	 */
	private const HARD_MULT = 10;

	private const KIND_SUBMIT = 'submit';
	private const KIND_PARAMS = 'params';
	private const KIND_VERIFY = 'verify';
	private const KIND_REPLY = 'reply';
	private const KIND_TOKEN = 'formtoken';
	private const KIND_PWRESET = 'pwreset';

	private const LIMITS = [
		self::KIND_SUBMIT => ['limit' => 5, 'window' => 3600],
		self::KIND_PARAMS => ['limit' => 10, 'window' => 60],
		self::KIND_VERIFY => ['limit' => 10, 'window' => 60],
		self::KIND_REPLY => ['limit' => 30, 'window' => 300],
		self::KIND_TOKEN => ['limit' => 20, 'window' => 600],
		self::KIND_PWRESET => ['limit' => 10, 'window' => 600],
	];

	public function __construct(
		private readonly RateBucketMapper $mapper,
		private readonly ILockingProvider $lockProvider,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * The current bucket identity for a client — raw IP never leaves this call.
	 */
	public function bucketFor(string $ip, string $kind): string
	{
		$spec = self::LIMITS[$kind] ?? null;
		$window = $spec['window'] ?? 60;
		$slot = intdiv($this->timeFactory->getTime(), $window);
		return hash_hmac(
			'sha256',
			'wchk-rate-v1|' . $kind . '|' . $slot,
			$this->dailySalt() . '|' . $ip
		);
	}

	/**
	 * Record one hit. Over the base limit the client must present a valid PoW
	 * bound to *this* request (spec US-006: escalation, not a hard block —
	 * reporters often share a corporate NAT). A documented absolute ceiling
	 * (limit × HARD_MULT) still applies to bound floods.
	 *
	 * @throws DomainException 429 'rate_limited' / 'pow_required'
	 */
	public function check(string $ip, string $kind, bool $powOk = false): void
	{
		$spec = self::LIMITS[$kind] ?? ['limit' => 10, 'window' => 60];
		$bucket = $this->bucketFor($ip, $kind);
		$expires = new \DateTime('@' . (($spec['window'] * 2) + $this->timeFactory->getTime()));

		try {
			$this->lockProvider->acquireLock('wchk.rate.' . substr($bucket, 0, 32), ILockingProvider::LOCK_EXCLUSIVE, 'rate bucket');
		} catch (LockedException) {
			// A concurrent request holds this bucket right now — that IS load.
			// Fail closed as rate-limited rather than leaking a 500.
			throw new DomainException('rate_limited', 'Too many requests', 429, $spec['window']);
		}
		// file_locks.key is varchar(64) — the lock path must stay short; the
		// full 64-char bucket hash would be silently truncated by INSERT IGNORE
		// and the release UPDATE would never match (stale lock, 429 forever).
		try {
			$hits = $this->mapper->hit($bucket, $kind, $expires);
		} finally {
			$this->lockProvider->releaseLock('wchk.rate.' . substr($bucket, 0, 32), ILockingProvider::LOCK_EXCLUSIVE);
		}

		if ($hits > $spec['limit']) {
			$retry = $spec['window'];
			if ($hits > $spec['limit'] * self::HARD_MULT) {
				throw new DomainException('rate_limited', 'Too many requests', 429, $retry);
			}
			if (!$powOk) {
				throw new DomainException('pow_required', 'Too many requests', 429, $retry);
			}
		}
	}

	public function remainingDelay(string $ip, string $kind): int
	{
		$spec = self::LIMITS[$kind] ?? ['limit' => 10, 'window' => 60];
		return $spec['window'];
	}

	public function purgeExpired(): int
	{
		return $this->mapper->purgeExpired(new \DateTime('now'));
	}

	/**
	 * Daily rotating salt — generated lazily per UTC day, stored in app config,
	 * overwritten (not appended) on rotation. The previous day's salt is gone
	 * from the value once rotated; buckets from prior days become unlinkable.
	 */
	public function dailySalt(): string
	{
		$today = gmdate('Y-m-d', $this->timeFactory->getTime());
		$stamp = $this->appConfig->getValueString('whistlecheck', 'rate_salt_day', '');
		$salt = $this->appConfig->getValueString('whistlecheck', 'rate_salt', '');
		if ($stamp === $today && $salt !== '') {
			return $salt;
		}
		// Re-check under a lock: a concurrent first-hit must not write a
		// competing salt (orphaned buckets = silent limit reset).
		try {
			$this->lockProvider->acquireLock('wchk.rate.salt', ILockingProvider::LOCK_EXCLUSIVE, 'salt rotation');
		} catch (LockedException) {
			// Another process is rotating right now — re-read what it wrote.
			$stamp = $this->appConfig->getValueString('whistlecheck', 'rate_salt_day', '');
			$salt = $this->appConfig->getValueString('whistlecheck', 'rate_salt', '');
			if ($salt !== '') {
				// Same value if it rotated mid-flight; deterministic per-day
				// derivation when it wrote a stale-stamp salt (still unlinkable).
				return $stamp === $today ? $salt : hash_hmac('sha256', $today, $salt);
			}
			return hash_hmac('sha256', $today, 'wchk-no-salt-yet');
		}
		try {
			$stamp = $this->appConfig->getValueString('whistlecheck', 'rate_salt_day', '');
			$salt = $this->appConfig->getValueString('whistlecheck', 'rate_salt', '');
			if ($stamp === $today && $salt !== '') {
				return $salt;
			}
			$salt = bin2hex(random_bytes(32));
			$this->appConfig->setValueString('whistlecheck', 'rate_salt', $salt);
			$this->appConfig->setValueString('whistlecheck', 'rate_salt_day', $today);
			return $salt;
		} finally {
			$this->lockProvider->releaseLock('wchk.rate.salt', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
