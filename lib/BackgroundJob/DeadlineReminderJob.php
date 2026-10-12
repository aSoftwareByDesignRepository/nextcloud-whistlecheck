<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\BackgroundJob;

use OCA\WhistleCheck\Service\ReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Daily reminder sweep — one-shot flag columns make reruns idempotent, and a
 * global lock prevents two cron workers from racing the same case list.
 */
class DeadlineReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ReminderService $reminders,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(3600); // hourly — the service dedupes by flag columns
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('wchk.job.reminders', ILockingProvider::LOCK_EXCLUSIVE, 'reminder sweep');
		} catch (LockedException) {
			return; // another worker is sweeping — idempotent, skip
		}
		try {
			$this->reminders->run();
		} finally {
			$this->lockProvider->releaseLock('wchk.job.reminders', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
