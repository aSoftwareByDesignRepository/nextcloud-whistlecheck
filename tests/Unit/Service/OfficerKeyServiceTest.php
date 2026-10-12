<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Db\CaseKeyWrap;
use OCA\WhistleCheck\Db\CaseKeyWrapMapper;
use OCA\WhistleCheck\Db\Officer;
use OCA\WhistleCheck\Db\OfficerMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCP\IGroup;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OfficerKeyServiceTest extends TestCase
{
	private OfficerMapper&MockObject $officers;
	private CaseKeyWrapMapper&MockObject $wraps;
	private IUserManager&MockObject $users;
	private IGroupManager&MockObject $groups;
	private IGroup&MockObject $group;
	private IDBConnection&MockObject $db;
	private OfficerKeyService $svc;

	protected function setUp(): void
	{
		$this->officers = $this->createMock(OfficerMapper::class);
		$this->wraps = $this->createMock(CaseKeyWrapMapper::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->group = $this->createMock(IGroup::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->groups->method('get')->willReturn($this->group);
		$this->svc = new OfficerKeyService(
			$this->officers,
			$this->wraps,
			new EnvelopeService(),
			$this->createMock(AuditService::class),
			$this->users,
			$this->groups,
			$this->db,
		);
	}

	private function activeOfficer(string $uid, int $version = 1): Officer
	{
		$o = new Officer();
		$o->setUid($uid);
		$o->setPubkey(base64_encode(random_bytes(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)));
		$o->setFingerprint('fp');
		$o->setStatus(Officer::STATUS_ACTIVE);
		$o->setKeyVersion($version);
		return $o;
	}

	private function sealedbox(): string
	{
		$env = new EnvelopeService();
		$kp = sodium_crypto_box_keypair();
		$pk = sodium_crypto_box_publickey($kp);
		return $env->sealToPubkey('key-material', base64_encode($pk));
	}

	// ── wrap grant authorization ────────────────────────────────────────

	public function testStoreWrapsRejectsOverwriteOfExistingWrap(): void
	{
		$target = $this->activeOfficer('o2');
		$this->officers->method('findByUidForUpdate')->willReturn($target);
		$this->wraps->method('findForCaseAndOfficer')->willReturn(new CaseKeyWrap());
		$this->wraps->expects($this->never())->method('update');
		$this->expectException(ValidationException::class);
		$this->svc->storeWraps(7, ['o2' => ['wrap' => $this->sealedbox(), 'fp' => 'fp']], 'o1');
	}

	public function testStoreWrapsInsertsForWraplessOfficer(): void
	{
		$target = $this->activeOfficer('o2');
		$this->officers->method('findByUidForUpdate')->willReturn($target);
		$this->wraps->method('findForCaseAndOfficer')
			->willReturnMap([[7, 'o1', new CaseKeyWrap()]]); // grantor holds a wrap

		$this->wraps->expects($this->once())->method('insert');
		$this->svc->storeWraps(7, ['o2' => ['wrap' => $this->sealedbox(), 'fp' => 'fp']], 'o1');
	}

	public function testStoreWrapsRejectsRevokedOrForeignUid(): void
	{
		$this->wraps->method('findForCaseAndOfficer')
			->willReturnMap([[7, 'o1', new CaseKeyWrap()]]); // grantor holds a wrap
		$this->officers->method('findByUidForUpdate')->willReturn(null);
		$this->expectException(ValidationException::class);
		$this->svc->storeWraps(7, ['ghost' => ['wrap' => $this->sealedbox(), 'fp' => 'fp']], 'o1');
	}

	public function testStoreWrapsRejectsMissingFingerprint(): void
	{
		$this->wraps->method('findForCaseAndOfficer')
			->willReturnMap([[7, 'o1', new CaseKeyWrap()]]); // grantor holds a wrap
		$this->officers->method('findByUidForUpdate')->willReturn($this->activeOfficer('o2'));
		$this->expectException(ValidationException::class);
		$this->svc->storeWraps(7, ['o2' => ['wrap' => $this->sealedbox()]], 'o1');
	}

	public function testStoreWrapsRejectsStaleFingerprint(): void
	{
		// Wrap was sealed to a key that has since rotated — a 409 tells the
		// client to refetch pubkeys and re-seal, never a silent dead wrap.
		$target = $this->activeOfficer('o2');
		$target->setFingerprint('new-fp');
		$this->wraps->method('findForCaseAndOfficer')
			->willReturnMap([[7, 'o1', new CaseKeyWrap()]]); // grantor holds a wrap
		$this->officers->method('findByUidForUpdate')->willReturn($target);
		$this->wraps->expects($this->never())->method('insert');
		try {
			$this->svc->storeWraps(7, ['o2' => ['wrap' => $this->sealedbox(), 'fp' => 'fp']], 'o1');
			$this->fail('expected stale-key rejection');
		} catch (\OCA\WhistleCheck\Exception\DomainException $e) {
			$this->assertSame('stale_officer_key', $e->errorCode);
			$this->assertSame(409, $e->httpStatus);
		}
	}

	public function testStoreWrapsAcceptsListWireForm(): void
	{
		$target = $this->activeOfficer('o2');
		$this->officers->method('findByUidForUpdate')->willReturn($target);
		$this->wraps->method('findForCaseAndOfficer')
			->willReturnMap([[7, 'o1', new CaseKeyWrap()]]); // grantor holds a wrap

		$this->wraps->expects($this->once())->method('insert');
		$this->svc->storeWraps(7, [['uid' => 'o2', 'wrap' => $this->sealedbox(), 'fp' => 'fp']], 'o1');
	}

	public function testStoreWrapsRejectsNonHolderGrantor(): void
	{
		// Re-wrap may only be minted by an officer holding a wrap on the
		// case — 'o3' holds nothing on case 7 → 403, never an insert.
		$this->wraps->method('findForCaseAndOfficer')->willReturn(null);
		$this->wraps->expects($this->never())->method('insert');
		try {
			$this->svc->storeWraps(7, ['o2' => ['wrap' => $this->sealedbox(), 'fp' => 'fp']], 'o3');
			$this->fail('expected non-holder rejection');
		} catch (\OCA\WhistleCheck\Exception\DomainException $e) {
			$this->assertSame('not_a_holder', $e->errorCode);
			$this->assertSame(403, $e->httpStatus);
		}
	}

	public function testStoreWrapsAnonymousSubmitSkipsGrantorInvariant(): void
	{
		// Initial submit passes grantedBy=null — the reporter holds nothing
		// and the invariant must not apply (it would reject every report).
		$target = $this->activeOfficer('o2');
		$this->officers->method('findByUidForUpdate')->willReturn($target);
		$this->wraps->method('findForCaseAndOfficer')->willReturn(null);
		$this->wraps->expects($this->once())->method('insert');
		$this->svc->storeWraps(7, ['o2' => ['wrap' => $this->sealedbox(), 'fp' => 'fp']], null);
	}

	// ── rotation ────────────────────────────────────────────────────────

	public function testReRegisterSameKeyIsNoOpNotRotation(): void
	{
		$existing = $this->activeOfficer('o1', 3);
		$this->officers->method('findByUid')->willReturn($existing);
		$user = $this->createMock(IUser::class);
		$this->users->method('get')->willReturn($user);
		$this->groups->method('isInGroup')->willReturn(true);
		$this->group->method('inGroup')->willReturn(true);
		// Same pubkey → no version bump, no wrap wipe, no audit rotation.
		$this->officers->expects($this->never())->method('update');
		$this->wraps->expects($this->never())->method('deleteForOfficer');
		$out = $this->svc->register('o1', $existing->getPubkey(), null);
		$this->assertSame(3, $out->getKeyVersion());
	}

	public function testRotationWipesStaleWraps(): void
	{
		$existing = $this->activeOfficer('o1', 2);
		$this->officers->method('findByUid')->willReturn($existing);
		$user = $this->createMock(IUser::class);
		$this->users->method('get')->willReturn($user);
		$this->groups->method('isInGroup')->willReturn(true);
		$this->group->method('inGroup')->willReturn(true);
		$this->wraps->expects($this->once())->method('deleteForOfficer')->with('o1');
		$newKey = base64_encode(random_bytes(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES));
		$out = $this->svc->register('o1', $newKey, null);
		$this->assertSame(3, $out->getKeyVersion());
	}

	public function testRotationRewrapsHeldCasesAtomically(): void
	{
		$existing = $this->activeOfficer('o1', 2);
		$this->officers->method('findByUid')->willReturn($existing);
		$this->groups->method('isInGroup')->willReturn(true);
		$w3 = new CaseKeyWrap(); $w3->setCaseId(3);
		$w5 = new CaseKeyWrap(); $w5->setCaseId(5);
		$this->wraps->method('findForOfficer')->willReturn([$w3, $w5]);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$inserted = [];
		$this->wraps->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function (CaseKeyWrap $w) use (&$inserted) { $inserted[] = $w->getCaseId(); return $w; });
		$out = $this->svc->register('o1', base64_encode(random_bytes(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)), null,
			[3 => $this->sealedbox(), 5 => $this->sealedbox()]);
		$this->assertSame([3, 5], $inserted);
		$this->assertSame(3, $out->getKeyVersion());
	}

	public function testRotationRejectsWrapForUnheldCaseWithoutWiping(): void
	{
		// A self-wrap naming a case the officer never held must be rejected
		// outright — the wrap row IS the authorization oracle; minting one
		// on a foreign case would be blind-write privilege escalation.
		$existing = $this->activeOfficer('o1', 2);
		$this->officers->method('findByUid')->willReturn($existing);
		$this->groups->method('isInGroup')->willReturn(true);
		$w3 = new CaseKeyWrap(); $w3->setCaseId(3);
		$this->wraps->method('findForOfficer')->willReturn([$w3]);
		$this->wraps->expects($this->never())->method('deleteForOfficer');
		$this->officers->expects($this->never())->method('update');
		$this->expectException(ValidationException::class);
		$this->svc->register('o1', base64_encode(random_bytes(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)), null,
			[9 => $this->sealedbox()]);
	}

	public function testRotationRejectsMalformedSelfWrap(): void
	{
		$existing = $this->activeOfficer('o1', 2);
		$this->officers->method('findByUid')->willReturn($existing);
		$this->groups->method('isInGroup')->willReturn(true);
		$w3 = new CaseKeyWrap(); $w3->setCaseId(3);
		$this->wraps->method('findForOfficer')->willReturn([$w3]);
		$this->expectException(ValidationException::class);
		$this->svc->register('o1', base64_encode(random_bytes(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)), null,
			[3 => 'not-an-envelope']);
	}

	public function testFirstRegisterRejectsSelfWraps(): void
	{
		// Held set is empty on first registration — wraps are rejected, not
		// silently ignored.
		$this->officers->method('findByUid')->willReturn(null);
		$this->groups->method('isInGroup')->willReturn(true);
		$this->wraps->method('findForOfficer')->willReturn([]);
		$this->expectException(ValidationException::class);
		$this->svc->register('o1', base64_encode(random_bytes(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)), null,
			[3 => $this->sealedbox()]);
	}

	public function testRotationRollsBackOnWrapInsertFailure(): void
	{
		$existing = $this->activeOfficer('o1', 2);
		$this->officers->method('findByUid')->willReturn($existing);
		$this->groups->method('isInGroup')->willReturn(true);
		$w3 = new CaseKeyWrap(); $w3->setCaseId(3);
		$this->wraps->method('findForOfficer')->willReturn([$w3]);
		$this->wraps->method('insert')->willThrowException(new \RuntimeException('db gone'));
		$this->db->expects($this->once())->method('rollBack');
		$this->expectException(\RuntimeException::class);
		$this->svc->register('o1', base64_encode(random_bytes(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)), null,
			[3 => $this->sealedbox()]);
	}

	// ── revoke ──────────────────────────────────────────────────────────

	public function testRevokeEjectsGroupMemberWithoutOfficerRow(): void
	{
		// User in officer group but no wchk_officers row — must still be ejected.
		$this->officers->method('findByUid')->willReturn(null);
		$user = $this->createMock(IUser::class);
		$this->users->method('get')->willReturn($user);
		$this->groups->method('isInGroup')->willReturn(true);
		$this->group->method('inGroup')->willReturn(true);
		$this->group->expects($this->once())->method('removeUser')->with($user);
		$this->wraps->expects($this->once())->method('deleteForOfficer')->with('ghost');
		$this->svc->revoke('ghost', 'admin');
	}

	public function testRevokeUnknownUserStill404s(): void
	{
		$this->officers->method('findByUid')->willReturn(null);
		$this->users->method('get')->willReturn(null);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->group->method('inGroup')->willReturn(false);
		$this->expectException(DomainException::class);
		$this->svc->revoke('nobody', 'admin');
	}
}
