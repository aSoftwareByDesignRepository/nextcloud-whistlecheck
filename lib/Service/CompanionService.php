<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\OfficerMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Companion-app device auth (spec §10.3 / companion support).
 *
 * An officer pairs a device inside the authenticated officer UI: the server
 * issues a random 32-byte bearer token; only sha256(token) is stored (device
 * list shows name + created date, token is shown once). API calls present
 * `Authorization: Bearer wcd_<hex>`; we resolve uid by hash.
 *
 * Replay + staleness controls: every device request must carry
 * `X-Wc-Ts` (unix seconds, ±300s window) — tokens are bearer credentials but
 * the timestamp kills blind replay. Rate limiting is per-uid via the same
 * rotating-salt machinery (key = uid instead of ip).
 */
class CompanionService
{
	private const TOKEN_PREFIX = 'wcd_';

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly OfficerKeyService $officers,
		private readonly OfficerMapper $officerMapper,
		private readonly AuditService $audit,
		private readonly ILockingProvider $lockProvider,
	) {
	}

	/**
	 * Pair a device → returns the one-time token (never stored).
	 *
	 * @return array{device_id:string, token:string}
	 * @throws DomainException
	 */
	public function pair(string $uid, string $deviceName): array
	{
		if (!$this->officers->isOfficer($uid)) {
			throw new DomainException('not_officer', 'Not a case officer', 403);
		}
		$deviceId = bin2hex(random_bytes(8));
		$token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
		$devices = $this->devices($uid);
		$devices[$deviceId] = [
			'name' => mb_substr($deviceName, 0, 64),
			'hash' => hash('sha256', $token),
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'last_seen' => null,
		];
		$this->saveDevices($uid, $devices);
		$this->audit->record('device_paired', $uid, null, []);
		return ['device_id' => $deviceId, 'token' => $token];
	}

	/**
	 * Resolve a bearer token → uid, or null. Timestamp check happens in the
	 * caller (controller) so failures can share the uniform-401 path.
	 */
	public function resolve(string $token): ?string
	{
		if (!str_starts_with($token, self::TOKEN_PREFIX) || strlen($token) !== 68) {
			return null;
		}
		$want = hash('sha256', $token);
		foreach ($this->officerMapper->findActive() as $o) {
			// Row status alone is not enough: an admin can eject an officer from
			// the group directly (bypassing revoke()). Both must hold.
			if (!$this->officers->isOfficer($o->getUid())) {
				continue;
			}
			foreach ($this->devices($o->getUid()) as $id => $d) {
				if (hash_equals((string)$d['hash'], $want)) {
					return $o->getUid() . '|' . $id;
				}
			}
		}
		return null;
	}

	/**
	 * Wipe all paired devices for a revoked officer — their tokens must not
	 * silently reactivate if the uid is ever re-registered.
	 */
	public function purgeOfficerDevices(string $uid): void
	{
		$this->appConfig->deleteKey('whistlecheck', 'devices_' . $uid);
	}

	/**
	 * Mark device as seen (throttled — write at most once per minute).
	 */
	public function touch(string $uid, string $deviceId): void
	{
		$devices = $this->devices($uid);
		if (!isset($devices[$deviceId])) {
			return;
		}
		$now = gmdate('Y-m-d\TH:i:s\Z');
		$last = $devices[$deviceId]['last_seen'] ?? null;
		if ($last !== null && strtotime($now) - strtotime((string)$last) < 60) {
			return;
		}
		$devices[$deviceId]['last_seen'] = $now;
		$this->saveDevices($uid, $devices);
	}

	/** @return array<string,array{name:string,hash:string,created:string,last_seen:?string}> */
	public function devices(string $uid): array
	{
		$raw = $this->appConfig->getValueString('whistlecheck', 'devices_' . $uid, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function revoke(string $uid, string $deviceId): void
	{
		$devices = $this->devices($uid);
		if (isset($devices[$deviceId])) {
			unset($devices[$deviceId]);
			$this->saveDevices($uid, $devices);
			$this->audit->record('device_revoked', $uid, null, []);
		}
	}

	/** @param array<string,array<string,mixed>> $devices */
	private function saveDevices(string $uid, array $devices): void
	{
		try {
			$this->lockProvider->acquireLock('wchk.dev.' . substr(hash('sha256', $uid), 0, 40), ILockingProvider::LOCK_EXCLUSIVE, 'device store');
		} catch (LockedException) {
			throw new \OCA\WhistleCheck\Exception\DomainException('busy', 'Busy — retry', 503);
		}
		try {
			if ($devices === []) {
				$this->appConfig->deleteKey('whistlecheck', 'devices_' . $uid);
			} else {
				$this->appConfig->setValueString('whistlecheck', 'devices_' . $uid, (string)json_encode($devices));
			}
		} finally {
			$this->lockProvider->releaseLock('wchk.dev.' . substr(hash('sha256', $uid), 0, 40), ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
