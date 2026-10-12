<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Crypto;

/**
 * Reporter case code: 128-bit random, Crockford base32, shown as 26 chars in
 * groups of five (US-001 AC-1).
 *
 * Three derived artifacts, three different domains — the server stores only
 * artifacts that cannot recover the code:
 *
 *   hint      = sha256('wchk-hint-v1|'  . code)          — case lookup key (public)
 *   verifier  = argon2id('wchk-ver-v1|' . code, saltV)   — server-side proof hash
 *   K_reporter= argon2id('wchk-key-v1|' . code, saltK)   — wraps the content key
 *
 * The raw code is never transmitted in the JS path; the no-JS fallback derives
 * the same artifacts server-side (transit+memory plaintext, honestly flagged).
 */
final class CaseCodeService
{
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	private const CODE_BYTES = 16;   // 128 bits
	public const CODE_LEN = 26;      // ceil(128 / 5)

	/** Crockford confusion aliases → canonical. */
	private const DECODE_MAP = [
		'O' => '0', 'o' => '0',
		'I' => '1', 'i' => '1',
		'L' => '1', 'l' => '1',
	];

	/**
	 * @return array{code:string, bytes:string} freshly generated case code
	 */
	public function generate(): array
	{
		$bytes = random_bytes(self::CODE_BYTES);
		return ['code' => $this->format($this->encode($bytes)), 'bytes' => $bytes];
	}

	/**
	 * Human form: XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-X
	 */
	public function format(string $canonical): string
	{
		return implode('-', str_split($canonical, 5));
	}

	/**
	 * Strict Crockford encode of 16 bytes → 26 chars.
	 */
	public function encode(string $bytes): string
	{
		if (strlen($bytes) !== self::CODE_BYTES) {
			throw new \InvalidArgumentException('code bytes must be 16');
		}
		// Treat the byte string as a 128-bit big-endian integer stream.
		$bits = '';
		foreach (str_split($bytes) as $byte) {
			$bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
		}
		// Pad to 130 bits (26 × 5).
		$bits = str_pad($bits, self::CODE_LEN * 5, '0', STR_PAD_LEFT);
		$out = '';
		for ($i = 0; $i < self::CODE_LEN; $i++) {
			$out .= self::ALPHABET[bindec(substr($bits, $i * 5, 5))];
		}
		return $out;
	}

	/**
	 * Normalize user input → canonical 26-char Crockford string.
	 * Strips dashes/spaces, folds case, maps O→0 and I/L→1.
	 *
	 * @throws \InvalidArgumentException on any other input
	 */
	public function normalize(string $input): string
	{
		$clean = preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '';
		$clean = strtoupper(strtr($clean, self::DECODE_MAP));
		if (strlen($clean) !== self::CODE_LEN) {
			throw new \InvalidArgumentException('invalid case code length');
		}
		foreach (str_split($clean) as $ch) {
			if (!str_contains(self::ALPHABET, $ch)) {
				throw new \InvalidArgumentException('invalid case code character');
			}
		}
		return $clean;
	}

	/**
	 * Canonical code → raw 16 bytes.
	 */
	public function decode(string $canonical): string
	{
		$bits = '';
		foreach (str_split($canonical) as $ch) {
			$pos = strpos(self::ALPHABET, $ch);
			if ($pos === false) {
				throw new \InvalidArgumentException('invalid case code character');
			}
			$bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
		}
		$bits = substr($bits, -self::CODE_BYTES * 8);
		$out = '';
		for ($i = 0; $i < self::CODE_BYTES; $i++) {
			$out .= chr(bindec(substr($bits, $i * 8, 8)));
		}
		return $out;
	}

	/**
	 * Accepts any user-typed form (dashes optional, case-insensitive) and returns
	 * the raw code bytes or null on malformed input.
	 */
	public function decodeLoose(string $input): ?string
	{
		try {
			return $this->decode($this->normalize($input));
		} catch (\InvalidArgumentException) {
			return null;
		}
	}

	/** Public case fingerprint — safe to store and to use as a lookup key. */
	public function lookupHint(string $codeBytes): string
	{
		return hash('sha256', 'wchk-hint-v1|' . $codeBytes);
	}

	public function newSalt(): string
	{
		return bin2hex(random_bytes(16));
	}

	/**
	 * argon2id verifier — what the server stores (code_hash column).
	 * Computed client-side in the JS path; never reveals the code.
	 */
	public function verifier(string $codeBytes, string $saltHex): string
	{
		if (strlen($saltHex) !== SODIUM_CRYPTO_PWHASH_SALTBYTES * 2 || !ctype_xdigit($saltHex)) {
			throw new \InvalidArgumentException('invalid salt');
		}
		$salt = hex2bin($saltHex);
		return bin2hex(sodium_crypto_pwhash(
			SODIUM_CRYPTO_BOX_SEEDBYTES,
			'wchk-ver-v1|' . $codeBytes,
			$salt,
			SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
		));
	}

	/**
	 * Reporter-side content-key wrap key: argon2id in a *different* domain than
	 * the verifier, so the stored verifier can never unwrap case content.
	 *
	 * @return string raw 32-byte key
	 */
	public function reporterKey(string $codeBytes, string $keySaltHex): string
	{
		if (strlen($keySaltHex) !== SODIUM_CRYPTO_PWHASH_SALTBYTES * 2 || !ctype_xdigit($keySaltHex)) {
			throw new \InvalidArgumentException('invalid salt');
		}
		$salt = hex2bin($keySaltHex);
		return sodium_crypto_pwhash(
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			'wchk-key-v1|' . $codeBytes,
			$salt,
			SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
		);
	}

	/**
	 * Constant-time verifier check (spec: hash_equals, identical payload either way).
	 */
	public function verify(string $presentedVerifierHex, string $storedCodeHash): bool
	{
		return hash_equals($storedCodeHash, $presentedVerifierHex);
	}

	/**
	 * Deterministic pseudo-salt for unknown hints — keeps the params endpoint
	 * response shape identical whether or not the case exists (no oracle).
	 */
	public function pseudoSalt(string $lookupHint, string $instanceSecret): string
	{
		$raw = hash_hmac('sha256', 'wchk-fakesalt-v1|' . $lookupHint, $instanceSecret, true);
		return substr(bin2hex($raw), 0, 32);
	}
}
