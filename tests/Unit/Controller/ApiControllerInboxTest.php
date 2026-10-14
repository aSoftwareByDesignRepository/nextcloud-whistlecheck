<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Controller;

use OCA\WhistleCheck\Controller\ApiController;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Db\AuditEventMapper;
use OCA\WhistleCheck\Db\CaseKeyWrap;
use OCA\WhistleCheck\Db\CaseKeyWrapMapper;
use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Db\OfficerMapper;
use OCA\WhistleCheck\Db\WattachmentMapper;
use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Db\WmessageMapper;
use OCA\WhistleCheck\Db\WnoteMapper;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\CaseService;
use OCA\WhistleCheck\Service\ConfigService;
use OCA\WhistleCheck\Service\DeadlineService;
use OCA\WhistleCheck\Service\EscalationService;
use OCA\WhistleCheck\Service\EvidenceExportService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCA\WhistleCheck\Service\StatsService;
use OCA\WhistleCheck\Service\VerbalChannelService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Inbox listing contract: the officer's wrap scope is applied in SQL
 * BEFORE the row limit (a wrap on an old case can never be truncated
 * away), and overdue cases sort first (spec US-003 AC-3).
 */
final class ApiControllerInboxTest extends TestCase
{
	private WcaseMapper&MockObject $cases;
	private CaseKeyWrapMapper&MockObject $wraps;
	private ApiController $ctrl;

	protected function setUp(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$keys = $this->createMock(OfficerKeyService::class);
		$keys->method('isOfficer')->willReturn(true);

		$this->cases = $this->createMock(WcaseMapper::class);
		$this->wraps = $this->createMock(CaseKeyWrapMapper::class);
		$deadlines = $this->createMock(DeadlineService::class);
		$deadlines->method('today')->willReturn(new \DateTime('2026-10-14', new \DateTimeZone('UTC')));

		$this->ctrl = new ApiController(
			'whistlecheck',
			$this->createMock(IRequest::class),
			$session,
			$this->createMock(IUserManager::class),
			$keys,
			$this->createMock(OfficerMapper::class),
			$this->createMock(CaseService::class),
			$this->cases,
			$this->createMock(WmessageMapper::class),
			$this->createMock(WattachmentMapper::class),
			$this->createMock(WnoteMapper::class),
			$this->wraps,
			$this->createMock(CategoryMapper::class),
			$this->createMock(AuditEventMapper::class),
			$this->createMock(AuditService::class),
			$this->createMock(ConfigService::class),
			$this->createMock(StatsService::class),
			$deadlines,
			$this->createMock(EvidenceExportService::class),
			$this->createMock(VerbalChannelService::class),
			new EnvelopeService(),
			$this->createMock(EscalationService::class),
			$this->createMock(\OCP\IDBConnection::class),
		);
	}

	private function caseRow(int $id, string $due, string $status = Wcase::STATUS_IN_REVIEW): Wcase
	{
		$c = new Wcase();
		$c->setId($id);
		$c->setStatus($status);
		$c->setLegalHold(0);
		$c->setReceivedAt(new \DateTime('2026-10-01', new \DateTimeZone('UTC')));
		$c->setFeedbackDue(new \DateTime($due, new \DateTimeZone('UTC')));
		return $c;
	}

	private function wrapFor(int $caseId): CaseKeyWrap
	{
		$w = new CaseKeyWrap();
		$w->setCaseId($caseId);
		$w->setWrappedKey('env');
		return $w;
	}

	public function testInboxIsWrapScopedAtSqlAndOverdueSortsFirst(): void
	{
		// Mapper must be asked for the officer-scoped listing — the limit
		// applies after wrap filtering, not before.
		$fresh = $this->caseRow(2, '2026-11-01');   // newer, not overdue
		$overdue = $this->caseRow(1, '2026-10-01'); // older, overdue
		$this->cases->expects($this->once())->method('listInboxForOfficer')
			->with('officer1', 501)
			->willReturn([$fresh, $overdue]); // DB order: newest first
		$this->wraps->method('findForCasesAndOfficer')
			->willReturn([1 => $this->wrapFor(1), 2 => $this->wrapFor(2)]);

		$data = $this->ctrl->inbox()->getData();
		$this->assertTrue($data['success']);
		$this->assertSame([1, 2], array_column($data['data']['cases'], 'id'));
		$this->assertTrue($data['data']['cases'][0]['overdue']);
		$this->assertFalse($data['data']['cases'][1]['overdue']);
		$this->assertFalse($data['data']['truncated']);
	}

	public function testInboxTruncationFlagReflectsWrapScopedOverflow(): void
	{
		$rows = [];
		$wraps = [];
		for ($i = 1; $i <= 501; $i++) {
			$rows[] = $this->caseRow($i, '2026-11-01');
			$wraps[$i] = $this->wrapFor($i);
		}
		$this->cases->method('listInboxForOfficer')->willReturn($rows);
		$this->wraps->method('findForCasesAndOfficer')->willReturn($wraps);
		$data = $this->ctrl->inbox()->getData();
		$this->assertTrue($data['data']['truncated']);
		$this->assertCount(500, $data['data']['cases']);
	}
}
