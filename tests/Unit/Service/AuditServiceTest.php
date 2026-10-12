<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\AuditEvent;
use OCA\WhistleCheck\Db\AuditEventMapper;
use OCA\WhistleCheck\Service\AuditService;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AuditServiceTest extends TestCase
{
	private AuditEventMapper&MockObject $mapper;
	private AuditService $svc;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(AuditEventMapper::class);
		$provider = $this->createMock(ILockingProvider::class);
		$this->svc = new AuditService($this->mapper, $provider);
	}

	private function event(int $seq, string $hash, string $prev): AuditEvent
	{
		$e = new AuditEvent();
		$e->setSeq($seq);
		$e->setHash($hash);
		$e->setPrevHash($prev);
		$e->setEventType('case.status');
		$e->setActorUid('officer1');
		$e->setCaseId(7);
		$e->setPayloadJson('{"from":"received"}');
		$e->setCreatedAt(new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
		return $e;
	}

	public function testRecordDropsNonWhitelistedPayloadKeys(): void
	{
		$this->mapper->method('findLast')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(function (AuditEvent $e) {
			$payload = json_decode((string)$e->getPayloadJson(), true);
			$this->assertSame(['status' => 'received'], $payload);
			$this->assertArrayNotHasKey('report_text', $payload);
			$this->assertArrayNotHasKey('body', $payload);
			return true;
		}))->willReturnArgument(0);
		$this->svc->record('case.created', null, 1, [
			'status' => 'received',
			'report_text' => 'SECRET PLAINTEXT — must never persist',
			'body' => 'also content',
		]);
	}

	public function testRecordDropsNonScalarValues(): void
	{
		$this->mapper->method('findLast')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(function (AuditEvent $e) {
			$this->assertNull($e->getPayloadJson()); // only non-scalar passed → payload stays null
			return true;
		}))->willReturnArgument(0);
		$this->svc->record('x', null, null, ['status' => ['nested' => 'array']]);
	}

	public function testFirstEventChainsToGenesis(): void
	{
		$this->mapper->method('findLast')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(function (AuditEvent $e) {
			$this->assertSame(1, $e->getSeq());
			$this->assertSame('wchk-genesis', $e->getPrevHash());
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $e->getHash());
			return true;
		}))->willReturnArgument(0);
		$this->svc->record('case.created', null, 1, []);
	}

	public function testSubsequentEventChainsPrevHash(): void
	{
		$prev = $this->event(1, str_repeat('aa', 32), 'wchk-genesis');
		$this->mapper->method('findLast')->willReturn($prev);
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(function (AuditEvent $e) use ($prev) {
			$this->assertSame(2, $e->getSeq());
			$this->assertSame($prev->getHash(), $e->getPrevHash());
			return true;
		}))->willReturnArgument(0);
		$this->svc->record('case.status', 'officer1', 1, ['from' => 'received', 'to' => 'in_review']);
	}

	public function testVerifyChainOk(): void
	{
		// Build a real chain through the service's own hash function
		$e1 = $this->event(1, '', 'wchk-genesis');
		$e1->setHash($this->svc->chainHash('wchk-genesis', 1, 'case.status', 'officer1', 7, '{"from":"received"}', '2026-01-01 00:00:00'));
		$e2 = $this->event(2, '', $e1->getHash());
		$e2->setHash($this->svc->chainHash($e1->getHash(), 2, 'case.status', 'officer1', 7, '{"from":"received"}', '2026-01-01 00:00:00'));
		$this->mapper->method('findAllOrdered')->willReturn([$e1, $e2]);
		$this->assertSame(-1, $this->svc->verifyChain());
	}

	public function testVerifyChainDetectsTamperedHash(): void
	{
		$e1 = $this->event(1, str_repeat('bb', 32), 'wchk-genesis'); // wrong hash
		$this->mapper->method('findAllOrdered')->willReturn([$e1]);
		$this->assertSame(1, $this->svc->verifyChain());
	}

	public function testVerifyChainDetectsBrokenPrevHash(): void
	{
		$e1 = $this->event(1, '', 'wchk-genesis');
		$e1->setHash($this->svc->chainHash('wchk-genesis', 1, 'case.status', 'officer1', 7, '{"from":"received"}', '2026-01-01 00:00:00'));
		$e2 = $this->event(2, '', 'wrong-prev-hash');
		$this->mapper->method('findAllOrdered')->willReturn([$e1, $e2]);
		$this->assertSame(2, $this->svc->verifyChain());
	}

	public function testVerifyChainDetectsSequenceGap(): void
	{
		$e1 = $this->event(1, '', 'wchk-genesis');
		$e1->setHash($this->svc->chainHash('wchk-genesis', 1, 'case.status', 'officer1', 7, '{"from":"received"}', '2026-01-01 00:00:00'));
		$e3 = $this->event(3, '', $e1->getHash());
		$this->mapper->method('findAllOrdered')->willReturn([$e1, $e3]);
		$this->assertSame(3, $this->svc->verifyChain());
	}

	public function testChainHashIsDeterministicAndInputSensitive(): void
	{
		$a = $this->svc->chainHash('p', 1, 't', 'u', 1, null, 'ts');
		$this->assertSame($a, $this->svc->chainHash('p', 1, 't', 'u', 1, null, 'ts'));
		$this->assertNotSame($a, $this->svc->chainHash('p', 2, 't', 'u', 1, null, 'ts'));
		$this->assertNotSame($a, $this->svc->chainHash('p', 1, 't', 'u', 2, null, 'ts'));
		$this->assertNotSame($a, $this->svc->chainHash('p', 1, 't', 'u', 1, '{}', 'ts'));
	}
}
