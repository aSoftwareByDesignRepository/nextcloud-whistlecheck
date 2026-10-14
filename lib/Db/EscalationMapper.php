<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Escalation> */
class EscalationMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'wchk_escalations', Escalation::class);
	}

	/**
	 * @return list<Escalation> newest first
	 */
	public function findForCase(int $caseId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function findByIdemKey(int $caseId, string $idemKey): ?Escalation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($idemKey)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function countForCase(int $caseId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Retention shred — escalations die with the case, before the case row,
	 * in the same transaction. The audit event keeps the ticket number as
	 * the durable proof of the export.
	 */
	public function deleteForCase(int $caseId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
