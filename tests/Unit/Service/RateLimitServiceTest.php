<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\RateBucketMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RateLimitServiceTest extends TestCase
{
	private RateBucketMapper&MockObject $mapper;
	private ILockingProvider&MockObject $locks;
	private IAppConfig&MockObject $appConfig;
	private ITimeFactory&MockObject $time;
	private RateLimitService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = $this->createMock(RateBucketMapper::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_760_000_000);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $key === 'rate_salt' ? 'fixed-salt' : ($key === 'rate_salt_day' ? gmdate('Y-m-d', 1_760_000_000) : $default)
		);
		$this->svc = new RateLimitService($this->mapper, $this->locks, $this->appConfig, $this->time);
	}

	public function testCheckRecordsHitAndReleasesLock(): void
	{
		$acquired = [];
		$released = [];
		$this->locks->method('acquireLock')->willReturnCallback(
			function (string $key) use (&$acquired) { $acquired[] = $key; }
		);
		$this->locks->method('releaseLock')->willReturnCallback(
			function (string $key) use (&$released) { $released[] = $key; }
		);
		$this->mapper->expects($this->once())->method('hit')->willReturn(1);
		$this->svc->check('10.0.0.1', 'submit');
		$this->assertSame($acquired, $released, 'every acquired lock must be released');
		foreach ($acquired as $key) {
			$this->assertLessThanOrEqual(64, strlen($key), 'file_locks.key is varchar(64) — longer paths truncate on INSERT IGNORE and never release');
		}
	}

	public function testCheckThrows429OverLimit(): void
	{
		$this->locks->method('acquireLock');
		$this->locks->method('releaseLock');
		$this->mapper->method('hit')->willReturn(6); // submit limit = 5
		try {
			$this->svc->check('10.0.0.1', 'submit');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame(429, $e->getCode());
			$this->assertSame('pow_required', $e->errorCode);
		}
	}

	public function testCheckPowRequiredAboveBaseLimitNotHardBlocked(): void
	{
		// Spec US-006 AC-6: over-cap requests escalate to PoW — no hard block.
		// Even far above the cap (but under the HARD_MULT ceiling) a request
		// without proof gets pow_required, not rate_limited.
		$this->locks->method('acquireLock');
		$this->locks->method('releaseLock');
		$this->mapper->method('hit')->willReturn(40); // >> 2× submit limit (5)
		try {
			$this->svc->check('10.0.0.1', 'submit');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame(429, $e->getCode());
			$this->assertSame('pow_required', $e->errorCode);
		}
	}

	public function testCheckHardCapBeyondTenfoldLimit(): void
	{
		$this->locks->method('acquireLock');
		$this->locks->method('releaseLock');
		$this->mapper->method('hit')->willReturn(51); // > 10× submit limit (5)
		try {
			$this->svc->check('10.0.0.1', 'submit', powOk: true);
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('rate_limited', $e->errorCode);
		}
	}

	public function testPowUnlocksAboveCapBelowCeiling(): void
	{
		$this->locks->method('acquireLock');
		$this->locks->method('releaseLock');
		$this->mapper->method('hit')->willReturn(49); // over 5, under 10×5
		$this->svc->check('10.0.0.1', 'submit', powOk: true);
		$this->addToAssertionCount(1); // fresh bound proof unlocks the request
	}

	public function testLockContentionDegradesTo429Not500(): void
	{
		$this->locks->method('acquireLock')->willThrowException(new LockedException('wchk.rate.x'));
		$this->mapper->expects($this->never())->method('hit');
		try {
			$this->svc->check('10.0.0.1', 'submit');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame(429, $e->getCode());
		}
	}

	public function testBucketIsStableWithinSlotAndVariesAcrossSlotsAndKinds(): void
	{
		$b1 = $this->svc->bucketFor('10.0.0.1', 'submit');
		$b2 = $this->svc->bucketFor('10.0.0.1', 'submit');
		$this->assertSame($b1, $b2, 'same ip+kind+slot must map to the same bucket');
		$this->assertNotSame($b1, $this->svc->bucketFor('10.0.0.1', 'verify'), 'different kind must isolate buckets');
		$this->assertNotSame($b1, $this->svc->bucketFor('10.0.0.2', 'submit'), 'different ip must isolate buckets');
		$this->assertStringNotContainsString('10.0.0.1', $b1, 'bucket must not embed the raw IP');
	}
}
