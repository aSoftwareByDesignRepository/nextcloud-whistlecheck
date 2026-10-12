<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Exception;

/**
 * CAS failure — stale revision or missing base token. Always HTTP 409,
 * never silently merge (fail-closed optimistic locking, api-security §3).
 */
class ConflictException extends DomainException
{
	public function __construct(string $errorCode = 'conflict', string $message = 'Conflict — reload and retry')
	{
		parent::__construct($errorCode, $message, 409);
	}
}
