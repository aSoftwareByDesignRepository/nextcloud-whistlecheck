<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Design-system chrome contract: every officer template opens the shared
 * page-start shell (sidebar nav, skip links, live regions, breadcrumb,
 * icon-well page header, labelled <main>). Public templates use the wc-skip
 * variant. Admin is an embedded NC settings fragment — no app chrome.
 */
final class TemplateContractTest extends TestCase
{
	private const OFFICER_TEMPLATES = [
		'audit', 'case', 'inbox', 'officers', 'settings', 'stats', 'verbal',
	];

	private const PUBLIC_TEMPLATES = [
		'denied', 'public-code', 'public-follow', 'public-report', 'public-thread',
	];

	private const DIR = __DIR__ . '/../../../templates/';

	public function testOfficerTemplatesUseSharedShell(): void
	{
		foreach (self::OFFICER_TEMPLATES as $name) {
			$src = file_get_contents(self::DIR . $name . '.php');
			$this->assertStringContainsString('common/page-start.php', $src, $name);
			$this->assertStringContainsString('common/page-end.php', $src, $name);
			$this->assertStringContainsString('$_[' . "'wc_page'", $src, $name);
			$this->assertStringNotContainsString('id="app-content"', $src, $name . ' must not open its own shell');
			$this->assertStringNotContainsString('parts/nav.php', $src, $name);
		}
	}

	public function testSharedShellCarriesDesignSystemChrome(): void
	{
		$start = file_get_contents(self::DIR . 'common/page-start.php');
		foreach ([
			'navigation.php', 'wc-skip-link', 'id="wc-main"',
			'wc-live-region', 'wc-alert-region', 'wc-breadcrumb',
			'wc-page-header__icon', 'wc-page-title', 'wc-page-header__actions',
			'wc-nav-toggle', 'wc-scope-strip',
		] as $needle) {
			$this->assertStringContainsString($needle, $start);
		}

		$nav = file_get_contents(self::DIR . 'common/navigation.php');
		foreach ([
			'id="app-navigation"', 'wc-brand', 'wc-nav__list',
			'wc-nav__sublist', 'aria-current', 'IconCatalog::render',
		] as $needle) {
			$this->assertStringContainsString($needle, $nav);
		}
	}

	public function testOfficerTemplatesMarkSidebarCurrent(): void
	{
		foreach (self::OFFICER_TEMPLATES as $name) {
			$src = file_get_contents(self::DIR . $name . '.php');
			$this->assertStringContainsString('$_[' . "'wc_nav_current'", $src, $name);
		}
	}

	public function testPublicTemplatesHaveSkipLink(): void
	{
		foreach (self::PUBLIC_TEMPLATES as $name) {
			$src = file_get_contents(self::DIR . $name . '.php');
			$this->assertStringContainsString('wc-skip', $src, $name);
			$this->assertStringContainsString('id="main-content"', $src, $name);
		}
	}

	public function testAdminIsEmbeddedFragmentWithoutAppChrome(): void
	{
		$src = file_get_contents(self::DIR . 'admin.php');
		$this->assertStringNotContainsString('id="app-content"', $src);
		$this->assertStringNotContainsString('page-start.php', $src);
		$this->assertStringNotContainsString('id="app-navigation"', $src);
	}
}
