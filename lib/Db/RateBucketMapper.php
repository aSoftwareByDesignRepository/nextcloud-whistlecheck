<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<RateBucket> */
class RateBucketMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'wchk_rate', RateBucket::class);
	}

	/**
	 * Atomic read-modify-write under the caller's lock (RateLimitService
	 * serializes hits per bucket to close the TOCTOU gap).
	 */
	public function hit(string $bucketHash, string $kind, \DateTime $expiresAt): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'hits')->from($this->getTableName())
			->where($qb->expr()->eq('bucket_hash', $qb->createNamedParameter($bucketHash)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			$ins = $this->db->getQueryBuilder();
			$ins->insert($this->getTableName())
				->setValue('bucket_hash', $ins->createNamedParameter($bucketHash))
				->setValue('kind', $ins->createNamedParameter($kind))
				->setValue('hits', $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->setValue('expires_at', $ins->createNamedParameter($expiresAt->format('Y-m-d H:i:s')));
			$ins->executeStatement();
			return 1;
		}
		$upd = $this->db->getQueryBuilder();
		$upd->update($this->getTableName())
			->set('hits', $upd->createNamedParameter((int)$row['hits'] + 1, IQueryBuilder::PARAM_INT))
			->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
		$upd->executeStatement();
		return (int)$row['hits'] + 1;
	}

	public function countFor(string $bucketHash, string $kind): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('hits')->from($this->getTableName())
			->where($qb->expr()->eq('bucket_hash', $qb->createNamedParameter($bucketHash)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		$val = $qb->executeQuery()->fetchOne();
		return $val === false ? 0 : (int)$val;
	}

	/**
	 * Sweep expired buckets. Salt rotation destroys the linking secret, so
	 * leftover rows are orphaned and simply get purged here.
	 */
	public function purgeExpired(\DateTimeInterface $now): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now->format('Y-m-d H:i:s'))));
		return $qb->executeStatement();
	}
}
