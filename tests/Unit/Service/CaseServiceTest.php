<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Crypto\CaseCodeService;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Db\IdemKeyMapper;
use OCA\WhistleCheck\Db\WattachmentMapper;
use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Db\WmessageMapper;
use OCA\WhistleCheck\Db\WnoteMapper;
use OCA\WhistleCheck\Exception\ConflictException;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Service\AttachmentService;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\CaseService;
use OCA\WhistleCheck\Service\ConfigService;
use OCA\WhistleCheck\Service\DeadlineService;
use OCA\WhistleCheck\Service\NotificationService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCA\WhistleCheck\Service\SanitizeService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CaseServiceTest extends TestCase
{
	private WcaseMapper&MockObject $cases;
	private OfficerKeyService&MockObject $officerKeys;
	private ConfigService&MockObject $config;
	private IUserManager&MockObject $users;
	private CaseService $svc;

	protected function setUp(): void
	{
		$this->cases = $this->createMock(WcaseMapper::class);
		$this->officerKeys = $this->createMock(OfficerKeyService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->svc = new CaseService(
			$this->cases,
			$this->createMock(WmessageMapper::class),
			$this->createMock(WattachmentMapper::class),
			$this->createMock(WnoteMapper::class),
			$this->createMock(IdemKeyMapper::class),
			$this->createMock(CategoryMapper::class),
			new CaseCodeService(),
			new EnvelopeService(),
			$this->createMock(DeadlineService::class),
			$this->config,
			$this->createMock(AttachmentService::class),
			$this->officerKeys,
			$this->createMock(AuditService::class),
			$this->createMock(NotificationService::class),
			new SanitizeService(),
			$this->createMock(IDBConnection::class),
			$this->users,
			$this->createMock(IConfig::class),
		);
	}

	private function case(string $status, int $rev = 0): Wcase
	{
		$c = new Wcase();
		$c->setStatus($status);
		$c->setRevision($rev);
		$c->setLegalHold(0);
		return $c;
	}

	// --- transitions -----------------------------------------------------

	public function testReceivedToInReview(): void
	{
		$case = $this->case(Wcase::STATUS_RECEIVED, 0);
		$this->cases->method('find')->willReturn($case);
		$this->cases->expects($this->once())->method('update');
		$out = $this->svc->setStatus(1, Wcase::STATUS_IN_REVIEW, 0, 'officer');
		$this->assertSame(Wcase::STATUS_IN_REVIEW, $out->getStatus());
		$this->assertSame(1, $out->getRevision());
	}

	public function testArchivedIsTerminal(): void
	{
		$case = $this->case(Wcase::STATUS_ARCHIVED, 0);
		$this->cases->method('find')->willReturn($case);
		$this->expectException(ValidationException::class);
		$this->svc->setStatus(1, Wcase::STATUS_IN_REVIEW, 0, 'officer');
	}

	public function testInvalidTransitionRejected(): void
	{
		$case = $this->case(Wcase::STATUS_RECEIVED, 0);
		$this->cases->method('find')->willReturn($case);
		$this->expectException(ValidationException::class);
		$this->svc->setStatus(1, Wcase::STATUS_ACTION_TAKEN, 0, 'officer'); // skips in_review
	}

	public function testInvalidStatusRejected(): void
	{
		$case = $this->case(Wcase::STATUS_RECEIVED, 0);
		$this->cases->method('find')->willReturn($case);
		$this->expectException(ValidationException::class);
		$this->svc->setStatus(1, 'nonsense', 0, 'officer');
	}

	public function testStaleRevisionRejected(): void
	{
		$case = $this->case(Wcase::STATUS_RECEIVED, 5);
		$this->cases->method('find')->willReturn($case);
		$this->expectException(ConflictException::class);
		$this->svc->setStatus(1, Wcase::STATUS_IN_REVIEW, 2, 'officer');
	}

	public function testUnknownCaseIs404(): void
	{
		$this->cases->method('find')->willReturn(null);
		$this->expectException(DomainException::class);
		try {
			$this->svc->setStatus(999, Wcase::STATUS_IN_REVIEW, 0, 'officer');
		} catch (DomainException $e) {
			$this->assertSame(404, $e->getCode());
			throw $e;
		}
	}

	// --- two-person close ------------------------------------------------

	public function testCloseSingleOfficerOrgClosesDirectly(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$this->cases->method('find')->willReturn($case);
		$this->officerKeys->method('activePubkeys')->willReturn(['only-officer' => 'pk']);
		$this->config->method('getInt')->with('retention_months')->willReturn(36);
		$deadline = $this->createMock(DeadlineService::class);
		$deadline->method('retentionUntil')->willReturn(new \DateTime('2029-01-01'));
		// rebuild svc with stubbed deadline? Simpler: real deadline needs config — use real service via reflection? Keep mock via construction below.

		$out = $this->svc->setStatus(1, Wcase::STATUS_CLOSED, 0, 'officer1');
		$this->assertSame(Wcase::STATUS_CLOSED, $out->getStatus());
		$this->assertSame('officer1', $out->getClosedBy());
		$this->assertNotNull($out->getClosedAt());
	}

	public function testTwoPersonCloseFirstRequesterPends(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$this->cases->method('find')->willReturn($case);
		$this->officerKeys->method('activePubkeys')->willReturn(['o1' => 'a', 'o2' => 'b']);
		$out = $this->svc->setStatus(1, Wcase::STATUS_CLOSED, 0, 'officer1');
		// Not closed yet — pending
		$this->assertSame(Wcase::STATUS_IN_REVIEW, $out->getStatus());
		$this->assertSame('officer1', $out->getCloseRequestedBy());
	}

	public function testTwoPersonCloseSameOfficerRejected(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$case->setCloseRequestedBy('officer1');
		$this->cases->method('find')->willReturn($case);
		$this->officerKeys->method('activePubkeys')->willReturn(['o1' => 'a', 'o2' => 'b']);
		$this->expectException(DomainException::class);
		try {
			$this->svc->setStatus(1, Wcase::STATUS_CLOSED, 1, 'officer1');
		} catch (DomainException $e) {
			$this->assertSame('second_officer_required', $e->errorCode);
			throw $e;
		}
	}

	public function testTwoPersonCloseSecondOfficerConfirms(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$case->setCloseRequestedBy('officer1');
		$this->cases->method('find')->willReturn($case);
		$this->officerKeys->method('activePubkeys')->willReturn(['o1' => 'a', 'o2' => 'b']);
		$deadline = $this->createMock(DeadlineService::class);
		$deadline->method('retentionUntil')->willReturn(new \DateTime('2029-01-01'));

		$svc = new CaseService(
			$this->cases,
			$this->createMock(WmessageMapper::class),
			$this->createMock(WattachmentMapper::class),
			$this->createMock(WnoteMapper::class),
			$this->createMock(IdemKeyMapper::class),
			$this->createMock(CategoryMapper::class),
			new CaseCodeService(),
			new EnvelopeService(),
			$deadline,
			$this->config,
			$this->createMock(AttachmentService::class),
			$this->officerKeys,
			$this->createMock(AuditService::class),
			$this->createMock(NotificationService::class),
			new SanitizeService(),
			$this->createMock(IDBConnection::class),
			$this->users,
			$this->createMock(IConfig::class),
		);
		$this->config->method('getInt')->willReturn(36);
		$out = $svc->setStatus(1, Wcase::STATUS_CLOSED, 1, 'officer2');
		$this->assertSame(Wcase::STATUS_CLOSED, $out->getStatus());
		$this->assertNull($out->getCloseRequestedBy());
		$this->assertSame('officer2', $out->getClosedBy());
		$this->assertEquals(new \DateTime('2029-01-01'), $out->getRetentionUntil());
	}

	// --- reopen -----------------------------------------------------------

	public function testReopenClearsClosureStamp(): void
	{
		$case = $this->case(Wcase::STATUS_CLOSED, 3);
		$case->setClosedAt(new \DateTime());
		$case->setClosedBy('o1');
		$case->setRetentionUntil(new \DateTime('2029-01-01'));
		$this->cases->method('find')->willReturn($case);
		$out = $this->svc->setStatus(1, Wcase::STATUS_IN_REVIEW, 3, 'officer');
		$this->assertSame(Wcase::STATUS_IN_REVIEW, $out->getStatus());
		$this->assertNull($out->getClosedAt());
		$this->assertNull($out->getClosedBy());
		$this->assertNull($out->getRetentionUntil());
	}

	// --- assign -----------------------------------------------------------

	public function testAssignRejectsGhostUid(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$this->cases->method('find')->willReturn($case);
		$this->users->method('userExists')->with('ghost')->willReturn(false);
		$this->officerKeys->method('isOfficer')->willReturn(true); // member row exists but user deleted
		$this->expectException(ValidationException::class);
		$this->svc->assign(1, 'ghost', 0, 'admin');
	}

	public function testAssignRejectsNonOfficerRealUser(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$this->cases->method('find')->willReturn($case);
		$this->users->method('userExists')->willReturn(true);
		$this->officerKeys->method('isOfficer')->with('regular_user')->willReturn(false);
		$this->expectException(ValidationException::class);
		$this->svc->assign(1, 'regular_user', 0, 'admin');
	}

	public function testAssignValidOfficer(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$this->cases->method('find')->willReturn($case);
		$this->users->method('userExists')->willReturn(true);
		$this->officerKeys->method('isOfficer')->willReturn(true);
		$out = $this->svc->assign(1, 'officer2', 0, 'admin');
		$this->assertSame('officer2', $out->getAssignedTo());
		$this->assertSame(1, $out->getRevision());
	}

	public function testAssignUnassignNull(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$case->setAssignedTo('o1');
		$this->cases->method('find')->willReturn($case);
		$out = $this->svc->assign(1, null, 0, 'admin');
		$this->assertNull($out->getAssignedTo());
	}

	// --- hold ---------------------------------------------------------------

	public function testHoldSetAndRelease(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$this->cases->method('find')->willReturn($case);
		$out = $this->svc->setHold(1, true, ' court order ', 0, 'admin');
		$this->assertSame(1, $out->getLegalHold());
		$this->assertSame('court order', $out->getHoldReason());
		$this->assertSame('admin', $out->getHoldSetBy());
	}

	public function testHoldReleaseClearsReason(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$case->setLegalHold(1);
		$case->setHoldReason('x');
		$case->setHoldSetBy('a');
		$this->cases->method('find')->willReturn($case);
		$out = $this->svc->setHold(1, false, null, 1, 'admin');
		$this->assertSame(0, $out->getLegalHold());
		$this->assertNull($out->getHoldReason());
		$this->assertNull($out->getHoldSetBy());
	}

	// --- reply ---------------------------------------------------------------

	public function testOfficerReplyWithoutRevisionFailsClosed(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 2);
		$this->cases->method('find')->willReturn($case);
		$envelope = new EnvelopeService();
		$cipher = $envelope->seal('x', $envelope->randomKey());
		$this->expectException(ConflictException::class);
		$this->svc->reply(1, 'officer', $cipher, 'o1', null);
	}

	public function testReplyRejectsBadCipher(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$this->cases->method('find')->willReturn($case);
		$this->expectException(ValidationException::class);
		$this->svc->reply(1, 'officer', 'not-an-envelope', 'o1', 0);
	}

	public function testReplyToTerminalCaseRejected(): void
	{
		$case = $this->case(Wcase::STATUS_ARCHIVED, 0);
		$this->cases->method('find')->willReturn($case);
		$envelope = new EnvelopeService();
		$cipher = $envelope->seal('x', $envelope->randomKey());
		$this->expectException(DomainException::class);
		$this->svc->reply(1, 'reporter', $cipher, null, null);
	}
}
