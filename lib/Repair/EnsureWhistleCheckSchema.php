<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Repair;

use OCA\WhistleCheck\Db\WchkTableCatalog;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Post-migration invariant: every catalog table must exist. If one is missing
 * the step reports it loudly — it does NOT try to recreate schema (migrations
 * own schema; repair only verifies + reports).
 */
class EnsureWhistleCheckSchema implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function getName(): string
	{
		return 'Ensure WhistleCheck schema is complete';
	}

	public function run(IOutput $output): void
	{
		$missing = [];
		foreach (WchkTableCatalog::requiredTables() as $table) {
			if (!$this->db->tableExists($table)) {
				$missing[] = $table;
			}
		}
		if ($missing !== []) {
			$output->warning('WhistleCheck: missing tables: ' . implode(', ', $missing));
			return;
		}
		$output->info('WhistleCheck: schema complete (' . count(WchkTableCatalog::requiredTables()) . ' tables)');
	}
}
