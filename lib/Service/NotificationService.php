<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\Officer;
use OCA\WhistleCheck\Db\OfficerMapper;
use OCP\Notification\IManager as INotificationManager;

/**
 * Content-free officer notifications (spec §10 / NN-04).
 *
 * Subjects carry a semantic KEY only ("wchk.new_report" etc.) — never report
 * content, never reporter data, never case codes. The case link uses the
 * internal numeric id, which an officer already needs to open the case.
 */
class NotificationService
{
	public const APP = 'whistlecheck';

	public function __construct(
		private readonly INotificationManager $notifications,
		private readonly OfficerMapper $officers,
	) {
	}

	/**
	 * Notify every active officer about a case event. Subject = key, params =
	 * ["<caseId>"] — the Notifier renders "A case requires attention" text.
	 */
	public function notifyOfficers(string $subject, int $caseId): void
	{
		foreach ($this->officers->findActive() as $officer) {
			$this->notify($officer, $subject, (string)$caseId);
		}
	}

	/** @param list<string> $uids */
	public function notifyUids(array $uids, string $subject, int $caseId): void
	{
		foreach ($this->officers->findActive() as $officer) {
			if (in_array($officer->getUid(), $uids, true)) {
				$this->notify($officer, $subject, (string)$caseId);
			}
		}
	}

	private function notify(Officer $officer, string $subject, string $caseId): void
	{
		$n = $this->notifications->createNotification();
		$n->setApp(self::APP)
			->setUser($officer->getUid())
			->setDateTime(new \DateTime('now', new \DateTimeZone('UTC')))
			->setObject('case', $caseId)
			->setSubject($subject, [$caseId]);
		$this->notifications->notify($n);
	}
}
