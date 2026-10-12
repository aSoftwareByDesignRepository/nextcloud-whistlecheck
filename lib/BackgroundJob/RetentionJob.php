<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\BackgroundJob;

use OCA\WhistleCheck\Db\FormTokenMapper;
use OCA\WhistleCheck\Db\IdemKeyMapper;
use OCA\WhistleCheck\Service\RateLimitService;
use OCA\WhistleCheck\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Daily retention sweep: crypto-shred expired cases, purge stale rate buckets,
 * stale form tokens and stale idempotency keys. Serialized by a global lock —
 * two workers must never shred the same case twice.
 */
class RetentionJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly RetentionService $retention,
		private readonly RateLimitService $rate,
		private readonly FormTokenMapper $formTokens,
		private readonly IdemKeyMapper $idem,
		private readonly ILockingProvider $lockProvider,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		try {
			$this->lockProvider->acquireLock('wchk.job.retention', ILockingProvider::LOCK_EXCLUSIVE, 'retention sweep');
		} catch (LockedException) {
			return; // another worker holds the sweep — next run covers it
		}
		try {
			$this->retention->sweep();
			$this->rate->purgeExpired();
			$cutoff = new \DateTime('-30 days', new \DateTimeZone('UTC'));
			$this->formTokens->deleteOlderThan($cutoff);
			$this->idem->deleteOlderThan($cutoff);
		} finally {
			$this->lockProvider->releaseLock('wchk.job.retention', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
