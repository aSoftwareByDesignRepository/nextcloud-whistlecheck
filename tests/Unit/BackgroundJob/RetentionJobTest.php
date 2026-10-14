<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\BackgroundJob;

use OCA\WhistleCheck\BackgroundJob\RetentionJob;
use OCA\WhistleCheck\Db\FormTokenMapper;
use OCA\WhistleCheck\Db\IdemKeyMapper;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\NotificationService;
use OCA\WhistleCheck\Service\RateLimitService;
use OCA\WhistleCheck\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetentionJobTest extends TestCase
{
	private RetentionService&MockObject $retention;
	private RateLimitService&MockObject $rate;
	private FormTokenMapper&MockObject $formTokens;
	private IdemKeyMapper&MockObject $idem;
	private ILockingProvider&MockObject $lock;
	private IAppConfig&MockObject $appConfig;
	private NotificationService&MockObject $notify;
	private AuditService&MockObject $audit;
	private LoggerInterface&MockObject $logger;
	private RetentionJob $job;
	/** @var array<string,int> */
	private array $failCount;

	protected function setUp(): void
	{
		parent::setUp();
		$this->retention = $this->createMock(RetentionService::class);
		$this->rate = $this->createMock(RateLimitService::class);
		$this->formTokens = $this->createMock(FormTokenMapper::class);
		$this->idem = $this->createMock(IdemKeyMapper::class);
		$this->lock = $this->createMock(ILockingProvider::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->notify = $this->createMock(NotificationService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->failCount = ['retention_fail_count' => 0];
		$this->appConfig->method('getValueInt')
			->willReturnCallback(fn ($app, $key, $def) => $this->failCount[$key] ?? $def);
		$this->appConfig->method('setValueInt')
			->willReturnCallback(function ($app, $key, $val): bool {
				$this->failCount[$key] = $val;
				return true;
			});

		$this->job = new RetentionJob(
			$this->createMock(ITimeFactory::class),
			$this->retention,
			$this->rate,
			$this->formTokens,
			$this->idem,
			$this->lock,
			$this->appConfig,
			$this->notify,
			$this->audit,
			$this->logger,
		);
	}

	private function runJob(): void
	{
		$m = new \ReflectionMethod($this->job, 'run');
		$m->invoke($this->job, null);
	}

	public function testSuccessfulRunResetsCounterAndDoesNotAlert(): void
	{
		$this->failCount['retention_fail_count'] = 3;
		$this->retention->expects($this->once())->method('sweep');
		$this->rate->expects($this->once())->method('purgeExpired');
		$this->notify->expects($this->never())->method('notifyRetentionFailure');
		$this->runJob();
		$this->assertSame(0, $this->failCount['retention_fail_count']);
	}

	public function testFirstFailureCountsButDoesNotAlert(): void
	{
		$this->retention->method('sweep')->willThrowException(new \RuntimeException('db down'));
		$this->notify->expects($this->never())->method('notifyRetentionFailure');
		$this->audit->expects($this->once())->method('record')
			->with('retention_failed', null, null, ['count' => 1]);
		$this->logger->expects($this->once())->method('error');
		try {
			$this->runJob();
			$this->fail('expected rethrow');
		} catch (\RuntimeException) {
		}
		$this->assertSame(1, $this->failCount['retention_fail_count']);
	}

	public function testSecondConsecutiveFailureAlerts(): void
	{
		$this->retention->method('sweep')->willThrowException(new \RuntimeException('db down'));
		$this->notify->expects($this->once())->method('notifyRetentionFailure');
		$this->failCount['retention_fail_count'] = 1;
		try {
			$this->runJob();
			$this->fail('expected rethrow');
		} catch (\RuntimeException) {
		}
		$this->assertSame(2, $this->failCount['retention_fail_count']);
	}

	public function testAlertAndAuditFailuresDoNotMaskOriginalException(): void
	{
		$this->retention->method('sweep')->willThrowException(new \LogicException('root cause'));
		$this->audit->method('record')->willThrowException(new \RuntimeException('audit broke'));
		$this->notify->method('notifyRetentionFailure')->willThrowException(new \RuntimeException('notify broke'));
		$this->failCount['retention_fail_count'] = 1;
		try {
			$this->runJob();
			$this->fail('expected rethrow');
		} catch (\LogicException $e) {
			$this->assertSame('root cause', $e->getMessage());
		}
	}

	public function testLockContentionSkipsRunWithoutCounting(): void
	{
		$this->lock->method('acquireLock')->willThrowException(new LockedException('held'));
		$this->retention->expects($this->never())->method('sweep');
		$this->runJob();
		$this->assertSame(0, $this->failCount['retention_fail_count']);
	}

	public function testPurgeFailureAlsoCounts(): void
	{
		$this->rate->method('purgeExpired')->willThrowException(new \RuntimeException('bucket purge died'));
		$this->notify->expects($this->once())->method('notifyRetentionFailure');
		$this->failCount['retention_fail_count'] = 1;
		try {
			$this->runJob();
			$this->fail('expected rethrow');
		} catch (\RuntimeException) {
		}
	}
}
