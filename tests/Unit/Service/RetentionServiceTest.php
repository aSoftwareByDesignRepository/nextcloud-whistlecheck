<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\CaseKeyWrapMapper;
use OCA\WhistleCheck\Db\WattachmentMapper;
use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Db\WmessageMapper;
use OCA\WhistleCheck\Db\WnoteMapper;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\DeadlineService;
use OCA\WhistleCheck\Service\NotificationService;
use OCA\WhistleCheck\Service\RetentionService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RetentionServiceTest extends TestCase
{
	private WcaseMapper&MockObject $cases;
	private CaseKeyWrapMapper&MockObject $wraps;
	private WmessageMapper&MockObject $messages;
	private WattachmentMapper&MockObject $attachments;
	private WnoteMapper&MockObject $notes;
	private AuditService&MockObject $audit;
	private IDBConnection&MockObject $db;
	private \OCA\WhistleCheck\Db\IdemKeyMapper|\PHPUnit\Framework\MockObject\MockObject $idem;
	private RetentionService $svc;

	protected function setUp(): void
	{
		$this->cases = $this->createMock(WcaseMapper::class);
		$this->wraps = $this->createMock(CaseKeyWrapMapper::class);
		$this->messages = $this->createMock(WmessageMapper::class);
		$this->attachments = $this->createMock(WattachmentMapper::class);
		$this->notes = $this->createMock(WnoteMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$deadlines = $this->createMock(DeadlineService::class);
		$deadlines->method('today')->willReturn(new \DateTime('2026-06-15'));
		$this->idem = $this->createMock(\OCA\WhistleCheck\Db\IdemKeyMapper::class);
		$this->svc = new RetentionService(
			$this->cases, $this->idem, $this->wraps, $this->messages, $this->attachments,
			$this->notes, $deadlines, $this->audit,
			$this->createMock(NotificationService::class), $this->db,
		);
	}

	private function expiredCase(int $id, int $hold = 0): Wcase
	{
		$c = new Wcase();
		$c->setId($id);
		$c->setLegalHold($hold);
		return $c;
	}

	public function testShredDeletesKeysBeforeContentBeforeRow(): void
	{
		$order = [];
		$this->wraps->method('deleteForCase')->willReturnCallback(function () use (&$order) { $order[] = 'wraps'; return 1; });
		$this->attachments->method('deleteForCase')->willReturnCallback(function () use (&$order) { $order[] = 'attachments'; return 0; });
		$this->messages->method('deleteForCase')->willReturnCallback(function () use (&$order) { $order[] = 'messages'; return 0; });
		$this->notes->method('deleteForCase')->willReturnCallback(function () use (&$order) { $order[] = 'notes'; return 0; });
		$this->idem->method('deleteForCase')->willReturnCallback(function () use (&$order) { $order[] = 'idem'; return 1; });
		$this->cases->method('deleteById')->willReturnCallback(function () use (&$order) { $order[] = 'case'; return; });
		$this->audit->method('record')->willReturnCallback(function () use (&$order) { $order[] = 'audit'; return new \OCA\WhistleCheck\Db\AuditEvent(); });

		$this->svc->shred($this->expiredCase(42));

		$this->assertSame(['wraps', 'attachments', 'messages', 'notes', 'idem', 'case', 'audit'], $order);
	}

	public function testShredRunsInsideTransaction(): void
	{
		$inTx = [];
		$this->db->method('beginTransaction')->willReturnCallback(function () use (&$inTx) { $inTx[] = true; });
		$this->wraps->method('deleteForCase')->willReturnCallback(function () use (&$inTx) {
			$this->assertNotEmpty($inTx, 'wraps deleted outside transaction');
			return 0;
		});
		$this->db->expects($this->once())->method('commit');
		$this->svc->shred($this->expiredCase(1));
	}

	public function testShredRollsBackOnFailure(): void
	{
		$this->messages->method('deleteForCase')->willThrowException(new \RuntimeException('db gone'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->expectException(\RuntimeException::class);
		$this->svc->shred($this->expiredCase(1));
	}

	public function testSweepSkipsLegalHoldAndAudits(): void
	{
		$this->cases->method('findRetentionExpired')->willReturn([$this->expiredCase(1, 1)]);
		$this->cases->method('findRetentionOpenExpired')->willReturn([]);
		$this->wraps->expects($this->never())->method('deleteForCase');
		$this->audit->expects($this->once())->method('record')
			->with('retention_skipped_hold', null, 1);
		$out = $this->svc->sweep();
		$this->assertSame(1, $out['held']);
		$this->assertSame(0, $out['deleted']);
	}

	public function testSweepDeletesExpired(): void
	{
		$this->audit->method('record')->willReturn(new \OCA\WhistleCheck\Db\AuditEvent());
		$this->cases->method('findRetentionExpired')->willReturn([$this->expiredCase(7), $this->expiredCase(8)]);
		$this->cases->method('findRetentionOpenExpired')->willReturn([]);
		$deleted = [];
		$this->cases->method('deleteById')->willReturnCallback(function (int $id) use (&$deleted) { $deleted[] = $id; return; });
		$out = $this->svc->sweep();
		$this->assertSame([7, 8], $deleted);
		$this->assertSame(2, $out['deleted']);
	}
}
