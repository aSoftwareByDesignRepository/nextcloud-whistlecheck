<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
	public function __construct(
		private readonly IL10N $l,
		private readonly IURLGenerator $url,
	) {
	}

	public function getIcon(): string
	{
		return $this->url->imagePath('whistlecheck', 'app-dark.svg');
	}

	public function getID(): string
	{
		return 'whistlecheck';
	}

	public function getName(): string
	{
		return $this->l->t('WhistleCheck');
	}

	public function getPriority(): int
	{
		return 50;
	}
}
