<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\WcaseMapper;

/**
 * Deadline reminders (spec US-003 / MH-04).
 *
 *  - pre-due reminder: once, N days before feedback_due (config reminder_days)
 *  - overdue escalation: once feedback_due passes without feedback_sent, the
 *    escalation officer (config escalation_uid) plus all officers are notified
 *  - both flags are one-shot columns → reruns are idempotent, no spam
 */
class ReminderService
{
	public function __construct(
		private readonly WcaseMapper $cases,
		private readonly DeadlineService $deadlines,
		private readonly ConfigService $config,
		private readonly NotificationService $notify,
		private readonly AuditService $audit,
	) {
	}

	/** @return array{reminded:int,escalated:int} */
	public function run(): array
	{
		$today = $this->deadlines->today();
		$days = $this->config->getInt('reminder_days');
		$reminded = $escalated = 0;

		foreach ($this->cases->findDueSoon($today, $days) as $case) {
			$case->setReminderSentAt(new \DateTime('now', new \DateTimeZone('UTC')));
			if ($this->cases->updateWithRevision($case, $case->getRevision()) === 0) {
				continue; // officer mutated concurrently — next run re-detects
			}
			$this->notify->notifyOfficers('wchk.due_soon', $case->getId());
			$this->audit->record('reminder_sent', null, $case->getId(), ['days' => $days]);
			$reminded++;
		}

		$escalationUid = $this->config->get('escalation_uid');
		foreach ($this->cases->findOverdue($today) as $case) {
			$case->setOverdueNotifiedAt(new \DateTime('now', new \DateTimeZone('UTC')));
			if ($this->cases->updateWithRevision($case, $case->getRevision()) === 0) {
				continue;
			}
			$this->notify->notifyOfficers('wchk.overdue', $case->getId());
			if ($escalationUid !== '') {
				$this->notify->notifyUids([$escalationUid], 'wchk.overdue_escalation', $case->getId());
			}
			$this->audit->record('overdue_notified', null, $case->getId(), []);
			$escalated++;
		}

		return ['reminded' => $reminded, 'escalated' => $escalated];
	}
}
