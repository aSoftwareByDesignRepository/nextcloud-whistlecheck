#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate l10n/<lang>.js OC.L10N.register bundles from the JSON catalogs.
 * Delegates to the shared generator (2-arg register() — pluralForm stays in
 * the .json for tooling; emitting it in .js is a CSP unsafe-eval risk and
 * a hardcoded nplurals=2 is wrong for pl/fr/pt_BR anyway).
 *
 * Usage: php scripts/regenerate-l10n-js.php
 */

$root = realpath(__DIR__ . '/../../..');
if ($root === false) {
	fwrite(STDERR, "Cannot resolve nextcloud root\n");
	exit(1);
}
$l10n = __DIR__ . '/../l10n';
$locales = [];
foreach (glob($l10n . '/*.json') ?: [] as $file) {
	$locales[] = basename($file, '.json');
}
if ($locales === []) {
	fwrite(STDERR, "No l10n/*.json catalogs found\n");
	exit(1);
}
$cmd = PHP_BINARY . ' ' . escapeshellarg($root . '/scripts/l10n/regenerate-l10n-js.php')
	. ' --app=whistlecheck --locales=' . implode(',', $locales);
passthru($cmd, $code);
exit($code);
