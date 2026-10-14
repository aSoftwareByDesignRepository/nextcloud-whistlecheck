<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Service\IconCatalog;
use PHPUnit\Framework\TestCase;

final class IconCatalogTest extends TestCase
{
	public function testRenderKnownIcon(): void
	{
		$html = IconCatalog::render('shield-check');
		self::assertStringContainsString('<svg', $html);
		self::assertStringContainsString('aria-hidden="true"', $html);
		self::assertStringContainsString('focusable="false"', $html);
		self::assertStringContainsString('stroke="currentColor"', $html);
		self::assertStringContainsString('wc-icon', $html);
	}

	public function testRenderEscapesExtraClass(): void
	{
		$html = IconCatalog::render('menu', 'wc-nav-toggle__icon-svg');
		self::assertStringContainsString('wc-nav-toggle__icon-svg', $html);
	}

	public function testRenderUnknownReturnsEmpty(): void
	{
		self::assertSame('', IconCatalog::render('not-a-real-icon-name'));
	}

	public function testNavigationIconsExist(): void
	{
		foreach (['inbox', 'users', 'scroll-text', 'bar-chart', 'phone', 'settings', 'menu', 'help'] as $name) {
			self::assertNotSame('', IconCatalog::render($name), $name);
		}
	}
}
