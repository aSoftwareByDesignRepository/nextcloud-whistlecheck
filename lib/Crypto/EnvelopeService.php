<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Crypto;

/**
 * Canonical ciphertext wire format shared by the JS client and the no-JS
 * server-side fallback (spec §9 crypto envelope):
 *
 *   secretbox payload : {"v":1,"alg":"xs20p1305","n":"<b64 24B>","c":"<b64>"}
 *   chunked payload   : {"v":1,"alg":"xs20p1305-chunk","n":"<b64 24B>",
 *                        "chunks":["<b64>", ...]}   (nonce_i = baseNonce + i)
 *   officer wrap      : {"v":1,"alg":"x25519-seal","c":"<b64 sealed box>"}
 *   reporter wrap     : secretbox payload under K_reporter
 *
 * The server VALIDATES envelope shape on intake (never decrypts officer-bound
 * ciphertext — it cannot) and produces identical envelopes on the no-JS path.
 */
final class EnvelopeService
{
	public const ALG_SECRETBOX = 'xs20p1305';
	public const ALG_CHUNKED = 'xs20p1305-chunk';
	public const ALG_SEALEDBOX = 'x25519-seal';

	public const MAX_CIPHER_BYTES = 64 * 1024 * 1024; // hard safety bound on any blob

	public function randomKey(): string
	{
		return sodium_crypto_secretbox_keygen();
	}

	/**
	 * XSalsa20-Poly1305 secretbox → canonical JSON envelope.
	 */
	public function seal(string $plaintext, string $key): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$ct = sodium_crypto_secretbox($plaintext, $nonce, $key);
		return (string)json_encode([
			'v' => 1,
			'alg' => self::ALG_SECRETBOX,
			'n' => base64_encode($nonce),
			'c' => base64_encode($ct),
		], JSON_THROW_ON_ERROR);
	}

	/**
	 * @return string|null plaintext, or null when MAC fails / shape is wrong
	 */
	public function open(string $envelopeJson, string $key): ?string
	{
		$env = $this->decodeEnvelope($envelopeJson);
		if ($env === null || $env['alg'] !== self::ALG_SECRETBOX) {
			return null;
		}
		$nonce = base64_decode((string)$env['n'], true);
		$ct = base64_decode((string)$env['c'], true);
		if ($nonce === false || $ct === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open($ct, $nonce, $key);
		} catch (\Throwable) {
			return null;
		}
		return $plain === false ? null : $plain;
	}

	/**
	 * Chunked secretbox for attachments (bounded memory client-side). Each chunk
	 * uses nonce = baseNonce incremented by index — deterministic, unique.
	 */
	public function sealChunked(string $data, string $key, int $chunkSize = 4194304): string
	{
		if ($chunkSize < 65536) {
			throw new \InvalidArgumentException('chunk size too small');
		}
		$baseNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$chunks = [];
		$offset = 0;
		$index = 0;
		$len = strlen($data);
		do {
			$part = substr($data, $offset, $chunkSize);
			$chunks[] = base64_encode(sodium_crypto_secretbox($part, $this->nonceAt($baseNonce, $index), $key));
			$offset += $chunkSize;
			$index++;
		} while ($offset < $len);
		return (string)json_encode([
			'v' => 1,
			'alg' => self::ALG_CHUNKED,
			'n' => base64_encode($baseNonce),
			'chunks' => $chunks,
		], JSON_THROW_ON_ERROR);
	}

	/**
	 * @return string|null plaintext, null on MAC failure anywhere
	 */
	public function openChunked(string $envelopeJson, string $key): ?string
	{
		$env = $this->decodeEnvelope($envelopeJson);
		if ($env === null || $env['alg'] !== self::ALG_CHUNKED) {
			return null;
		}
		$baseNonce = base64_decode((string)$env['n'], true);
		if ($baseNonce === false || strlen($baseNonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			return null;
		}
		$chunks = $env['chunks'] ?? null;
		if (!is_array($chunks) || $chunks === [] || count($chunks) > 4096) {
			return null;
		}
		$out = '';
		foreach (array_values($chunks) as $i => $b64) {
			if (!is_string($b64)) {
				return null;
			}
			$ct = base64_decode($b64, true);
			if ($ct === false) {
				return null;
			}
			try {
				$plain = sodium_crypto_secretbox_open($ct, $this->nonceAt($baseNonce, $i), $key);
			} catch (\Throwable) {
				return null;
			}
			if ($plain === false) {
				return null;
			}
			$out .= $plain;
		}
		return $out;
	}

	/**
	 * Sealed box to an officer X25519 public key (anonymous sender side).
	 */
	public function sealToPubkey(string $plaintext, string $pubkeyB64): string
	{
		$pk = base64_decode($pubkeyB64, true);
		if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			throw new \InvalidArgumentException('invalid officer public key');
		}
		$ct = sodium_crypto_box_seal($plaintext, $pk);
		return (string)json_encode([
			'v' => 1,
			'alg' => self::ALG_SEALEDBOX,
			'c' => base64_encode($ct),
		], JSON_THROW_ON_ERROR);
	}

	/**
	 * Envelope *shape* check for intake — proves the blob is a valid ciphertext
	 * container without decrypting anything. Returns normalized envelope or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function decodeEnvelope(string $json): ?array
	{
		if (strlen($json) > self::MAX_CIPHER_BYTES) {
			return null;
		}
		try {
			$env = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($env) || ($env['v'] ?? null) !== 1 || !isset($env['alg']) || !is_string($env['alg'])) {
			return null;
		}
		return $env;
	}

	/**
	 * Strict intake validation for stored ciphertexts.
	 */
	public function isValidEnvelope(string $json, ?string $requiredAlg = null): bool
	{
		$env = $this->decodeEnvelope($json);
		if ($env === null) {
			return false;
		}
		if ($requiredAlg !== null && $env['alg'] !== $requiredAlg) {
			return false;
		}
		return match ($env['alg']) {
			self::ALG_SECRETBOX => isset($env['n'], $env['c'])
				&& is_string($env['n']) && is_string($env['c'])
				&& $this->b64Len($env['n']) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
				&& $this->b64Len($env['c']) !== null && $this->b64Len($env['c']) > SODIUM_CRYPTO_SECRETBOX_MACBYTES,
			self::ALG_CHUNKED => isset($env['n'], $env['chunks'])
				&& is_string($env['n'])
				&& $this->b64Len($env['n']) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
				&& is_array($env['chunks']) && $env['chunks'] !== [] && count($env['chunks']) <= 4096
				&& !in_array(false, array_map(static fn ($c) => is_string($c) && strlen((string)base64_decode((string)$c, true)) > SODIUM_CRYPTO_SECRETBOX_MACBYTES, $env['chunks']), true),
			self::ALG_SEALEDBOX => isset($env['c']) && is_string($env['c'])
				&& $this->b64Len($env['c']) !== null && $this->b64Len($env['c']) > SODIUM_CRYPTO_BOX_SEALBYTES,
			default => false,
		};
	}

	private function b64Len(string $b64): ?int
	{
		$raw = base64_decode($b64, true);
		return $raw === false ? null : strlen($raw);
	}

	/**
	 * nonce_i = baseNonce incremented by i (little-endian-safe big integer add on
	 * the raw 24-byte nonce — sodium increments work the same way).
	 */
	private function nonceAt(string $baseNonce, int $i): string
	{
		$nonce = $baseNonce;
		$carry = $i;
		for ($p = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES - 1; $p >= 0 && $carry > 0; $p--) {
			$sum = ord($nonce[$p]) + ($carry & 0xFF);
			$nonce[$p] = chr($sum & 0xFF);
			$carry = intdiv($carry, 256) + ($sum >> 8);
		}
		return $nonce;
	}

	/**
	 * Officer pubkey fingerprint: sha256 of raw key bytes (hex) — shown to
	 * officers so they can visually verify key continuity (US-007).
	 */
	public function pubkeyFingerprint(string $pubkeyB64): string
	{
		$raw = base64_decode($pubkeyB64, true);
		if ($raw === false) {
			return '';
		}
		return hash('sha256', $raw);
	}
}
