<?php

declare(strict_types=1);

/* Integration runs always load the real Nextcloud tree (Docker). */

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}
if (!defined('PHPUNIT_RUNNING')) {
	define('PHPUNIT_RUNNING', true);
}

$base = null;
$candidates = [];
$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '';
if ($nextcloudRoot !== '') {
	$candidates[] = rtrim($nextcloudRoot, '/\\') . '/lib/base.php';
}
$candidates[] = __DIR__ . '/../../../lib/base.php';
$candidates[] = '/var/www/html/lib/base.php';
foreach ($candidates as $candidate) {
	if (is_file($candidate)) {
		$base = $candidate;
		break;
	}
}
if ($base === null) {
	fwrite(STDERR, "Integration tests require a Nextcloud tree (run inside Docker).\n");
	exit(1);
}
require_once $base;
$integrationBootstrap = dirname(__DIR__, 3) . '/scripts/phpunit-integration-bootstrap.php';
if (is_file($integrationBootstrap)) {
	require_once $integrationBootstrap;
}
require_once __DIR__ . '/../vendor/autoload.php';
