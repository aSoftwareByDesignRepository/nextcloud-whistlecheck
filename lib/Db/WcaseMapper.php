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

/** @template-extends QBMapper<Wcase> */
class WcaseMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'wchk_cases', Wcase::class);
	}

	public function find(int $id): ?Wcase
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByHint(string $lookupHint): ?Wcase
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('lookup_hint', $qb->createNamedParameter($lookupHint)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Inbox listing, newest first. Overdue ordering happens in the service layer
	 * (OVERDUE is computed, not stored).
	 *
	 * @return list<Wcase>
	 */
	public function listInbox(int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName());
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * @return list<Wcase> open cases whose feedback deadline falls inside the window
	 */
	public function findDueSoon(\DateTimeInterface $today, int $days): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNull('feedback_sent_at'))
			->andWhere($qb->expr()->isNull('closed_at'))
			->andWhere($qb->expr()->isNull('reminder_sent_at'))
			->andWhere($qb->expr()->lte('feedback_due', $qb->createNamedParameter(
				$today->modify('+' . $days . ' days')->format('Y-m-d')
			)))
			->andWhere($qb->expr()->gte('feedback_due', $qb->createNamedParameter($today->format('Y-m-d'))));
		return $this->findEntities($qb);
	}

	/**
	 * @return list<Wcase> open cases past their feedback deadline, not yet escalated
	 */
	public function findOverdue(\DateTimeInterface $today): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNull('feedback_sent_at'))
			->andWhere($qb->expr()->isNull('closed_at'))
			->andWhere($qb->expr()->isNull('overdue_notified_at'))
			->andWhere($qb->expr()->lt('feedback_due', $qb->createNamedParameter($today->format('Y-m-d'))));
		return $this->findEntities($qb);
	}

	/**
	 * Retention candidates: terminal cases whose retention period has elapsed.
	 *
	 * @return list<Wcase>
	 */
	public function findRetentionExpired(\DateTimeInterface $today): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNotNull('retention_until'))
			->andWhere($qb->expr()->lte('retention_until', $qb->createNamedParameter($today->format('Y-m-d'))))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[Wcase::STATUS_CLOSED, Wcase::STATUS_ARCHIVED],
				IQueryBuilder::PARAM_STR_ARRAY
			)));
		return $this->findEntities($qb);
	}

	/**
	 * Cases past retention age that are still open — need a manual closure decision.
	 *
	 * @return list<Wcase>
	 */
	public function findRetentionOpenExpired(\DateTimeInterface $today): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNotNull('retention_until'))
			->andWhere($qb->expr()->lte('retention_until', $qb->createNamedParameter($today->format('Y-m-d'))))
			->andWhere($qb->expr()->eq('retention_warned', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(
				[Wcase::STATUS_CLOSED, Wcase::STATUS_ARCHIVED],
				IQueryBuilder::PARAM_STR_ARRAY
			)));
		return $this->findEntities($qb);
	}

	/**
	 * Optimistic lock update (spec §9 CAS): fails unless the stored revision equals
	 * $expectedRevision. Returns the number of changed rows — 0 means stale.
	 */
	public function updateWithRevision(Wcase $case, int $expectedRevision): int
	{
		$case->setRevision($expectedRevision + 1);
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($case->getStatus()))
			->set('legal_hold', $qb->createNamedParameter($case->getLegalHold(), IQueryBuilder::PARAM_INT))
			->set('hold_reason', $qb->createNamedParameter($case->getHoldReason()))
			->set('hold_set_by', $qb->createNamedParameter($case->getHoldSetBy()))
			->set('assigned_to', $qb->createNamedParameter($case->getAssignedTo()))
			->set('feedback_sent_at', $qb->createNamedParameter($case->getFeedbackSentAt()?->format('Y-m-d H:i:s')))
			->set('reminder_sent_at', $qb->createNamedParameter($case->getReminderSentAt()?->format('Y-m-d H:i:s')))
			->set('overdue_notified_at', $qb->createNamedParameter($case->getOverdueNotifiedAt()?->format('Y-m-d H:i:s')))
			->set('close_requested_by', $qb->createNamedParameter($case->getCloseRequestedBy()))
			->set('close_requested_to', $qb->createNamedParameter($case->getCloseRequestedTo()))
			->set('closed_at', $qb->createNamedParameter($case->getClosedAt()?->format('Y-m-d H:i:s')))
			->set('closed_by', $qb->createNamedParameter($case->getClosedBy()))
			->set('retention_until', $qb->createNamedParameter($case->getRetentionUntil()?->format('Y-m-d')))
			->set('retention_warned', $qb->createNamedParameter($case->getRetentionWarned(), IQueryBuilder::PARAM_INT))
			->set('revision', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($case->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Per-case delete for crypto-shredding. The service deletes wrap keys,
	 * attachments, messages and notes FIRST (same transaction), then the row.
	 */
	public function deleteById(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
