<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Design-system chrome contract: every officer template carries a skip link
 * and a labelled main landmark (checklist: shared chrome). Public templates
 * use the wc-skip variant.
 */
final class TemplateContractTest extends TestCase
{
	private const OFFICER_TEMPLATES = [
		'admin', 'audit', 'case', 'inbox', 'officers', 'settings', 'stats', 'verbal',
	];

	private const PUBLIC_TEMPLATES = [
		'denied', 'public-code', 'public-follow', 'public-report', 'public-thread',
	];

	private const DIR = __DIR__ . '/../../../templates/';

	public function testOfficerTemplatesHaveSkipLinkAndMainLandmark(): void
	{
		foreach (self::OFFICER_TEMPLATES as $name) {
			$src = file_get_contents(self::DIR . $name . '.php');
			$this->assertStringContainsString('wc-skip-link', $src, $name);
			$this->assertStringContainsString('id="wc-main"', $src, $name);
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

	public function testOfficerTemplatesExposeI18nForJs(): void
	{
		foreach (['audit', 'case', 'inbox', 'officers', 'settings', 'stats', 'verbal'] as $name) {
			$src = file_get_contents(self::DIR . $name . '.php');
			$this->assertStringContainsString('data-wc-i18n', $src, $name);
		}
	}
}
