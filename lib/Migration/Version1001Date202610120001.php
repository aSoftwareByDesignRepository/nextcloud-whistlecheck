<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Records WHICH terminal transition a two-person close request targets.
 * Without it, `confirm-close` could only apply `closed` — a pending
 * `archived` request was unconfirmable and a mismatched confirm could
 * silently apply a different action than the one that was requested.
 */
class Version1001Date202610120001 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('wchk_cases')) {
			$t = $schema->getTable('wchk_cases');
			if (!$t->hasColumn('close_requested_to')) {
				$t->addColumn('close_requested_to', 'string', ['notnull' => false, 'length' => 20]);
			}
		}
		return $schema;
	}
}
