<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Officer> */
class OfficerMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'wchk_officers', Officer::class);
	}

	public function findByUid(string $uid): ?Officer
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Locking read — FOR UPDATE reads bypass the REPEATABLE READ snapshot and
	 * take the row lock, so a wrap check-and-insert serializes against a
	 * concurrent rotation's UPDATE of the same officer row.
	 */
	public function findByUidForUpdate(string $uid): ?Officer
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$stmt = $this->db->executeQuery(
			$qb->getSQL() . ' FOR UPDATE',
			$qb->getParameters(),
			$qb->getParameterTypes(),
		);
		$row = $stmt->fetch();
		$stmt->closeCursor();
		return $row === false ? null : $this->mapRowToEntity($row);
	}

	/** @return list<Officer> */
	public function findActive(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Officer::STATUS_ACTIVE)))
			->orderBy('uid', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<Officer> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('uid', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByUid(string $uid): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
