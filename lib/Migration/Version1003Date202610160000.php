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
 * One-way escalation references (FC-WCHK-EXPORT): which officer exported a
 * self-authored summary into which sibling app, and the destination ticket
 * reference. Stores no case content and nothing a sibling could use as a
 * backlink — the anonymity boundary is the DTO shape, not a convention.
 * idem_key is nullable; NULLs never collide under the composite unique
 * index, so keyless rows are always fresh inserts.
 */
class Version1003Date202610160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('wchk_escalations')) {
			$t = $schema->createTable('wchk_escalations');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('case_id', 'bigint', ['notnull' => true]);
			$t->addColumn('officer_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('ticket_app', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('ticket_id', 'bigint', ['notnull' => true]);
			$t->addColumn('ticket_number', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('idem_key', 'string', ['notnull' => false, 'length' => 128]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'wchk_esc_pk');
			$t->addIndex(['case_id'], 'wchk_esc_case_idx');
			$t->addUniqueIndex(['case_id', 'idem_key'], 'wchk_esc_idem_uk');
		}
		return $schema;
	}
}
