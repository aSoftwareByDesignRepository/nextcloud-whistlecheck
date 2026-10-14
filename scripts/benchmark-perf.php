<?php

declare(strict_types=1);

/**
 * WhistleCheck performance evidence harness (spec NFR-5 / NFR-6).
 *
 * Measures three spec gates on a real stack:
 *   A) Officer inbox @ WC_BENCH_CASES cases — listInbox + wrap-resolution
 *      surface of api#inbox (NFR: 500 cases/officer without degradation).
 *   B) Submission burst — real cases->submit() throughput vs 60/min.
 *   C) Public report-form TTFB — real HTTP GET /report, p95 < 500 ms.
 *
 * Everything goes through production service paths — no fixtures, no stubs.
 * Seeded cases are crypto-shredded afterwards via RetentionService::shred().
 *
 * Env:
 *   WC_BENCH_CASES    default 500   (seeded case count for phase A)
 *   WC_BENCH_SAMPLES  default 40    (latency samples for phase A)
 *   WC_BENCH_TTFB_SAMPLES default 40 (phase C samples, paced ~6.5 s apart)
 *   WC_BENCH_TTFB_INTERVAL_US default 6500000 (0 = no pacing)
 *   WC_BENCH_BURST    default 60    (phase B submissions)
 *   WC_BENCH_QUICK=1  → 120 / 15 / 20
 *   WC_BENCH_KEEP=1   → skip cleanup (debugging)
 *   WC_BENCH_BASE_URL default http://localhost (as seen from inside the container)
 */

if (!class_exists(\OC::class)) {
	require '/var/www/html/lib/base.php';
}

use OCA\WhistleCheck\AppInfo\Application;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Db\CaseKeyWrapMapper;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Service\CaseService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCA\WhistleCheck\Service\RetentionService;
use OCP\IGroupManager;
use OCP\IUserManager;

$quick = getenv('WC_BENCH_QUICK') === '1';
$caseTarget = (int)(getenv('WC_BENCH_CASES') ?: ($quick ? 120 : 500));
$samples = (int)(getenv('WC_BENCH_SAMPLES') ?: ($quick ? 15 : 40));
$ttfbSamples = (int)(getenv('WC_BENCH_TTFB_SAMPLES') ?: ($quick ? 8 : 40));
// GET /report draws from the 'params' rate bucket (10/min per IP by design).
// Sustained TTFB is measured under normal load — pace samples so the bench
// itself stays inside the limiter window.
$ttfbIntervalUs = (int)(getenv('WC_BENCH_TTFB_INTERVAL_US') ?: ($quick ? 0 : 6_500_000));
$burst = (int)(getenv('WC_BENCH_BURST') ?: ($quick ? 20 : 60));
$baseUrl = rtrim((string)(getenv('WC_BENCH_BASE_URL') ?: 'http://localhost'), '/');
$keep = getenv('WC_BENCH_KEEP') === '1';
$benchUid = 'wc_bench_officer';

function pct(array $sortedMs, float $q): float
{
	if ($sortedMs === []) {
		return 0.0;
	}
	$idx = (int)ceil($q / 100 * count($sortedMs)) - 1;
	return $sortedMs[max(0, min($idx, count($sortedMs) - 1))];
}

/** @return array{avg:float,p50:float,p95:float,p99:float,max:float} */
function stats(array $ms): array
{
	sort($ms);
	$n = count($ms);
	return [
		'avg' => $n ? array_sum($ms) / $n : 0.0,
		'p50' => pct($ms, 50),
		'p95' => pct($ms, 95),
		'p99' => pct($ms, 99),
		'max' => $n ? $ms[$n - 1] : 0.0,
	];
}

function line(array $s, float $threshold = -1): string
{
	$verdict = $threshold <= 0 ? '' : ($s['p95'] <= $threshold ? ' — PASS' : ' — FAIL');
	return sprintf(
		'avg=%.1f ms · p50=%.1f · p95=%.1f · p99=%.1f · max=%.1f%s',
		$s['avg'], $s['p50'], $s['p95'], $s['p99'], $s['max'], $verdict
	);
}

$app = new Application();
$c = $app->getContainer();
/** @var CaseService $cases */
$cases = $c->get(CaseService::class);
/** @var WcaseMapper $caseMapper */
$caseMapper = $c->get(WcaseMapper::class);
/** @var CaseKeyWrapMapper $wrapMapper */
$wrapMapper = $c->get(CaseKeyWrapMapper::class);
/** @var OfficerKeyService $officerKeys */
$officerKeys = $c->get(OfficerKeyService::class);
/** @var EnvelopeService $envelope */
$envelope = $c->get(EnvelopeService::class);
/** @var RetentionService $retention */
$retention = $c->get(RetentionService::class);
/** @var IUserManager $users */
$users = $c->get(IUserManager::class);
/** @var IGroupManager $groups */
$groups = $c->get(IGroupManager::class);

echo "== WhistleCheck perf benchmark ==\n";
echo "targets: cases=$caseTarget samples=$samples burst=$burst base=$baseUrl\n";

// ── Provision bench officer ─────────────────────────────────────────────
$user = $users->get($benchUid);
if ($user === null) {
	$user = $users->createUser($benchUid, bin2hex(random_bytes(12)));
	if ($user === null) {
		fwrite(STDERR, "could not create bench officer\n");
		exit(2);
	}
	echo "created bench officer $benchUid\n";
}
$group = $groups->get(OfficerKeyService::GROUP);
if ($group === null) {
	$group = $groups->createGroup(OfficerKeyService::GROUP);
}
if ($group !== null && !$group->inGroup($user)) {
	$group->addUser($user);
}

$kp = sodium_crypto_box_keypair();
$pubkey = base64_encode(sodium_crypto_box_publickey($kp));
$officer = $officerKeys->register($benchUid, $pubkey, $benchUid);
$fp = $officer->getFingerprint();
echo "officer key registered (fp " . substr($fp, 0, 12) . "…)\n";

// ── Seed cases via the real submit path ──────────────────────────────────
$seeded = [];
$seedStart = microtime(true);
for ($i = 0; $i < $caseTarget; $i++) {
	$caseKey = $envelope->randomKey();
	$reporterKey = $envelope->randomKey();
	$body = $envelope->seal(str_repeat('Q', 2048), $caseKey); // ~2 KiB ciphertext
	$res = $cases->submit([
		'ikey' => 'bench-' . bin2hex(random_bytes(16)),
		'hint' => bin2hex(random_bytes(32)),
		'verifier' => bin2hex(random_bytes(32)),
		'code_salt' => bin2hex(random_bytes(16)),
		'key_salt' => bin2hex(random_bytes(16)),
		'reporter_wrap' => $envelope->seal($caseKey, $reporterKey),
		'body' => $body,
		'wraps' => [
			$benchUid => [
				'wrap' => $envelope->sealToPubkey($caseKey, $pubkey),
				'fp' => $fp,
			],
		],
	], true);
	$seeded[] = (int)$res['case_id'];
}
$seedSec = microtime(true) - $seedStart;
printf("seeded %d cases in %.1f s (%.0f/min)\n", count($seeded), $seedSec, count($seeded) / max(0.001, $seedSec) * 60);

// ── Phase A: inbox surface @ N cases ─────────────────────────────────────
// Mirrors api#inbox: listInbox(501) + batched wrap resolution.
$listMs = [];
$fullMs = [];
for ($i = 0; $i < $samples; $i++) {
	$t0 = microtime(true);
	$rows = $caseMapper->listInbox(501);
	$tList = microtime(true);
	$rows = array_slice($rows, 0, 500);
	$wrapsByCase = $wrapMapper->findForCasesAndOfficer(
		array_map(fn ($c) => (int)$c->getId(), $rows),
		$benchUid
	);
	$visible = 0;
	foreach ($rows as $case) {
		if (isset($wrapsByCase[$case->getId()])) {
			$visible++;
		}
	}
	$fullMs[] = (microtime(true) - $t0) * 1000;
	$listMs[] = ($tList - $t0) * 1000;
}
$listStats = stats($listMs);
$fullStats = stats($fullMs);
echo "\n[A] listInbox(501)            : " . line($listStats, 500) . "\n";
echo "[A] inbox wrap-resolution loop: " . line($fullStats, 500) . "  (visible=$visible/500)\n";

// ── Phase B: submission burst ────────────────────────────────────────────
$burstMs = [];
$burstErrors = 0;
$burstIds = [];
$b0 = microtime(true);
for ($i = 0; $i < $burst; $i++) {
	$caseKey = $envelope->randomKey();
	$t0 = microtime(true);
	try {
		$res = $cases->submit([
			'ikey' => 'burst-' . bin2hex(random_bytes(16)),
			'hint' => bin2hex(random_bytes(32)),
			'verifier' => bin2hex(random_bytes(32)),
			'code_salt' => bin2hex(random_bytes(16)),
			'key_salt' => bin2hex(random_bytes(16)),
			'reporter_wrap' => $envelope->seal($caseKey, $envelope->randomKey()),
			'body' => $envelope->seal(str_repeat('B', 2048), $caseKey),
			'wraps' => [
				$benchUid => [
					'wrap' => $envelope->sealToPubkey($caseKey, $pubkey),
					'fp' => $fp,
				],
			],
		], true);
		$burstIds[] = (int)$res['case_id'];
	} catch (\Throwable $e) {
		$burstErrors++;
	}
	$burstMs[] = (microtime(true) - $t0) * 1000;
}
$burstSec = microtime(true) - $b0;
$perMin = $burst / max(0.001, $burstSec) * 60;
$seeded = array_merge($seeded, $burstIds);
printf(
	"\n[B] submit burst %d ops: %s\n[B] sustained %.0f/min (target ≥ 60/min)%s — errors: %d\n",
	$burst,
	line(stats($burstMs), 0),
	$perMin,
	$perMin >= 60 && $burstErrors === 0 ? ' — PASS' : ' — FAIL',
	$burstErrors
);

// ── Phase C: public form TTFB ────────────────────────────────────────────
$ttfb = [];
$httpFails = 0;
$rateLimited = 0;
$failCodes = [];
$reportUrl = $baseUrl . '/index.php/apps/whistlecheck/report';
for ($i = 0; $i < $ttfbSamples; $i++) {
	$ch = curl_init($reportUrl);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT => 10,
		CURLOPT_NOBODY => false,
	]);
	curl_exec($ch);
	$info = curl_getinfo($ch);
	$code = (int)($info['http_code'] ?? 0);
	if ($code === 429) {
		$rateLimited++; // bench outpaced the limiter — not a server error
	} elseif ($code !== 200) {
		$httpFails++;
		$failCodes[] = $code;
	} else {
		$ttfb[] = (float)$info['starttransfer_time'] * 1000;
	}
	curl_close($ch);
	if ($ttfbIntervalUs > 0 && $i < $ttfbSamples - 1) {
		usleep($ttfbIntervalUs);
	}
}
$ttfbStats = stats($ttfb);
echo "\n[C] GET /report TTFB          : " . line($ttfbStats, 500) . "  (http_fails=$httpFails" . ($failCodes !== [] ? ' codes=' . implode(',', $failCodes) : '') . " rate_429=$rateLimited)\n";

// ── Cleanup ──────────────────────────────────────────────────────────────
if (!$keep) {
	$shredded = 0;
	foreach ($seeded as $caseId) {
		$case = $caseMapper->find($caseId);
		if ($case !== null) {
			$retention->shred($case);
			$shredded++;
		}
	}
	$officerKeys->revoke($benchUid, $benchUid);
	$group?->removeUser($user);
	$user->delete();
	echo "\ncleanup: shredded $shredded cases, removed bench officer\n";
} else {
	echo "\nWC_BENCH_KEEP=1 — bench data left in place\n";
}

// ── Machine-readable summary (paste into qa-report/performance.md) ───────
echo "\n<!-- MEASURED_PERF -->\n";
printf(
	"cases=%d samples=%d burst=%d ttfb_n=%d | A-list p95=%.1f · A-full p95=%.1f | B rate=%.0f/min err=%d | C ttfb p95=%.1f fails=%d rate_429=%d\n",
	$caseTarget,
	$samples,
	$burst,
	$ttfbSamples,
	$listStats['p95'],
	$fullStats['p95'],
	$perMin,
	$burstErrors,
	$ttfbStats['p95'],
	$httpFails,
	$rateLimited
);
echo "<!-- /MEASURED_PERF -->\n";

$fail = ($listStats['p95'] > 500) || ($fullStats['p95'] > 500)
	|| $perMin < 60 || $burstErrors > 0
	|| ($ttfb !== [] && $ttfbStats['p95'] > 500) || $httpFails > 0;
exit($fail ? 1 : 0);
