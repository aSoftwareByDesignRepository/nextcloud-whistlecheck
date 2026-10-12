<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Stateless proof-of-work escalation (spec US-006). When rate buckets breach
 * their cap, the API advertises a challenge; the client answers with a nonce
 * making HMAC(challenge ‖ nonce) start with N zero-bits.
 *
 * Challenges are HMAC-bound to (bucket, expiry-slot) — no server state, no
 * replay across windows, no storage to leak.
 */
class PowService
{
	public const DIFFICULTY = 18; // leading zero bits — ~0.3–1.5s in browser WASM

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Issue a challenge for a bucket. `bucket` is already a rotating-salt HMAC —
	 * the challenge itself never contains a raw identifier.
	 *
	 * @return array{challenge:string, difficulty:int, expires:int}
	 */
	public function issue(string $bucket): array
	{
		$slot = $this->slot();
		$challenge = base64_encode(hash_hmac(
			'sha256',
			'wchk-pow-v1|' . $bucket . '|' . $slot,
			$this->secret(),
			true
		));
		return [
			'challenge' => $challenge,
			'difficulty' => self::DIFFICULTY,
			'expires' => ($slot + 1) * 300,
		];
	}

	/**
	 * Verify a client answer: sha256(challenge ‖ bind ‖ nonce) must lead with
	 * `difficulty` zero bits. `bind` pins the proof to one request (submit ikey,
	 * case hint, attachment digest) — a solved challenge cannot be replayed
	 * across different requests, so each escalated request costs fresh work.
	 */
	public function verify(string $bucket, string $challengeB64, string $nonceB64, int $difficulty = self::DIFFICULTY, string $bind = ''): bool
	{
		$challenge = base64_decode($challengeB64, true);
		$nonce = base64_decode($nonceB64, true);
		if ($challenge === false || $nonce === false || strlen($nonce) > 64 || strlen($nonce) < 4) {
			return false;
		}
		// Challenge must be ours for this bucket in the current or previous slot.
		$valid = false;
		foreach ([$this->slot(), $this->slot() - 1] as $slot) {
			$expected = hash_hmac('sha256', 'wchk-pow-v1|' . $bucket . '|' . $slot, $this->secret(), true);
			if (hash_equals($expected, $challenge)) {
				$valid = true;
				break;
			}
		}
		if (!$valid) {
			return false;
		}
		$digest = hash('sha256', $challenge . $bind . $nonce, true);
		return $this->leadingZeroBits($digest) >= $difficulty;
	}

	private function leadingZeroBits(string $bytes): int
	{
		$bits = 0;
		foreach (str_split($bytes) as $b) {
			$v = ord($b);
			for ($m = 7; $m >= 0; $m--) {
				if (($v >> $m) & 1) {
					return $bits;
				}
				$bits++;
			}
		}
		return $bits;
	}

	private function slot(): int
	{
		return intdiv($this->timeFactory->getTime(), 300);
	}

	private function secret(): string
	{
		$s = $this->appConfig->getValueString('whistlecheck', 'pow_secret', '');
		if ($s === '') {
			$s = bin2hex(random_bytes(32));
			$this->appConfig->setValueString('whistlecheck', 'pow_secret', $s);
		}
		return $s;
	}
}
