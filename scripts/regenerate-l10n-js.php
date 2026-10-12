<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate l10n/<lang>.js OC.L10N.register bundles from the JSON catalogs.
 */

$l10n = dirname(__DIR__) . '/l10n';
foreach (glob("$l10n/*.json") ?: [] as $file) {
	$lang = basename($file, '.json');
	$data = json_decode((string)file_get_contents($file), true);
	$out = "OC.L10N.register(\n\t\"whistlecheck\",\n\t"
		. json_encode($data['translations'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
		. ",\n\t\"nplurals=2; plural=(n != 1);\"\n);\n";
	file_put_contents("$l10n/$lang.js", $out);
	echo "$lang.js regenerated\n";
}
