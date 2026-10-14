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

// The app's own autoloader must exist before base.php boots Nextcloud: during
// app loading, every enabled app's Application.php re-registers its vendor
// loader APPENDED (so OCP stubs don't shadow the server). That pushes this
// app's loader behind sibling apps' vendors — an older PHPUnit in a sibling
// vendor then shadows PHPUnit\Framework\TestSuite and PHPUnit crashes with
// "Call to undefined method TestSuite::empty()".
$appLoader = require __DIR__ . '/../vendor/autoload.php';
require_once $base;
$integrationBootstrap = dirname(__DIR__, 3) . '/scripts/phpunit-integration-bootstrap.php';
if (is_file($integrationBootstrap)) {
	require_once $integrationBootstrap;
}

// Our vendor loader sits behind sibling-app vendors after boot (see above), so
// prepend a scoped resolver: dev/test classes (PHPUnit, mocks) resolve from OUR
// vendor, while OCP/NCU/OC fall through to Nextcloud's own loader. Re-prepend
// of the composer loader itself is NOT an option — the optimized classmap
// contains the full nextcloud/ocp stub tree (1k+ classes), which would shadow
// the real interfaces (e.g. a stub IManager declaring registerNotifier fatals
// the real OC\Notification\Manager).
if ($appLoader instanceof \Composer\Autoload\ClassLoader) {
	spl_autoload_register(static function (string $class) use ($appLoader): void {
		if (str_starts_with($class, 'OCP\\') || str_starts_with($class, 'NCU\\')
			|| str_starts_with($class, 'OC\\') || str_starts_with($class, 'OCA\\')) {
			return;
		}
		$file = $appLoader->findFile($class);
		if ($file !== false) {
			require $file;
		}
	}, true, true);
}
unset($appLoader);

if (!class_exists(\Test\TestCase::class)) {
	$shim = __DIR__ . '/shim/TestCase.php';
	if (is_file($shim)) {
		require_once $shim;
	}
}
