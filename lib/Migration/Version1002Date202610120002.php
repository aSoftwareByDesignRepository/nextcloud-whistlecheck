<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Migration;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * code_hash previously stored the argon2id verifier verbatim — the exact
 * credential the reporter presents to authenticate. A DB dump therefore
 * contained working reporter-login credentials. The verifier is now stored
 * as sha256(verifier); this rewrites existing rows in place.
 */
class Version1002Date202610120002 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
	{
		// Stored values are 64-hex either way, so rows are re-hashed by
		// migration one-shot semantics, not a length heuristic.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'code_hash')->from('wchk_cases');
		$rows = $qb->executeQuery()->fetchAll();
		foreach ($rows as $row) {
			$upd = $this->db->getQueryBuilder();
			$upd->update('wchk_cases')
				->set('code_hash', $upd->createNamedParameter(hash('sha256', (string)$row['code_hash'])))
				->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
		}
	}
}
