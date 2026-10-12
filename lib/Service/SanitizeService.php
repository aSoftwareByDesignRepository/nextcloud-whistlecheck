<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

/**
 * Reporter-content handling for the two render surfaces (spec §7.1, NN-05):
 *
 *   officer UI : decrypted client-side, rendered via textContent only — the
 *                officer bundle NEVER html-injects reporter bytes. This class
 *                provides the belt for that suspenders: a plaintext allowlist
 *                normalizer (used on the no-JS server-render path and for the
 *                sealed-PDF text export).
 *
 * Strips every byte that could form markup: control chars, bidi overrides,
 * zero-widths, surrogates. Output is display-safe when escaped — and our
 * templates always escape regardless.
 */
class SanitizeService
{
	public const MAX_TEXT = 200_000;
	public const MAX_TITLE = 300;
	public const MAX_CONTACT = 2000;

	/**
	 * Normalize reporter-typed text: keep it plain, printable, honest.
	 */
	public function plainText(string $input, int $max = self::MAX_TEXT): string
	{
		$input = str_replace("\0", '', $input);
		// Strip C0/C1 control chars except \t \r \n.
		$input = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $input);
		// Bidi overrides + zero-width + line/paragraph separators that smuggle layout.
		$input = (string)preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{FEFF}]/u', '', $input);
		$input = str_replace("\r", "\n", str_replace("\r\n", "\n", $input));
		// Collapse 3+ blank lines.
		$input = (string)preg_replace('/\n{3,}/', "\n\n", $input);
		if (mb_strlen($input) > $max) {
			$input = mb_substr($input, 0, $max);
		}
		return trim($input);
	}

	/**
	 * Escape for an HTML text node — the ONLY rendering mode reporter bytes
	 * ever get (spec NN-05). Used by the no-JS path; JS uses textContent.
	 */
	public function escapeForDisplay(string $plain): string
	{
		return htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * Attachment filename for display/download headers — strips path parts,
	 * controls, and anything that could break a Content-Disposition header.
	 */
	public function filename(string $name): string
	{
		$name = basename(str_replace('\\', '/', $name));
		$name = (string)preg_replace('/[^\p{L}\p{N}\p{M}._ ()\-]/u', '', $name);
		$name = trim($name, ' .');
		if ($name === '' || $name === '.' || $name === '..') {
			$name = 'attachment';
		}
		return mb_substr($name, 0, 200);
	}
}
