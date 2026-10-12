<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\ConfigEntry;
use OCA\WhistleCheck\Db\ConfigEntryMapper;
use OCA\WhistleCheck\Exception\ConflictException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Service\ConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase
{
	private ConfigEntryMapper&MockObject $mapper;
	private ConfigService $svc;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(ConfigEntryMapper::class);
		$this->svc = new ConfigService($this->mapper);
	}

	private function row(string $key, string $value, int $rev): ConfigEntry
	{
		$r = new ConfigEntry();
		$r->setCkey($key);
		$r->setCvalue($value);
		$r->setRevision($rev);
		return $r;
	}

	public function testGetReturnsDefaultWhenUnset(): void
	{
		$this->mapper->method('findByKey')->willReturn(null);
		$this->assertSame('36', $this->svc->get('retention_months'));
		$this->assertSame('', $this->svc->get('org_name'));
	}

	public function testGetIntFallsBackOnGarbage(): void
	{
		$this->mapper->method('findByKey')->willReturn($this->row('retention_months', 'abc', 1));
		$this->assertSame(36, $this->svc->getInt('retention_months'));
	}

	public function testGetBoolOnlyTrueFor1(): void
	{
		$this->mapper->method('findByKey')->willReturnCallback(fn ($k) => $this->row($k, 'true', 1));
		// 'true' stored raw is not '1' — getBool is strict
		$this->assertFalse($this->svc->getBool('attachments_enabled'));
		$this->mapper = $this->createMock(ConfigEntryMapper::class);
		$this->svc = new ConfigService($this->mapper);
		$this->mapper->method('findByKey')->willReturn($this->row('attachments_enabled', '1', 1));
		$this->assertTrue($this->svc->getBool('attachments_enabled'));
	}

	public function testSetUnknownKeyRejected(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->set('evil_key', 'x', 0, 'admin');
	}

	public function testSetNewKeyRequiresRevision0(): void
	{
		$this->mapper->method('findByKey')->willReturn(null);
		$this->expectException(ConflictException::class);
		$this->svc->set('org_name', 'Acme', 5, 'admin');
	}

	public function testSetExistingKeyRejectsStaleRevision(): void
	{
		$this->mapper->method('findByKey')->willReturn($this->row('org_name', 'Old', 3));
		$this->expectException(ConflictException::class);
		$this->svc->set('org_name', 'New', 1, 'admin'); // stale
	}

	public function testSetExistingKeyWithCurrentRevisionUpdates(): void
	{
		$row = $this->row('org_name', 'Old', 3);
		$this->mapper->method('findByKey')->willReturn($row);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			fn (ConfigEntry $r) => $r->getRevision() === 4 && $r->getCvalue() === 'New' && $r->getUpdatedBy() === 'admin'
		));
		$this->svc->set('org_name', 'New', 3, 'admin');
	}

	public function testSetNewKeyWithRevision0Inserts(): void
	{
		$this->mapper->method('findByKey')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(
			fn (ConfigEntry $r) => $r->getRevision() === 1 && $r->getCkey() === 'org_name'
		));
		$this->svc->set('org_name', 'Acme GmbH', 0, 'admin');
	}

	public function testValidateIntRange(): void
	{
		$this->assertSame('36', $this->svc->validate('retention_months', '36'));
		$this->expectException(ValidationException::class);
		$this->svc->validate('retention_months', '0');
	}

	public function testValidateIntRejectsNonNumeric(): void
	{
		$rejected = 0;
		foreach (['abc', '12.5', ' 5', '5 ', '-3', '2e3'] as $bad) {
			try {
				$this->svc->validate('retention_months', $bad);
				$this->fail("accepted '$bad'");
			} catch (ValidationException) {
				$rejected++;
			}
		}
		$this->assertSame(6, $rejected);
	}

	public function testValidateBoolExplicitParse(): void
	{
		$this->assertSame('1', $this->svc->validate('attachments_enabled', '1'));
		$this->assertSame('1', $this->svc->validate('attachments_enabled', 'true'));
		$this->assertSame('0', $this->svc->validate('attachments_enabled', '0'));
		$this->assertSame('0', $this->svc->validate('attachments_enabled', 'false'));
		$this->expectException(ValidationException::class);
		$this->svc->validate('attachments_enabled', 'yes');
	}

	public function testValidateEscalationUid(): void
	{
		$this->assertSame('officer_1', $this->svc->validate('escalation_uid', 'officer_1'));
		$this->assertSame('', $this->svc->validate('escalation_uid', ''));
		$this->expectException(ValidationException::class);
		$this->svc->validate('escalation_uid', 'bad user!');
	}
}
