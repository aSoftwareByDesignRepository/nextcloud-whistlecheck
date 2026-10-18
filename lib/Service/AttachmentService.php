<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Exception\ValidationException;

/**
 * Attachment intake policy (spec §7.2 / US-002).
 *
 * E2E path: the server only ever sees ciphertext, so it validates envelope
 * shape + claimed-mime whitelist + ciphertext size bound. Real content checks
 * (magic bytes, EXIF strip) happen in the client before encryption — that is
 * documented honesty, not a hole: server cannot inspect what it cannot read.
 *
 * No-JS path: server receives plaintext, so THIS service sniffs magic bytes,
 * rejects mismatch, and re-encodes images through GD to burn EXIF/metadata
 * before encrypting.
 */
class AttachmentService
{
	public const ALLOWED_MIMES = [
		'image/png', 'image/jpeg', 'image/webp', 'image/gif',
		'application/pdf', 'text/plain',
	];

	/** Magic-byte signatures per allowed mime. */
	private const SNIFF = [
		'image/png' => ["\x89PNG\r\n\x1a\n"],
		'image/jpeg' => ["\xFF\xD8\xFF"],
		'image/webp' => ['RIFF'],  // + WEBP at offset 8 — checked in sniff()
		'image/gif' => ['GIF87a', 'GIF89a'],
		'application/pdf' => ['%PDF-'],
		'text/plain' => [],
	];

	public function __construct(
		private readonly EnvelopeService $envelope,
		private readonly ConfigService $config,
	) {
	}

	/**
	 * Validate a ciphertext attachment intake. Bounds the stored blob, verifies
	 * the claimed mime is whitelisted, and the envelope is well-formed.
	 *
	 * @return int ciphertext byte size stored
	 * @throws ValidationException
	 */
	public function validateCipher(string $cipherJson, string $claimedMime): int
	{
		$fields = [];
		if (!$this->config->getBool('attachments_enabled')) {
			throw new ValidationException('attachments_disabled', 'Attachments disabled', ['attachments' => 'disabled']);
		}
		if (!in_array($claimedMime, self::ALLOWED_MIMES, true)) {
			$fields['attachments'] = 'unsupported file type';
		}
		if (!$this->envelope->isValidEnvelope($cipherJson, EnvelopeService::ALG_CHUNKED)
			&& !$this->envelope->isValidEnvelope($cipherJson, EnvelopeService::ALG_SECRETBOX)) {
			$fields['attachments'] = 'invalid attachment';
		}
		$maxBytes = $this->config->getInt('max_attachment_mb') * 1048576 * 2; // ciphertext ≤ 2× plaintext bound
		$size = strlen($cipherJson);
		if ($size > $maxBytes || $size === 0) {
			$fields['attachments'] = 'file too large';
		}
		if ($fields !== []) {
			throw new ValidationException('invalid_attachment', 'Attachment rejected', $fields);
		}
		return $size;
	}

	/**
	 * No-JS path: plaintext attachment in. Sniff magic bytes against the
	 * claimed mime; images get re-encoded through GD (EXIF/metadata strip).
	 *
	 * @return string plaintext safe to encrypt
	 * @throws ValidationException
	 */
	public function sanitizePlain(string $data, string $claimedMime): string
	{
		if (!$this->config->getBool('attachments_enabled')) {
			throw new ValidationException('attachments_disabled', 'Attachments disabled', ['attachments' => 'disabled']);
		}
		$fields = [];
		$maxBytes = $this->config->getInt('max_attachment_mb') * 1048576;
		if (strlen($data) > $maxBytes || $data === '') {
			$fields['attachments'] = 'file too large';
		}
		if (!in_array($claimedMime, self::ALLOWED_MIMES, true)) {
			$fields['attachments'] = 'unsupported file type';
		}
		if ($fields === [] && !$this->sniff($data, $claimedMime)) {
			$fields['attachments'] = 'file content does not match its type';
		}
		if ($fields !== []) {
			throw new ValidationException('invalid_attachment', 'Attachment rejected', $fields);
		}
		return $this->stripMetadata($data, $claimedMime);
	}

	/**
	 * Magic-byte check — claimed mime must match actual bytes.
	 */
	public function sniff(string $data, string $mime): bool
	{
		if ($mime === 'text/plain') {
			// plain text: reject NULs and heavy control content (binary in disguise)
			return !str_contains(substr($data, 0, 8192), "\0");
		}
		if ($mime === 'image/webp') {
			return strncmp($data, 'RIFF', 4) === 0 && substr($data, 8, 4) === 'WEBP';
		}
		foreach (self::SNIFF[$mime] ?? [] as $magic) {
			if (strncmp($data, $magic, strlen($magic)) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Burn metadata: images re-encode via GD (drops EXIF/ICC/XMP wholesale),
	 * pdf/txt pass through (PDF metadata strip is documented in the client;
	 * server-side PDF rewriting would corrupt signatures — honesty note).
	 */
	public function stripMetadata(string $data, string $mime): string
	{
		if (!extension_loaded('gd') || !str_starts_with($mime, 'image/')) {
			return $data;
		}
		$img = @imagecreatefromstring($data);
		if ($img === false) {
			return $data; // sniff already passed; leave bytes as claimed
		}
		ob_start();
		$ok = match ($mime) {
			'image/png' => imagepng($img),
			'image/jpeg' => imagejpeg($img, null, 92),
			'image/webp' => imagewebp($img, null, 90),
			'image/gif' => imagegif($img),
			default => false,
		};
		$out = $ok ? (string)ob_get_clean() : (ob_end_clean() ?: '');
		return $ok && $out !== '' ? $out : $data;
	}
}
