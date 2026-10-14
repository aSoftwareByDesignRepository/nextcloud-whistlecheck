<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\BackgroundJob;

use OCA\WhistleCheck\Db\FormTokenMapper;
use OCA\WhistleCheck\Db\IdemKeyMapper;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\NotificationService;
use OCA\WhistleCheck\Service\RateLimitService;
use OCA\WhistleCheck\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Daily retention sweep: crypto-shred expired cases, purge stale rate buckets,
 * stale form tokens and stale idempotency keys. Serialized by a global lock —
 * two workers must never shred the same case twice.
 *
 * Observability (NFR): a failed run increments retention_fail_count; at the
 * second consecutive failure every active officer gets a content-free
 * notification plus a tamper-evident audit event. A clean run resets the
 * counter. Alert + audit are best-effort — if the failure was the DB itself,
 * those writes fail too and must not mask the original exception.
 */
class RetentionJob extends TimedJob
{
	private const FAIL_KEY = 'retention_fail_count';
	private const ALERT_THRESHOLD = 2;

	public function __construct(
		ITimeFactory $time,
		private readonly RetentionService $retention,
		private readonly RateLimitService $rate,
		private readonly FormTokenMapper $formTokens,
		private readonly IdemKeyMapper $idem,
		private readonly ILockingProvider $lockProvider,
		private readonly IAppConfig $appConfig,
		private readonly NotificationService $notify,
		private readonly AuditService $audit,
		private readonly LoggerInterface $logger,
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
			if ($this->appConfig->getValueInt('whistlecheck', self::FAIL_KEY, 0) > 0) {
				$this->appConfig->setValueInt('whistlecheck', self::FAIL_KEY, 0);
			}
		} catch (\Throwable $e) {
			$this->recordFailure($e);
			throw $e;
		} finally {
			$this->lockProvider->releaseLock('wchk.job.retention', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function recordFailure(\Throwable $e): void
	{
		// NN-10: class name only — exception messages can interpolate SQL/params.
		$this->logger->error('WhistleCheck retention sweep failed (' . $e::class . ')', ['app' => 'whistlecheck']);
		try {
			$fails = $this->appConfig->getValueInt('whistlecheck', self::FAIL_KEY, 0) + 1;
			$this->appConfig->setValueInt('whistlecheck', self::FAIL_KEY, $fails);
		} catch (\Throwable) {
			return; // counter store broken — nothing else will work either
		}
		try {
			$this->audit->record('retention_failed', null, null, ['count' => $fails]);
		} catch (\Throwable) {
			// best-effort — audit chain lives in the same DB that may be down
		}
		if ($fails >= self::ALERT_THRESHOLD) {
			try {
				$this->notify->notifyRetentionFailure();
			} catch (\Throwable) {
				// notification transport down must not mask the original failure
			}
		}
	}
}
