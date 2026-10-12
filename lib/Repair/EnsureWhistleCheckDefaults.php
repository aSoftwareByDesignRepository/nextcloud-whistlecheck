<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Repair;

use OCA\WhistleCheck\Service\OfficerKeyService;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Install-time defaults: make sure the officer group exists so admins can
 * assign the channel to real accounts. Idempotent — groupManager->createGroup
 * is a no-op when the group already exists.
 */
class EnsureWhistleCheckDefaults implements IRepairStep
{
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}

	public function getName(): string
	{
		return 'Ensure WhistleCheck defaults (officer group)';
	}

	public function run(IOutput $output): void
	{
		if (!$this->groupManager->groupExists(OfficerKeyService::GROUP)) {
			$this->groupManager->createGroup(OfficerKeyService::GROUP);
			$output->info('WhistleCheck: created group ' . OfficerKeyService::GROUP);
			return;
		}
		$output->info('WhistleCheck: officer group already exists');
	}
}
