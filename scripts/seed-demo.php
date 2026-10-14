<?php

declare(strict_types=1);

/**
 * Seed/cleanup a deterministic demo dataset for screenshots & manual QA.
 *
 * Creates demo cases wrapped for a given officer uid through the real
 * submit path, so the inbox/case views render realistic (encrypted)
 * content. Cleanup shreds every seeded case via RetentionService::shred().
 *
 * Usage (inside the container):
 *   php scripts/seed-demo.php --uid=<officer> [--count=6]
 *   php scripts/seed-demo.php --uid=<officer> --cleanup
 *
 * The marker ikey prefix `demo-` lets cleanup find its own rows safely.
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

$opts = getopt('', ['uid:', 'count::', 'cleanup']);
$uid = (string)($opts['uid'] ?? '');
$count = (int)($opts['count'] ?? 6);
if ($uid === '') {
	fwrite(STDERR, "usage: seed-demo.php --uid=<officer> [--count=6] [--cleanup]\n");
	exit(2);
}

$app = new Application();
$c = $app->getContainer();
/** @var CaseService $cases */
$cases = $c->get(CaseService::class);
/** @var OfficerKeyService $officerKeys */
$officerKeys = $c->get(OfficerKeyService::class);
/** @var EnvelopeService $envelope */
$envelope = $c->get(EnvelopeService::class);
/** @var WcaseMapper $caseMapper */
$caseMapper = $c->get(WcaseMapper::class);
/** @var CaseKeyWrapMapper $wrapMapper */
$wrapMapper = $c->get(CaseKeyWrapMapper::class);
/** @var RetentionService $retention */
$retention = $c->get(RetentionService::class);
/** @var \OCP\IDBConnection $db */
$db = $c->get(\OCP\IDBConnection::class);

// Find seeded demo cases via their wrap rows for this officer + demo ikeys.
$demoCaseIds = function () use ($db): array {
	$qb = $db->getQueryBuilder();
	$qb->select('case_id')->from('wchk_idem')
		->where($qb->expr()->like('ikey', $qb->createNamedParameter('demo-%')));
	return array_map('intval', array_column($qb->executeQuery()->fetchAll(), 'case_id'));
};

if (isset($opts['cleanup'])) {
	$n = 0;
	foreach ($demoCaseIds() as $caseId) {
		$case = $caseMapper->find($caseId);
		if ($case !== null) {
			$retention->shred($case);
			$n++;
		}
	}
	echo "cleaned $n demo cases\n";
	exit(0);
}

// Reuse the officer's live key when present — registering a new one would
// rotate the pubkey and strand the browser-held private key (the demo
// officer goes through the real key ceremony first).
/** @var \OCA\WhistleCheck\Db\OfficerMapper $officers */
$officers = $c->get(\OCA\WhistleCheck\Db\OfficerMapper::class);
$existing = $officers->findByUid($uid);
if ($existing !== null && $existing->getStatus() === \OCA\WhistleCheck\Db\Officer::STATUS_ACTIVE) {
	$pubkey = $existing->getPubkey();
	$fp = $existing->getFingerprint();
} else {
	$officer = $officerKeys->register($uid, base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())), $uid);
	$pubkey = $officer->getPubkey();
	$fp = $officer->getFingerprint();
}

$titles = [
	'Suspicious invoices to a shell supplier',
	'Unsafe machinery guard removed on line 3',
	'Overtime records altered before audit',
	'Data exported to private storage media',
	'Quality certificates signed without tests',
	'Harassment by shift supervisor',
];

$seeded = [];
for ($i = 0; $i < $count; $i++) {
	$caseKey = $envelope->randomKey();
	$reporterKey = $envelope->randomKey();
	$res = $cases->submit([
		'ikey' => 'demo-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(8)),
		'hint' => bin2hex(random_bytes(32)),
		'verifier' => bin2hex(random_bytes(32)),
		'code_salt' => bin2hex(random_bytes(16)),
		'key_salt' => bin2hex(random_bytes(16)),
		'reporter_wrap' => $envelope->seal($caseKey, $reporterKey),
		'title_cipher' => $envelope->seal($titles[$i % count($titles)], $caseKey),
		'body' => $envelope->seal(str_repeat('Demo report content. ', 60), $caseKey),
		'wraps' => [
			$uid => [
				'wrap' => $envelope->sealToPubkey($caseKey, $pubkey),
				'fp' => $fp,
			],
		],
	], true);
	$seeded[] = (int)$res['case_id'];
}
echo 'seeded ' . count($seeded) . ' demo cases for ' . $uid . ': ' . implode(',', $seeded) . "\n";
