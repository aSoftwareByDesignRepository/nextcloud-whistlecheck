<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Service\VerbalChannelService;
use PHPUnit\Framework\TestCase;

class VerbalChannelServiceTest extends TestCase
{
	public function testOfferTextCoversBothReporterLanguages(): void
	{
		$t = (new VerbalChannelService())->offerText();
		$this->assertSame(['de', 'en'], array_keys($t));
		foreach ($t as $lang => $text) {
			$this->assertNotSame('', trim($text), "$lang offer text must not be empty");
			$this->assertLessThan(1000, strlen($text), "$lang text must stay paste-friendly");
		}
		$this->assertNotSame($t['de'], $t['en'], 'translations must actually differ');
	}

	public function testOfferTextCarriesTheAnonymityPromise(): void
	{
		$t = (new VerbalChannelService())->offerText();
		// The core reassurance must survive rewording: identity stays
		// protected and no name is required.
		$this->assertStringContainsStringIgnoringCase('identit', $t['de'] . ' ' . $t['en']);
		$this->assertStringContainsStringIgnoringCase('name', $t['de'] . ' ' . $t['en']);
	}
}
