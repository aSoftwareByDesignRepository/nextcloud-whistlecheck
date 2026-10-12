<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Exception;

/**
 * Validation failure carrying a field-level `fields` map (control name →
 * localized message). Required for WCAG 3.3.1/3.3.3 field-level rendering —
 * a banner alone is a reject (api-security-patterns §5).
 */
class ValidationException extends \RuntimeException
{
	/** @param array<string,string> $fields */
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
		private readonly array $fields = [],
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	/** @return array<string,string> */
	public function getFields(): array
	{
		return $this->fields;
	}
}
