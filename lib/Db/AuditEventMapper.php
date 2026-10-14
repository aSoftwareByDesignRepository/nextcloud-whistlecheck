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

/** @template-extends QBMapper<AuditEvent> */
class AuditEventMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'wchk_audit', AuditEvent::class);
	}

	/**
	 * Last chain link — used to extend the hash chain. Caller must hold the
	 * audit lock (AuditService serializes appends).
	 */
	public function findLast(): ?AuditEvent
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('seq', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Locking tail read — SELECT … FOR UPDATE takes the row lock until the
	 * surrounding transaction commits. The advisory lock alone is not enough:
	 * it is released at the end of record(), BEFORE the caller's transaction
	 * commits, so a second appender could compute the same seq against the
	 * uncommitted tail and die on the unique index (spurious failure) or —
	 * without it — silently fork the chain.
	 *
	 * With FOR UPDATE the second writer blocks on the tail row until the
	 * first transaction resolves, then reads the committed head — correct
	 * seq, no abort, no fork. The named lock still guards the empty-table
	 * genesis (no row to lock) and keeps non-transactional callers cheap.
	 */
	public function findLastForUpdate(): ?AuditEvent
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('seq', 'DESC')
			->setMaxResults(1);
		$stmt = $this->db->executeQuery(
			$qb->getSQL() . ' FOR UPDATE',
			$qb->getParameters(),
			$qb->getParameterTypes(),
		);
		$row = $stmt->fetch();
		$stmt->closeCursor();
		return $row === false ? null : $this->mapRowToEntity($row);
	}

	/** @return list<AuditEvent> */
	public function findForCase(int $caseId, int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<AuditEvent> */
	public function findRecent(int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('seq', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<AuditEvent> whole chain, for verification/export */
	public function findAllOrdered(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('seq', 'ASC');
		return $this->findEntities($qb);
	}
}
