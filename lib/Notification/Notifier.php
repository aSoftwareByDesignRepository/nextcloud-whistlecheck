<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Notification;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Content-free notification renderer (spec §10). Subjects are semantic keys —
 * the officer sees "a case needs attention", never report content.
 */
class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $url,
	) {
	}

	public function getID(): string
	{
		return 'whistlecheck';
	}

	public function getName(): string
	{
		return 'WhistleCheck';
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== 'whistlecheck') {
			throw new \InvalidArgumentException('not our app');
		}
		$l = $this->l10nFactory->get('whistlecheck', $languageCode);
		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('whistlecheck', 'app-dark.svg')));
		$notification->setLink($this->url->linkToRouteAbsolute('whistlecheck.page.inbox'));

		switch ($notification->getSubject()) {
			case 'wchk.new_report':
				return $notification->setParsedSubject($l->t('A new report has been submitted.'));
			case 'wchk.due_soon':
				return $notification->setParsedSubject($l->t('A case approaches its feedback deadline.'));
			case 'wchk.overdue':
				return $notification->setParsedSubject($l->t('A case is overdue — feedback deadline passed.'));
			case 'wchk.overdue_escalation':
				return $notification->setParsedSubject($l->t('Escalation: a case is overdue.'));
			case 'wchk.retention_due':
				return $notification->setParsedSubject($l->t('A case is past its retention period.'));
			case 'wchk.retention_failed':
				return $notification->setParsedSubject($l->t('The retention sweep failed repeatedly — please check the server logs.'));
			default:
				throw new \InvalidArgumentException('unknown subject');
		}
	}
}
