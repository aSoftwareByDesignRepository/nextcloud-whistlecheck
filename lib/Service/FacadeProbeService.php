<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Integration\SiblingFacadeContracts;
use OCP\App\IAppManager;

/**
 * Capability-gated sibling probe. Never throws into a page render or API
 * handler — a missing/disabled/outdated TicketCheck returns a reason code
 * and the UI simply hides the escalation affordance.
 */
class FacadeProbeService
{
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}

	/**
	 * @return array{available: bool, usable: bool, reason: ?string}
	 */
	public function probeEscalationTarget(): array
	{
		if (!$this->appManager->isEnabledForUser(SiblingFacadeContracts::TICKET_CHECK_APP)) {
			return ['available' => false, 'usable' => false, 'reason' => 'disabled'];
		}
		$facade = SiblingFacadeContracts::ESCALATION_WRITE_FACADE;
		$request = SiblingFacadeContracts::ESCALATION_WRITE_REQUEST;
		if (!class_exists($facade) || !class_exists($request)) {
			return ['available' => true, 'usable' => false, 'reason' => 'facade_missing'];
		}
		$version = defined($facade . '::FACADE_VERSION')
			? (int)constant($facade . '::FACADE_VERSION')
			: 0;
		if ($version < SiblingFacadeContracts::ESCALATION_MIN_VERSION) {
			return ['available' => true, 'usable' => false, 'reason' => 'too_old'];
		}
		return ['available' => true, 'usable' => true, 'reason' => null];
	}

	public function escalationTargetUsable(): bool
	{
		return $this->probeEscalationTarget()['usable'];
	}
}
