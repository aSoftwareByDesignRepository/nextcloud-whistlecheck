<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Controller;

use OCA\WhistleCheck\Controller\ApiController;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Db\AuditEvent;
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
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The audit feed must obey the same wrap boundary as every other case
 * surface — an officer without a case-key wrap must not learn the case
 * exists, neither per-case nor via the global feed (NN-04 oracle).
 */
final class ApiControllerAuditTest extends TestCase
{
	private WcaseMapper&MockObject $cases;
	private CaseKeyWrapMapper&MockObject $wraps;
	private AuditEventMapper&MockObject $auditMapper;
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
		$this->auditMapper = $this->createMock(AuditEventMapper::class);
		$audit = $this->createMock(AuditService::class);
		$audit->method('verifyChain')->willReturn(-1);

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
			$this->auditMapper,
			$audit,
			$this->createMock(ConfigService::class),
			$this->createMock(StatsService::class),
			$this->createMock(DeadlineService::class),
			$this->createMock(EvidenceExportService::class),
			$this->createMock(VerbalChannelService::class),
			new EnvelopeService(),
			$this->createMock(EscalationService::class),
			$this->createMock(\OCP\IDBConnection::class),
		);
	}

	private function event(int $seq, ?int $caseId): AuditEvent
	{
		$e = new AuditEvent();
		$e->setSeq($seq);
		$e->setEventType('case_submitted');
		$e->setActorUid('system');
		$e->setCaseId($caseId);
		$e->setPayloadJson(null);
		$e->setCreatedAt(new \DateTime('2026-10-13T12:00:00Z'));
		return $e;
	}

	public function testPerCaseAuditWithoutWrapIsCanonical404(): void
	{
		// Case exists but the officer holds no wrap → identical payload to
		// "case does not exist" (no existence oracle, no event leak).
		$this->cases->method('find')->willReturn(new Wcase());
		$this->wraps->method('findForCaseAndOfficer')->willReturn(null);
		$this->auditMapper->expects($this->never())->method('findForCase');
		$res = $this->ctrl->auditList(7);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'NOT_FOUND', 'message' => 'Not found.', 'fields' => []],
			$res->getData());
	}

	public function testPerCaseAuditWithWrapReturnsEvents(): void
	{
		$this->cases->method('find')->willReturn(new Wcase());
		$this->wraps->method('findForCaseAndOfficer')->willReturn(new CaseKeyWrap());
		$this->wraps->method('findForOfficer')->willReturn([$this->wrapFor(7)]);
		$this->auditMapper->method('findForCase')->with(7)
			->willReturn([$this->event(1, 7), $this->event(2, 7)]);
		$res = $this->ctrl->auditList(7);
		$data = $res->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']['events']);
		$this->assertTrue($data['data']['chain_ok']);
	}

	private function wrapFor(int $caseId): CaseKeyWrap
	{
		$w = new CaseKeyWrap();
		$w->setCaseId($caseId);
		return $w;
	}

	public function testGlobalFeedFiltersInvisibleCases(): void
	{
		// Officer wraps case 7 only. Global feed must keep case-7 events and
		// system events (case_id null) but drop events for foreign cases.
		$this->wraps->method('findForOfficer')->willReturn([$this->wrapFor(7)]);
		$this->auditMapper->method('findRecent')->willReturn([
			$this->event(1, 7),
			$this->event(2, 42),   // foreign case — must be filtered
			$this->event(3, null), // system event — visible to all officers
			$this->event(4, 13),   // another foreign case
		]);
		$res = $this->ctrl->auditList();
		$data = $res->getData();
		$this->assertTrue($data['success']);
		$seqs = array_column($data['data']['events'], 'seq');
		$this->assertSame([1, 3], $seqs);
	}

	public function testGlobalFeedForWraplessOfficerShowsOnlySystemEvents(): void
	{
		$this->wraps->method('findForOfficer')->willReturn([]);
		$this->auditMapper->method('findRecent')->willReturn([
			$this->event(1, 7),
			$this->event(2, null),
		]);
		$data = $this->ctrl->auditList()->getData();
		$this->assertSame([2], array_column($data['data']['events'], 'seq'));
	}
}
