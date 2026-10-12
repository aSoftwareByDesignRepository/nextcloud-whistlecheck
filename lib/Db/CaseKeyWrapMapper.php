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

	/**
	 * Replace/insert a wrap idempotently (re-wrap flow must be retry-safe).
	 */
	public function upsert(CaseKeyWrap $wrap): CaseKeyWrap
	{
		$existing = $this->findForCaseAndOfficer((int)$wrap->getCaseId(), (string)$wrap->getOfficerUid());
		if ($existing !== null) {
			$existing->setWrappedKey($wrap->getWrappedKey());
			$existing->setGrantedBy($wrap->getGrantedBy());
			$existing->setGrantedAt($wrap->getGrantedAt());
			return $this->update($existing);
		}
		return $this->insert($wrap);
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
