<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Crypto\CaseCodeService;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Db\IdemKey;
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
	private \OCA\WhistleCheck\Db\CaseKeyWrapMapper&MockObject $wraps;
	private OfficerKeyService&MockObject $officerKeys;
	private ConfigService&MockObject $config;
	private IUserManager&MockObject $users;
	private DeadlineService&MockObject $deadlines;
	private IdemKeyMapper&MockObject $idem;
	private \OCP\IDBConnection&MockObject $db;
	private WmessageMapper&MockObject $messages;
	private IConfig&MockObject $sysConfig;
	private string $instanceSecret = 'test-instance-secret';
	private bool $casFails = false;
	private ?\OCA\WhistleCheck\Db\CaseKeyWrap $heldWrap = null;
	private CaseService $svc;

	protected function setUp(): void
	{
		$this->cases = $this->createMock(WcaseMapper::class);
		$this->officerKeys = $this->createMock(OfficerKeyService::class);
		$this->config = $this->createMock(ConfigService::class);
		// two_person_close defaults ON in production (DEFAULTS['1']) — mirror it.
		$this->config->method('getBool')->willReturnCallback(
			static fn (string $k) => $k === 'two_person_close');
		$this->users = $this->createMock(IUserManager::class);
		// Mirror the real CAS write: bump the entity's revision and report 1
		// affected row (0 would mean a concurrent write won → ConflictException).
		$this->deadlines = $this->createMock(DeadlineService::class);
		$this->idem = $this->createMock(IdemKeyMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->cases->method('updateWithRevision')->willReturnCallback(
			fn (Wcase $c, int $rev) => $this->casFails ? 0 : ($c->setRevision($rev + 1) ?? 1));
		$this->messages = $this->createMock(WmessageMapper::class);
		$this->wraps = $this->createMock(\OCA\WhistleCheck\Db\CaseKeyWrapMapper::class);
		// Officer mutations re-check the case wrap FOR UPDATE inside the
		// transaction — default to "holds a wrap" so happy-path tests exercise
		// the mutation; revoke-path tests null $this->heldWrap out.
		$this->heldWrap = new \OCA\WhistleCheck\Db\CaseKeyWrap();
		$this->wraps->method('findForCaseAndOfficerForUpdate')
			->willReturnCallback(fn () => $this->heldWrap);
		$this->sysConfig = $this->createMock(IConfig::class);
		$this->sysConfig->method('getSystemValueString')
			->willReturnCallback(fn (string $k, string $d = '') => $k === 'secret' ? $this->instanceSecret : $d);

		$this->svc = new CaseService(
			$this->cases,
			$this->messages,
			$this->createMock(WattachmentMapper::class),
			$this->createMock(WnoteMapper::class),
			$this->idem,
			$this->createMock(CategoryMapper::class),
			new CaseCodeService(),
			new EnvelopeService(),
			$this->deadlines,
			$this->config,
			$this->createMock(AttachmentService::class),
			$this->officerKeys,
			$this->createMock(AuditService::class),
			$this->createMock(NotificationService::class),
			new SanitizeService(),
			$this->db,
			$this->users,
			$this->sysConfig,
			$this->wraps,
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
		$this->cases->expects($this->once())->method('updateWithRevision')->willReturnCallback(fn (Wcase $c, int $r) => ($c->setRevision($r + 1) ?? 1));
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

	public function testSetStatusFailsWhenWrapVanishedInTx(): void
	{
		// Revoked mid-request: the FOR UPDATE re-check inside the mutation
		// transaction sees the wrap deleted and the write never commits.
		$case = $this->case(Wcase::STATUS_RECEIVED, 0);
		$this->cases->method('find')->willReturn($case);
		$this->heldWrap = null; // the FOR UPDATE re-check sees the wrap deleted
		$this->cases->expects($this->never())->method('updateWithRevision');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		try {
			$this->svc->setStatus(1, Wcase::STATUS_IN_REVIEW, 0, 'revoked-officer');
			$this->fail('expected not_found');
		} catch (DomainException $e) {
			$this->assertSame(404, $e->getCode());
		}
	}

	public function testStatusChangeRollsBackWhenAuditAppendFails(): void
	{
		// The audit event is mandatory: if the chain append fails inside the
		// transaction, the status mutation must roll back with it — a
		// committed-but-unaudited lifecycle change is a compliance hole.
		$case = $this->case(Wcase::STATUS_RECEIVED, 0);
		$this->cases->method('find')->willReturn($case);
		$this->cases->expects($this->once())->method('updateWithRevision');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$audit = $this->createMock(AuditService::class);
		$audit->method('record')->willThrowException(new \RuntimeException('audit chain busy'));
		$svc = new CaseService(
			$this->cases,
			$this->messages,
			$this->createMock(WattachmentMapper::class),
			$this->createMock(WnoteMapper::class),
			$this->idem,
			$this->createMock(CategoryMapper::class),
			new CaseCodeService(),
			new EnvelopeService(),
			$this->deadlines,
			$this->config,
			$this->createMock(AttachmentService::class),
			$this->officerKeys,
			$audit,
			$this->createMock(NotificationService::class),
			new SanitizeService(),
			$this->db,
			$this->users,
			$this->sysConfig,
			$this->wraps,
		);
		$this->expectException(\RuntimeException::class);
		$svc->setStatus(1, Wcase::STATUS_IN_REVIEW, 0, 'officer');
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
		$this->assertSame(Wcase::STATUS_CLOSED, $out->getCloseRequestedTo());
	}

	public function testTwoPersonCloseSameOfficerRejected(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$case->setCloseRequestedBy('officer1');
		$case->setCloseRequestedTo(Wcase::STATUS_CLOSED);
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
		$case->setCloseRequestedTo(Wcase::STATUS_CLOSED);
		$this->cases->method('find')->willReturn($case);
		$this->officerKeys->method('activePubkeys')->willReturn(['o1' => 'a', 'o2' => 'b']);
		$deadline = $this->createMock(DeadlineService::class);
		$deadline->method('retentionUntil')->willReturn(new \DateTime('2029-01-01'));

		$svc = new CaseService(
			$this->cases,
			$this->messages,
			$this->createMock(WattachmentMapper::class),
			$this->createMock(WnoteMapper::class),
			$this->idem,
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
			$this->db,
			$this->users,
			$this->sysConfig,
			$this->wraps,
		);
		$this->config->method('getInt')->willReturn(36);
		$out = $svc->setStatus(1, Wcase::STATUS_CLOSED, 1, 'officer2');
		$this->assertSame(Wcase::STATUS_CLOSED, $out->getStatus());
		$this->assertNull($out->getCloseRequestedBy());
		$this->assertSame('officer2', $out->getClosedBy());
		$this->assertEquals(new \DateTime('2029-01-01'), $out->getRetentionUntil());
	}

	public function testPendingArchiveCannotBeConfirmedAsClosed(): void
	{
		// An `archived` request must not be confirmable as `closed` — the
		// stored target rules.
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$case->setCloseRequestedBy('officer1');
		$case->setCloseRequestedTo(Wcase::STATUS_ARCHIVED);
		$this->cases->method('find')->willReturn($case);
		$this->officerKeys->method('activePubkeys')->willReturn(['o1' => 'a', 'o2' => 'b']);
		$this->expectException(DomainException::class);
		try {
			$this->svc->setStatus(1, Wcase::STATUS_CLOSED, 1, 'officer2');
		} catch (DomainException $e) {
			$this->assertSame('pending_mismatch', $e->errorCode);
			throw $e;
		}
	}

	public function testConfirmCloseAppliesStoredTarget(): void
	{
		// Pending target is `archived` → confirmClose must archive, not close.
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$case->setCloseRequestedBy('officer1');
		$case->setCloseRequestedTo(Wcase::STATUS_ARCHIVED);
		$this->cases->method('find')->willReturn($case);
		$this->officerKeys->method('activePubkeys')->willReturn(['o1' => 'a', 'o2' => 'b']);
		$this->config->method('getInt')->willReturn(36);
		$this->deadlines->method('retentionUntil')->willReturn(new \DateTime('2029-01-01'));
		$out = $this->svc->confirmClose(1, 1, 'officer2');
		$this->assertSame(Wcase::STATUS_ARCHIVED, $out->getStatus());
		$this->assertNull($out->getCloseRequestedBy());
		$this->assertNull($out->getCloseRequestedTo());
	}

	public function testConfirmCloseLegacyNullTargetAppliesClosed(): void
	{
		// Rows written before `close_requested_to` existed have by=<uid>,
		// to=NULL — the old confirm path applied `closed`, so NULL must
		// still confirm as `closed` rather than deadlocking the case.
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$case->setCloseRequestedBy('officer1');
		$case->setCloseRequestedTo(null);
		$this->cases->method('find')->willReturn($case);
		$this->officerKeys->method('activePubkeys')->willReturn(['o1' => 'a', 'o2' => 'b']);
		$this->deadlines->method('retentionUntil')->willReturn(new \DateTime('2029-01-01'));
		$out = $this->svc->confirmClose(1, 1, 'officer2');
		$this->assertSame(Wcase::STATUS_CLOSED, $out->getStatus());
	}

	public function testConfirmCloseNothingPending(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$this->cases->method('find')->willReturn($case);
		$this->expectException(DomainException::class);
		try {
			$this->svc->confirmClose(1, 1, 'officer2');
		} catch (DomainException $e) {
			$this->assertSame('nothing_pending', $e->errorCode);
			throw $e;
		}
	}

	// --- idempotent submit -------------------------------------------------

	public function testSubmitReplayReturnsExistingCase(): void
	{
		$existing = new IdemKey();
		$existing->setCaseId(42);
		$this->idem->method('findByKey')->willReturn($existing);
		$out = $this->svc->submit(['ikey' => 'replay-key-123'], true);
		$this->assertSame(42, $out['case_id']);
	}

	public function testSubmitUniqueViolationReturnsExisting(): void
	{
		// Two concurrent submits with the same ikey: the loser's INSERT hits
		// the unique index → catch, re-read, return the winner's case id.
		$existing = new IdemKey();
		$existing->setCaseId(77);
		$this->idem->method('findByKey')->willReturnOnConsecutiveCalls(null, $existing);
		$this->cases->method('insert')->willThrowException(new \OCP\DB\Exception('dup', 0, null));

		$env = new EnvelopeService();
		$payload = [
			'ikey' => 'race-key-123456',
			'hint' => str_repeat('a', 64),
			'verifier' => str_repeat('b', 64),
			'code_salt' => str_repeat('c', 32),
			'key_salt' => str_repeat('d', 32),
			'reporter_wrap' => $env->seal('k', $env->randomKey()),
			'body' => $env->seal('body', $env->randomKey()),
			'wraps' => [['uid' => 'o1', 'wrap' => $env->seal('w', $env->randomKey())]],
		];
		$out = $this->svc->submit($payload, true);
		$this->assertSame(77, $out['case_id']);
	}

	// --- reopen -----------------------------------------------------------

	public function testReopenClearsClosureStamp(): void
	{
		$case = $this->case(Wcase::STATUS_CLOSED, 3);
		$case->setClosedAt(new \DateTime());
		$case->setClosedBy('o1');
		$case->setRetentionUntil(new \DateTime('2029-01-01'));
		$case->setCloseRequestedBy('o1');
		$case->setCloseRequestedTo(Wcase::STATUS_ARCHIVED);
		$this->cases->method('find')->willReturn($case);
		$out = $this->svc->setStatus(1, Wcase::STATUS_IN_REVIEW, 3, 'officer');
		$this->assertSame(Wcase::STATUS_IN_REVIEW, $out->getStatus());
		$this->assertNull($out->getClosedAt());
		$this->assertNull($out->getClosedBy());
		$this->assertNull($out->getRetentionUntil());
		$this->assertNull($out->getCloseRequestedBy());
		$this->assertNull($out->getCloseRequestedTo());
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

	public function testReporterCanReplyOnClosedCase(): void
	{
		// Spec US-002 AC-4: only ARCHIVED is read-only — a `closed` case may
		// still receive a reporter message before archival.
		$case = $this->case(Wcase::STATUS_CLOSED, 3);
		$this->cases->method('find')->willReturn($case);
		$envelope = new EnvelopeService();
		$cipher = $envelope->seal('reporter note', $envelope->randomKey());
		$out = $this->svc->reply(1, 'reporter', $cipher, null, null);
		$this->assertSame(Wcase::STATUS_CLOSED, $out->getStatus());
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

	public function testReplyCasLossLeavesNoOrphanMessage(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 5);
		$this->cases->method('find')->willReturn($case);
		$this->casFails = true;
		$this->messages->expects($this->once())->method('insert');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$envelope = new EnvelopeService();
		$this->expectException(ConflictException::class);
		$this->svc->reply(1, 'officer', $envelope->seal('x', $envelope->randomKey()), 'o1', 5);
	}

	public function testReplyRejectsOversizedCipher(): void
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 0);
		$this->cases->method('find')->willReturn($case);
		$huge = json_encode(['v' => 1, 'alg' => 'xs20p1305', 'n' => str_repeat('A', 32), 'c' => str_repeat('B', CaseService::MAX_MESSAGE_CIPHER_BYTES)]);
		$this->expectException(ValidationException::class);
		$this->svc->reply(1, 'officer', $huge, 'o1', 0);
	}

	public function testSubmitRejectsTooManyAttachments(): void
	{
		$env = new EnvelopeService();
		$att = ['cipher' => $env->seal('a', $env->randomKey()), 'mime' => 'text/plain'];
		$this->expectException(ValidationException::class);
		try {
			$this->svc->submit([
				'ikey' => 'att-count-key-1',
				'hint' => str_repeat('a', 64),
				'verifier' => str_repeat('b', 64),
				'code_salt' => str_repeat('c', 32),
				'key_salt' => str_repeat('d', 32),
				'reporter_wrap' => $env->seal('k', $env->randomKey()),
				'body' => $env->seal('b', $env->randomKey()),
				'wraps' => [['uid' => 'o1', 'wrap' => $env->seal('w', $env->randomKey())]],
				'attachments' => array_fill(0, CaseService::MAX_ATTACHMENTS + 1, $att),
			], true);
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('attachments', $e->getFields());
			throw $e;
		}
	}

	public function testSubmitStoresHashedVerifierNotRawVerifier(): void
	{
		$env = new EnvelopeService();
		$verifier = str_repeat('b', 64);
		$this->cases->expects($this->once())->method('insert')
			->willReturnCallback(function (Wcase $c) use ($verifier) {
				$this->assertSame(hash('sha256', $verifier), $c->getCodeHash());
				$this->assertNotSame($verifier, $c->getCodeHash());
				$c->setId(1);
				return $c;
			});
		$this->svc->submit([
			'ikey' => 'hash-store-key-1',
			'hint' => str_repeat('a', 64),
			'verifier' => $verifier,
			'code_salt' => str_repeat('c', 32),
			'key_salt' => str_repeat('d', 32),
			'reporter_wrap' => $env->seal('k', $env->randomKey()),
			'body' => $env->seal('b', $env->randomKey()),
			'wraps' => [['uid' => 'o1', 'wrap' => $env->seal('w', $env->randomKey())]],
		], true);
	}

	public function testOpenByHintRejectsStoredVerifierAsCredential(): void
	{
		// code_hash = sha256(verifier). Presenting the STORED value must fail —
		// a DB dump cannot be replayed as an auth credential.
		$verifier = str_repeat('e', 64);
		$case = $this->case(Wcase::STATUS_RECEIVED, 0);
		$case->setCodeHash(hash('sha256', $verifier));
		$this->cases->method('findByHint')->willReturn($case);
		$this->expectException(DomainException::class);
		$this->svc->openByHint(str_repeat('a', 64), $case->getCodeHash());
	}

	public function testOpenByHintAcceptsRealVerifier(): void
	{
		$verifier = str_repeat('e', 64);
		$case = $this->case(Wcase::STATUS_RECEIVED, 0);
		$case->setId(1);
		$case->setCodeHash(hash('sha256', $verifier));
		$case->setReporterWrap('');
		$case->setReceivedAt(new \DateTime('2026-10-12', new \DateTimeZone('UTC')));
		$case->setFeedbackDue(new \DateTime('2027-01-12'));
		$this->cases->method('findByHint')->willReturn($case);
		$view = $this->svc->openByHint(str_repeat('a', 64), $verifier);
		$this->assertSame(Wcase::STATUS_RECEIVED, $view['status']);
	}

	// --- receipt_cipher validation ----------------------------------------

	private function submitPayload(EnvelopeService $env, array $extra = []): array
	{
		return array_merge([
			'ikey' => 'receipt-key-123456',
			'hint' => str_repeat('a', 64),
			'verifier' => str_repeat('b', 64),
			'code_salt' => str_repeat('c', 32),
			'key_salt' => str_repeat('d', 32),
			'reporter_wrap' => $env->seal('k', $env->randomKey()),
			'body' => $env->seal('b', $env->randomKey()),
			'wraps' => [['uid' => 'o1', 'wrap' => $env->seal('w', $env->randomKey())]],
		], $extra);
	}

	public function testSubmitRejectsInvalidReceiptCipher(): void
	{
		$env = new EnvelopeService();
		$this->cases->expects($this->never())->method('insert');
		try {
			$this->svc->submit(
				$this->submitPayload($env, ['receipt_cipher' => 'not-an-envelope']), true);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('body', $e->getFields());
		}
	}

	public function testSubmitStoresClientReceiptCipher(): void
	{
		$env = new EnvelopeService();
		$receipt = $env->seal('receipt-text', $env->randomKey());
		$this->cases->method('insert')->willReturnCallback(function (Wcase $c) {
			$c->setId(1);
			return $c;
		});
		$inserted = [];
		$this->messages->method('insert')->willReturnCallback(function ($m) use (&$inserted) {
			$inserted[] = $m;
			return $m;
		});
		$this->svc->submit(
			$this->submitPayload($env, ['receipt_cipher' => $receipt]), true);
		$this->assertCount(2, $inserted); // report body + automatic receipt
		$this->assertSame('officer', $inserted[1]->getDirection());
		$this->assertSame($receipt, $inserted[1]->getCipher());
	}

	public function testSubmitReceiptFallsBackToBodyCipher(): void
	{
		$env = new EnvelopeService();
		$body = $env->seal('the-report', $env->randomKey());
		$this->cases->method('insert')->willReturnCallback(function (Wcase $c) {
			$c->setId(1);
			return $c;
		});
		$inserted = [];
		$this->messages->method('insert')->willReturnCallback(function ($m) use (&$inserted) {
			$inserted[] = $m;
			return $m;
		});
		$this->svc->submit($this->submitPayload($env, ['body' => $body]), true);
		$this->assertSame($body, $inserted[1]->getCipher());
	}

	// --- reporter reply idempotency ----------------------------------------

	private function verifiedCase(string $verifier): Wcase
	{
		$case = $this->case(Wcase::STATUS_IN_REVIEW, 1);
		$case->setId(9);
		$case->setCodeHash(hash('sha256', $verifier));
		return $case;
	}

	public function testReporterReplyReplayReturnsCaseWithoutAppending(): void
	{
		// ikey already recorded → replay-safe early return: no message insert,
		// no hint lookup, no transaction.
		$existing = new IdemKey();
		$existing->setCaseId(9);
		$this->idem->method('findByKey')->willReturn($existing);
		$this->cases->expects($this->never())->method('findByHint');
		$this->messages->expects($this->never())->method('insert');
		$env = new EnvelopeService();
		$out = $this->svc->replyAsReporterByHint(
			str_repeat('a', 64), str_repeat('b', 64),
			$env->seal('m', $env->randomKey()), 'draft-key-123');
		$this->assertSame(9, $out);
	}

	public function testReporterReplyAppendsWithIkeyMarker(): void
	{
		$env = new EnvelopeService();
		$verifier = str_repeat('b', 64);
		$this->cases->method('findByHint')->willReturn($this->verifiedCase($verifier));
		$seenKeys = [];
		$this->idem->method('insert')->willReturnCallback(function (IdemKey $k) use (&$seenKeys) {
			$seenKeys[] = $k->getIkey();
			return $k;
		});
		$this->messages->expects($this->once())->method('insert');
		$this->db->expects($this->once())->method('commit');
		$out = $this->svc->replyAsReporterByHint(
			str_repeat('a', 64), $verifier, $env->seal('m', $env->randomKey()), 'draft-abc-456');
		$this->assertSame(9, $out);
		$this->assertSame(['reply-draft-abc-456'], $seenKeys); // namespaced marker
	}

	public function testReporterReplyUniqueViolationReturnsWinner(): void
	{
		// Two racing identical retries: loser hits the unique index inside the
		// tx, rolls back, re-reads, and returns the winner's case id.
		$env = new EnvelopeService();
		$verifier = str_repeat('b', 64);
		$this->cases->method('findByHint')->willReturn($this->verifiedCase($verifier));
		$winner = new IdemKey();
		$winner->setCaseId(9);
		$this->idem->method('findByKey')->willReturnOnConsecutiveCalls(null, $winner);
		$this->idem->method('insert')->willThrowException(new \OCP\DB\Exception('dup', 0, null));
		$this->messages->expects($this->never())->method('insert');
		$this->db->expects($this->once())->method('rollBack');
		$out = $this->svc->replyAsReporterByHint(
			str_repeat('a', 64), $verifier, $env->seal('m', $env->randomKey()), 'draft-race-1');
		$this->assertSame(9, $out);
	}

	public function testReporterReplyRejectsMalformedIkey(): void
	{
		$env = new EnvelopeService();
		$this->cases->expects($this->never())->method('findByHint');
		try {
			$this->svc->replyAsReporterByHint(
				str_repeat('a', 64), str_repeat('b', 64),
				$env->seal('m', $env->randomKey()), 'short');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('ikey', $e->getFields());
		}
	}

	public function testReporterReplyWithoutIkeyStillWorks(): void
	{
		// Backwards compatibility: callers without an ikey take the plain
		// reply path (single tx inside reply()).
		$env = new EnvelopeService();
		$verifier = str_repeat('b', 64);
		$case = $this->verifiedCase($verifier);
		$this->cases->method('findByHint')->willReturn($case);
		$this->cases->method('find')->willReturn($case); // reply() re-reads by id
		$this->idem->expects($this->never())->method('findByKey');
		$this->messages->expects($this->once())->method('insert');
		$out = $this->svc->replyAsReporterByHint(
			str_repeat('a', 64), $verifier, $env->seal('m', $env->randomKey()));
		$this->assertSame(9, $out);
	}

	// --- paramsByHint anti-enumeration ------------------------------------

	public function testParamsByHintUnknownCaseReturnsDeterministicFakeSalts(): void
	{
		$this->cases->method('findByHint')->willReturn(null);
		$hint = str_repeat('ab', 32);
		$a = $this->svc->paramsByHint($hint);
		$b = $this->svc->paramsByHint($hint);
		$this->assertSame($a, $b);
		$this->assertSame(32, strlen($a['salt']));
		$this->assertSame(32, strlen($a['key_salt']));
		$this->assertNotSame($a['salt'], $a['key_salt']);
		// A different instance secret must produce different fake salts —
		// otherwise the pseudo-salt leaks which hints are unclaimed.
		$this->instanceSecret = 'other-secret';
		$this->assertNotSame($a, $this->svc->paramsByHint($hint));
	}

	public function testParamsByHintFailsClosedWithoutInstanceSecret(): void
	{
		// An empty `secret` must hard-fail: a publicly known fallback makes
		// fake salts computable → paramsByHint becomes an existence oracle.
		$this->cases->method('findByHint')->willReturn(null);
		$this->instanceSecret = '';
		$this->expectException(\RuntimeException::class);
		$this->svc->paramsByHint(str_repeat('ab', 32));
	}

}
