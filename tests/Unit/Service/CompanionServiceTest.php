<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Db\Officer;
use OCA\WhistleCheck\Db\OfficerMapper;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\CompanionService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CompanionServiceTest extends TestCase
{
	private IAppConfig&MockObject $appConfig;
	private OfficerKeyService&MockObject $officers;
	private OfficerMapper&MockObject $officerMapper;
	private AuditService&MockObject $audit;
	private ILockingProvider&MockObject $locks;
	private CompanionService $svc;
	private bool $locked = false;

	protected function setUp(): void
	{
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->officers = $this->createMock(OfficerKeyService::class);
		$this->officerMapper = $this->createMock(OfficerMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		// Spy lock: records held-state so we can assert the device map is
		// only ever read/written while the exclusive lock is held.
		$this->locks->method('acquireLock')->willReturnCallback(function () {
			$this->locked = true;
		});
		$this->locks->method('releaseLock')->willReturnCallback(function () {
			$this->locked = false;
		});
		$this->svc = new CompanionService(
			$this->appConfig,
			$this->officers,
			$this->officerMapper,
			$this->audit,
			$this->locks,
		);
	}

	private function officer(string $uid): Officer
	{
		$o = new Officer();
		$o->setUid($uid);
		$o->setStatus(Officer::STATUS_ACTIVE);
		return $o;
	}

	private function storeJson(array $devices): void
	{
		$this->appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key) use ($devices) {
			// FM regression pin: the map may only be READ under the lock —
			// reading it before acquireLock was the lost-update race.
			$this->assertTrue($this->locked, 'devices map read outside the device lock');
			return $key === 'devices_officer1' ? (string)json_encode($devices) : '';
		});
	}

	public function testPairStoresHashedTokenAndAudits(): void
	{
		$this->officers->method('isOfficer')->willReturn(true);
		$this->storeJson([]);
		$written = null;
		$this->appConfig->method('setValueString')->willReturnCallback(function ($a, $k, $v) use (&$written) {
			$this->assertTrue($this->locked, 'devices map written outside the device lock');
			$written = json_decode($v, true);
			return true;
		});
		$this->audit->expects($this->once())->method('record')->with('device_paired', 'officer1');
		$out = $this->svc->pair('officer1', 'my phone');
		$this->assertSame(68, strlen($out['token']));
		$this->assertStringStartsWith('wcd_', $out['token']);
		$id = array_key_first($written);
		$this->assertSame(hash('sha256', $out['token']), $written[$id]['hash']);
		$this->assertSame('my phone', $written[$id]['name']);
	}

	public function testPairAtCapThrowsInsideLockedMutation(): void
	{
		$this->officers->method('isOfficer')->willReturn(true);
		$full = [];
		for ($i = 0; $i < CompanionService::MAX_DEVICES; $i++) {
			$full["d$i"] = ['name' => "d$i", 'hash' => "h$i", 'created' => 'x', 'last_seen' => null];
		}
		$this->storeJson($full);
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Device limit reached');
		$this->svc->pair('officer1', 'sixth');
	}

	public function testPairRejectedForNonOfficer(): void
	{
		$this->officers->method('isOfficer')->willReturn(false);
		$this->expectException(DomainException::class);
		$this->svc->pair('outsider', 'phone');
	}

	public function testRevokeRemovesAndAudits(): void
	{
		$this->storeJson(['dev1' => ['name' => 'n', 'hash' => 'h', 'created' => 'c', 'last_seen' => null]]);
		$written = null;
		$this->appConfig->method('deleteKey')->willReturnCallback(function () use (&$written) {
			$written = 'deleted';
		});
		$this->audit->expects($this->once())->method('record')->with('device_revoked', 'officer1');
		$this->svc->revoke('officer1', 'dev1');
		$this->assertSame('deleted', $written);
	}

	public function testRevokeUnknownDeviceWritesNothingAndSkipsAudit(): void
	{
		$this->storeJson(['dev1' => ['name' => 'n', 'hash' => 'h', 'created' => 'c', 'last_seen' => null]]);
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->appConfig->expects($this->never())->method('deleteKey');
		$this->audit->expects($this->never())->method('record');
		$this->svc->revoke('officer1', 'nope');
	}

	public function testTouchThrottlesSecondWriteWithinMinute(): void
	{
		$store = ['dev1' => ['name' => 'n', 'hash' => 'h', 'created' => 'c', 'last_seen' => null]];
		$this->appConfig->method('getValueString')->willReturnCallback(
			function ($a, $k) use (&$store) {
				return $k === 'devices_officer1' ? (string)json_encode($store) : '';
			}
		);
		$writes = 0;
		$this->appConfig->method('setValueString')->willReturnCallback(function ($a, $k, $v) use (&$writes, &$store) {
			$writes++;
			$store = json_decode($v, true);
			return true;
		});
		$this->svc->touch('officer1', 'dev1');
		$this->svc->touch('officer1', 'dev1');
		$this->assertSame(1, $writes);
	}

	public function testResolveRejectsMalformedAndFindsHashedToken(): void
	{
		$token = 'wcd_' . bin2hex(random_bytes(32));
		$this->officerMapper->method('findActive')->willReturn([$this->officer('officer1')]);
		$this->officers->method('isOfficer')->willReturn(true);
		// resolve() reads via devices() — plain read, no lock required.
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn ($a, $k) => $k === 'devices_officer1'
				? json_encode(['d1' => ['name' => 'n', 'hash' => hash('sha256', $token), 'created' => 'c', 'last_seen' => null]])
				: ''
		);
		$this->assertSame('officer1|d1', $this->svc->resolve($token));
		$this->assertNull($this->svc->resolve('wcd_short'));
		$this->assertNull($this->svc->resolve('wcd_' . bin2hex(random_bytes(32))));
	}

	public function testResolveSkipsGroupEjectedOfficers(): void
	{
		// An active row is not enough — admin group ejection must also deny.
		$token = 'wcd_' . bin2hex(random_bytes(32));
		$this->officerMapper->method('findActive')->willReturn([$this->officer('officer1')]);
		$this->officers->method('isOfficer')->willReturn(false);
		$this->assertNull($this->svc->resolve($token));
	}
}
