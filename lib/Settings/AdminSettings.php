<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Settings;

use OCA\WhistleCheck\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings
{
	public function __construct(
		private readonly ConfigService $config,
	) {
	}

	public function getForm(): TemplateResponse
	{
		Util::addScript('whistlecheck', 'whistlecheck-admin');
		Util::addStyle('whistlecheck', 'app');
		return new TemplateResponse('whistlecheck', 'admin', [
			'config' => $this->config->getAll(),
			'revisions' => $this->config->revisions(),
		]);
	}

	public function getSection(): string
	{
		return 'whistlecheck';
	}

	public function getPriority(): int
	{
		return 50;
	}
}
