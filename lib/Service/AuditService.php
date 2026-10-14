<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\AuditEvent;
use OCA\WhistleCheck\Db\AuditEventMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Hash-chained, append-only audit trail (spec US-005 / NN-04).
 *
 * Every entry:
 *   hash = sha256(prev_hash || seq || event || actor || case_id || payload || created_at)
 *
 * Chain extension is serialized two ways: a global advisory lock covers the
 * compute-and-insert window, and the tail row is read FOR UPDATE so the
 * row lock outlives the advisory lock until the caller's transaction commits.
 * Without the row lock a second appender could compute the same seq against
 * the not-yet-committed tail and die on the unique index (spec §6 invariants).
 *
 * PAYLOAD CONTRACT: whitelisted scalar keys only. A caller passing anything
 * else gets it dropped, never stored — the audit can structurally not carry
 * content (NN-04).
 */
class AuditService
{
	private const GENESIS = 'wchk-genesis';

	/** Whitelisted payload fields — everything else is silently dropped. */
	private const ALLOWED_PAYLOAD = [
		'status', 'from', 'to', 'uid', 'target_uid', 'granted_by',
		'count', 'limit', 'kind', 'days', 'months', 'flag', 'reason_class',
		'key', 'version', 'direction', 'message_id', 'attachment_id',
		'attempts', 'outcome', 'mode',
	];

	public function __construct(
		private readonly AuditEventMapper $mapper,
		private readonly ILockingProvider $lockProvider,
	) {
	}

	/**
	 * Append an event. Content-free by construction.
	 *
	 * @param array<string,string|int|bool|null> $payload whitelisted scalars
	 */
	public function record(
		string $eventType,
		?string $actorUid,
		?int $caseId,
		array $payload = [],
	): AuditEvent {
		$clean = [];
		foreach ($payload as $k => $v) {
			if (!in_array($k, self::ALLOWED_PAYLOAD, true)) {
				continue; // structurally content-free — drop, never store
			}
			if (!is_scalar($v) && $v !== null) {
				continue;
			}
			$clean[$k] = $v;
		}

		try {
			$this->lockProvider->acquireLock('wchk.audit.chain', ILockingProvider::LOCK_EXCLUSIVE, 'audit chain append');
		} catch (LockedException) {
			throw new DomainException('busy', 'Busy — retry', 503);
		}
		try {
			$prev = $this->mapper->findLastForUpdate();
			$seq = $prev === null ? 1 : $prev->getSeq() + 1;
			$prevHash = $prev === null ? self::GENESIS : $prev->getHash();

			$created = new \DateTime('now', new \DateTimeZone('UTC'));
			$createdStr = $created->format('Y-m-d H:i:s');
			$payloadJson = $clean === [] ? null : (string)json_encode($clean, JSON_THROW_ON_ERROR);

			$hash = $this->chainHash($prevHash, $seq, $eventType, $actorUid, $caseId, $payloadJson, $createdStr);

			$event = new AuditEvent();
			$event->setSeq($seq);
			$event->setPrevHash($prevHash);
			$event->setHash($hash);
			$event->setEventType($eventType);
			$event->setActorUid($actorUid);
			$event->setCaseId($caseId);
			$event->setPayloadJson($payloadJson);
			$event->setCreatedAt($created);
			return $this->mapper->insert($event);
		} finally {
			$this->lockProvider->releaseLock('wchk.audit.chain', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Re-walk the whole chain. Returns the first broken index or -1 when intact.
	 * Used by the audit viewer's "verify" action and by sealed export.
	 */
	public function verifyChain(): int
	{
		$prevHash = self::GENESIS;
		$expectedSeq = 1;
		foreach ($this->mapper->findAllOrdered() as $row) {
			if ($row->getSeq() !== $expectedSeq) {
				return $row->getSeq();
			}
			if ($row->getPrevHash() !== $prevHash) {
				return $row->getSeq();
			}
			$recomputed = $this->chainHash(
				$row->getPrevHash(), $row->getSeq(), $row->getEventType(),
				$row->getActorUid(), $row->getCaseId(), $row->getPayloadJson(),
				$row->getCreatedAt()->format('Y-m-d H:i:s')
			);
			if ($recomputed !== $row->getHash()) {
				return $row->getSeq();
			}
			$prevHash = $row->getHash();
			$expectedSeq++;
		}
		return -1;
	}

	public function chainHash(
		string $prevHash,
		int $seq,
		string $eventType,
		?string $actorUid,
		?int $caseId,
		?string $payloadJson,
		string $createdStr
	): string {
		return hash('sha256', implode('|', [
			$prevHash,
			(string)$seq,
			$eventType,
			$actorUid ?? '-',
			$caseId === null ? '-' : (string)$caseId,
			$payloadJson ?? '-',
			$createdStr,
		]));
	}
}
