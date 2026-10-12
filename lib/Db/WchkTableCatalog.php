<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

final class WchkTableCatalog
{
	/**
	 * Every table this app has ever created (uninstall DROP IF EXISTS target list).
	 * Kept in sync by the DB-standards linter (sync-uninstall).
	 *
	 * @var list<string>
	 */
	public const TABLES = [
		'wchk_attachments',
		'wchk_audit',
		'wchk_case_keys',
		'wchk_cases',
		'wchk_categories',
		'wchk_config',
		'wchk_form_tokens',
		'wchk_idem',
		'wchk_messages',
		'wchk_notes',
		'wchk_officers',
		'wchk_rate',
	];

	/**
	 * Tables intentionally dropped by later migrations (excluded from presence check).
	 *
	 * @var list<string>
	 */
	public const DROPPED_LEGACY_TABLES = [
	];

	/**
	 * @return list<string>
	 */
	public static function requiredTables(): array
	{
		$dropped = array_fill_keys(self::DROPPED_LEGACY_TABLES, true);
		$out = [];
		foreach (self::TABLES as $table) {
			if (!isset($dropped[$table])) {
				$out[] = $table;
			}
		}
		return $out;
	}
}
