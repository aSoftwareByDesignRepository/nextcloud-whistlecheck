<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\CaseKeyWrapMapper;
use OCA\WhistleCheck\Db\WattachmentMapper;
use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Db\WmessageMapper;
use OCA\WhistleCheck\Db\WnoteMapper;
use OCP\IDBConnection;

/**
 * Retention + cryptographic shredding (spec US-009 / MH-06).
 *
 * Deletion order inside ONE transaction per case:
 *   1. wchk_case_keys  — officer wraps gone first: content becomes unrecoverable
 *                        even if the tx later fails and rolls back ciphertext
 *   2. wchk_messages / wchk_attachments / wchk_notes — ciphertext
 *   3. wchk_cases row (keeps lookup_hint for hash continuity? NO — full delete,
 *      the audit chain is the proof-of-deletion)
 *   4. audit event 'case_deleted' — inside the same tx, so a case can never be
 *      "deleted but unproven" or "proven but not deleted"
 *
 * Cases under legal hold are skipped (audited), never silently kept — the hold
 * requires a fresh officer action, and held cases are revisited daily.
 */
class RetentionService
{
	public function __construct(
		private readonly WcaseMapper $cases,
		private readonly CaseKeyWrapMapper $wraps,
		private readonly WmessageMapper $messages,
		private readonly WattachmentMapper $attachments,
		private readonly WnoteMapper $notes,
		private readonly DeadlineService $deadlines,
		private readonly AuditService $audit,
		private readonly NotificationService $notify,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Daily sweep. Returns [deleted, held, warned].
	 *
	 * @return array{deleted:int,held:int,warned:int}
	 */
	public function sweep(): array
	{
		$today = $this->deadlines->today();
		$deleted = $held = $warned = 0;

		foreach ($this->cases->findRetentionExpired($today) as $case) {
			if ($case->getLegalHold() === 1) {
				$held++;
				$this->audit->record('retention_skipped_hold', null, $case->getId(), []);
				continue;
			}
			$this->shred($case);
			$deleted++;
		}

		foreach ($this->cases->findRetentionOpenExpired($today) as $case) {
			$case->setRetentionWarned(1);
			if ($this->cases->updateWithRevision($case, $case->getRevision()) === 0) {
				continue; // concurrent write wins — warning stays due, retried next run
			}
			$this->notify->notifyOfficers('wchk.retention_due', $case->getId());
			$this->audit->record('retention_warned', null, $case->getId(), []);
			$warned++;
		}
		return ['deleted' => $deleted, 'held' => $held, 'warned' => $warned];
	}

	/**
	 * Crypto-shred one case — per-case transaction so a crash mid-sweep leaves
	 * at most one half-shredded case, which the NEXT sweep finishes (rows are
	 * still 'expired' until the case row goes).
	 */
	public function shred(Wcase $case): void
	{
		$caseId = $case->getId();
		$this->db->beginTransaction();
		try {
			$this->wraps->deleteForCase($caseId);          // 1. keys first
			$this->attachments->deleteForCase($caseId);    // 2. blobs
			$this->messages->deleteForCase($caseId);       // 3. ciphertext
			$this->notes->deleteForCase($caseId);
			$this->cases->deleteById($caseId);             // 4. case row
			$this->audit->record('case_deleted', null, $caseId, []); // 5. proof
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
