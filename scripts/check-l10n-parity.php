<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * L10n parity gate: every locale JSON must carry the same keys in the same
 * order as en.json (key order matters — the standalone CI gate rejects
 * reordered catalogs).
 */

$l10n = dirname(__DIR__) . '/l10n';
$en = json_decode((string)file_get_contents("$l10n/en.json"), true);
$enKeys = array_keys($en['translations'] ?? []);

$failed = 0;
foreach (glob("$l10n/*.json") ?: [] as $file) {
	$lang = basename($file, '.json');
	if ($lang === 'en') {
		continue;
	}
	$data = json_decode((string)file_get_contents($file), true);
	$keys = array_keys($data['translations'] ?? []);
	if ($keys === $enKeys) {
		continue;
	}
	if (array_diff($enKeys, $keys) !== [] || array_diff($keys, $enKeys) !== []) {
		fwrite(STDERR, "$lang.json: key mismatch vs en.json\n");
	} else {
		fwrite(STDERR, "$lang.json: key order mismatch vs en.json\n");
	}
	$failed++;
}
exit($failed > 0 ? 1 : 0);
