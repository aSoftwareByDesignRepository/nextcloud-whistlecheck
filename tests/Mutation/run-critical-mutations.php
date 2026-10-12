<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for WhistleCheck security-critical paths (no Infection —
 * it pulls incompatible deps and breaks the Nextcloud bootstrap under Docker).
 *
 * Baseline unit tests must pass; each known-bad source mutation must then be
 * killed by the focused test filter. A surviving mutation = coverage hole.
 *
 * Usage (Docker):
 *   docker compose exec -T nextcloud php /var/www/html/custom_apps/whistlecheck/tests/Mutation/run-critical-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @param list<string> $filters
 */
function run_unit_tests(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --do-not-cache-result --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

$suiteFilters = [
	'CaseCodeServiceTest', 'EnvelopeServiceTest', 'DeadlineServiceTest',
	'SanitizeServiceTest', 'CaseServiceTest', 'AuditServiceTest',
	'RateLimitServiceTest', 'PowServiceTest',
];

echo "== baseline whistlecheck unit tests ==\n";
$baseline = run_unit_tests($appRoot, $phpunit, $suiteFilters);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'verifier_always_valid',
		'file' => 'lib/Crypto/CaseCodeService.php',
		'from' => 'return hash_equals(strtolower($storedCodeHash), hash(\'sha256\', strtolower($presentedVerifierHex)));',
		'to' => 'return true;',
		'filters' => ['CaseCodeServiceTest'],
	],
	[
		'name' => 'drop_confusion_aliases',
		'file' => 'lib/Crypto/CaseCodeService.php',
		'from' => "\$clean = strtoupper(strtr(\$clean, self::DECODE_MAP));",
		'to' => "\$clean = strtoupper(\$clean);",
		'filters' => ['CaseCodeServiceTest'],
	],
	[
		'name' => 'pseudosalt_random',
		'file' => 'lib/Crypto/CaseCodeService.php',
		'from' => "\$raw = hash_hmac('sha256', 'wchk-fakesalt-v1|' . \$lookupHint, \$instanceSecret, true);",
		'to' => "\$raw = random_bytes(32);",
		'filters' => ['CaseCodeServiceTest'],
	],
	[
		'name' => 'skip_alg_check',
		'file' => 'lib/Crypto/EnvelopeService.php',
		'from' => "if (\$env === null || \$env['alg'] !== self::ALG_SECRETBOX) {",
		'to' => "if (\$env === null || false && \$env['alg'] !== self::ALG_SECRETBOX) {",
		'filters' => ['EnvelopeServiceTest'],
	],
	[
		'name' => 'required_alg_ignored',
		'file' => 'lib/Crypto/EnvelopeService.php',
		'from' => "if (\$requiredAlg !== null && \$env['alg'] !== \$requiredAlg) {",
		'to' => "if (false && \$requiredAlg !== null && \$env['alg'] !== \$requiredAlg) {",
		'filters' => ['EnvelopeServiceTest'],
	],
	[
		'name' => 'retention_no_day_clamp',
		'file' => 'lib/Service/DeadlineService.php',
		'from' => "sprintf('%04d-%02d-%02d', \$y, \$m, min(\$d, \$lastDay))",
		'to' => "sprintf('%04d-%02d-%02d', \$y, \$m, \$d)",
		'filters' => ['DeadlineServiceTest'],
	],
	[
		'name' => 'retention_months_zero',
		'file' => 'lib/Service/DeadlineService.php',
		'from' => "'n') + ",
		'to' => "'n') + 0 * ",
		'filters' => ['DeadlineServiceTest'],
	],
	[
		'name' => 'crlf_double_normalize',
		'file' => 'lib/Service/SanitizeService.php',
		'from' => '$input = str_replace("\r", "\n", str_replace("\r\n", "\n", $input));',
		'to' => '$input = str_replace("\r\n", "\n", str_replace("\r", "\n", $input));',
		'filters' => ['SanitizeServiceTest'],
	],
	[
		'name' => 'status_cas_skipped',
		'file' => 'lib/Service/CaseService.php',
		'from' => "if (\$case->getRevision() !== \$revision) {",
		'to' => "if (false && \$case->getRevision() !== \$revision) {",
		'filters' => ['CaseServiceTest'],
	],
	[
		'name' => 'transition_map_bypassed',
		'file' => 'lib/Service/CaseService.php',
		'from' => "if (!in_array(\$to, self::TRANSITIONS[\$from] ?? [], true)) {",
		'to' => "if (false && !in_array(\$to, self::TRANSITIONS[\$from] ?? [], true)) {",
		'filters' => ['CaseServiceTest'],
	],
	[
		'name' => 'audit_whitelist_dropped',
		'file' => 'lib/Service/AuditService.php',
		'from' => "if (!in_array(\$k, self::ALLOWED_PAYLOAD, true)) {",
		'to' => "if (false && !in_array(\$k, self::ALLOWED_PAYLOAD, true)) {",
		'filters' => ['AuditServiceTest'],
	],
	[
		'name' => 'audit_seq_always_one',
		'file' => 'lib/Service/AuditService.php',
		'from' => '$seq = $prev === null ? 1 : $prev->getSeq() + 1;',
		'to' => '$seq = 1;',
		'filters' => ['AuditServiceTest'],
	],
	[
		'name' => 'wrap_fingerprint_unchecked',
		'file' => 'lib/Service/OfficerKeyService.php',
		'from' => 'if (!hash_equals($officer->getFingerprint(), $fp)) {',
		'to' => 'if (false && !hash_equals($officer->getFingerprint(), $fp)) {',
		'filters' => ['OfficerKeyServiceTest'],
	],
	[
		'name' => 'rotation_rewrap_scope_dropped',
		'file' => 'lib/Service/OfficerKeyService.php',
		'from' => 'if (!isset($held[(int)$caseId])) {',
		'to' => 'if (false && !isset($held[(int)$caseId])) {',
		'filters' => ['OfficerKeyServiceTest'],
	],
	[
		'name' => 'device_cap_unchecked',
		'file' => 'lib/Service/CompanionService.php',
		'from' => 'if (count($devices) >= self::MAX_DEVICES) {',
		'to' => 'if (false && count($devices) >= self::MAX_DEVICES) {',
		'filters' => ['CompanionServiceTest'],
	],
	[
		'name' => 'touch_throttle_dropped',
		'file' => 'lib/Service/CompanionService.php',
		'from' => 'if ($last !== null && strtotime($now) - strtotime((string)$last) < 60) {',
		'to' => 'if (false && $last !== null && strtotime($now) - strtotime((string)$last) < 60) {',
		'filters' => ['CompanionServiceTest'],
	],
	[
		'name' => 'ratelimit_no_increment',
		'file' => 'lib/Service/RateLimitService.php',
		'from' => '$hits = $this->mapper->hit($bucket, $kind, $expires);',
		'to' => '$hits = 0;',
		'filters' => ['RateLimitServiceTest'],
	],
];

$failedToKill = [];
foreach ($mutations as $mutation) {
	$name = $mutation['name'];
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutation-bak';
	$filters = $mutation['filters'] ?? $suiteFilters;

	echo "\n== mutation: {$name} ==\n";
	if (!is_file($source)) {
		$failedToKill[] = $name . ' (missing file)';
		continue;
	}
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		$failedToKill[] = $name . ' (anchor missing)';
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original || file_put_contents($source, $mutated) === false) {
		$failedToKill[] = $name . ' (replace failed)';
		restore($source, $backup);
		continue;
	}
	$code = run_unit_tests($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

foreach (glob($appRoot . '/lib/**/*.mutation-bak') ?: [] as $leftover) {
	$restored = preg_replace('/\.mutation-bak$/', '', (string)$leftover);
	if (is_string($restored) && !is_file($restored)) {
		rename($leftover, $restored);
	} else {
		unlink($leftover);
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, "Mutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll whistlecheck critical mutations killed.\n";
exit(0);
