<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Integration;

/**
 * Sibling facade contracts this app CONSUMES (FC-WCHK-EXPORT).
 *
 * WhistleCheck is the deliberate composability exception (COMPOSABILITY.md):
 * the only allowed direction is officer-initiated one-way export, and the
 * request DTO structurally cannot carry case ids, reporter data, or a
 * backlink. Facades are resolved lazily via \OCP\Server::get so a missing
 * or outdated sibling degrades instead of fataling.
 */
final class SiblingFacadeContracts
{
	public const TICKET_CHECK_APP = 'ticketcheck';
	public const ESCALATION_WRITE_FACADE = 'OCA\\Ticketcheck\\Public\\EscalationTicketWriteFacade';
	public const ESCALATION_WRITE_REQUEST = 'OCA\\Ticketcheck\\Public\\EscalationTicketWriteRequest';
	public const ESCALATION_MIN_VERSION = 1;

	private function __construct()
	{
	}
}
