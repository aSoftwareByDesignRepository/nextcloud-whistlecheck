<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Exception;

class DomainException extends \RuntimeException
{
	public function __construct(
		public readonly string $errorCode,
		string $message = '',
		public readonly int $httpStatus = 400,
		/** Remaining seconds for Retry-After (rate limits / pacing). */
		public readonly ?int $retryAfterSeconds = null,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $httpStatus);
	}
}
