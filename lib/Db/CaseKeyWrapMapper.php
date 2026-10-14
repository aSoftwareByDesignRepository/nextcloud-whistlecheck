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

/** @template-extends QBMapper<CaseKeyWrap> */
class CaseKeyWrapMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'wchk_case_keys', CaseKeyWrap::class);
	}

	public function findForCaseAndOfficer(int $caseId, string $uid): ?CaseKeyWrap
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('officer_uid', $qb->createNamedParameter($uid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Locking read — FOR UPDATE bypasses the REPEATABLE READ snapshot and
	 * takes the row lock, so an in-transaction wrap check serializes against
	 * a concurrent revoke()'s DELETE of the same row: either the delete
	 * commits first (row gone → uniform not-found) or this read wins and the
	 * revoke waits for our commit before de-wrapping.
	 */
	public function findForCaseAndOfficerForUpdate(int $caseId, string $uid): ?CaseKeyWrap
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('officer_uid', $qb->createNamedParameter($uid)));
		$stmt = $this->db->executeQuery(
			$qb->getSQL() . ' FOR UPDATE',
			$qb->getParameters(),
			$qb->getParameterTypes(),
		);
		$row = $stmt->fetch();
		$stmt->closeCursor();
		return $row === false ? null : $this->mapRowToEntity($row);
	}

	/**
	 * Batch wrap lookup for inbox surfaces — one query instead of N.
	 *
	 * @param list<int> $caseIds
	 * @return array<int, CaseKeyWrap> caseId => wrap
	 */
	public function findForCasesAndOfficer(array $caseIds, string $uid): array
	{
		if ($caseIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('case_id', $qb->createNamedParameter($caseIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('officer_uid', $qb->createNamedParameter($uid)));
		$out = [];
		foreach ($this->findEntities($qb) as $wrap) {
			$out[$wrap->getCaseId()] = $wrap;
		}
		return $out;
	}

	/** @return list<CaseKeyWrap> */
	public function findForCase(int $caseId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return list<CaseKeyWrap> */
	public function findForOfficer(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('officer_uid', $qb->createNamedParameter($uid)));
		return $this->findEntities($qb);
	}

	public function deleteForCase(int $caseId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function deleteForOfficer(string $uid): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('officer_uid', $qb->createNamedParameter($uid)));
		return $qb->executeStatement();
	}
}
