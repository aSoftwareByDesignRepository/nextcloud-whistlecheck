<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Db\CaseKeyWrapMapper;
use OCA\WhistleCheck\Db\Officer;
use OCA\WhistleCheck\Db\OfficerMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Officer key lifecycle (spec US-007 / MH-09).
 *
 * The server only ever sees X25519 PUBLIC keys. Private keys live
 * passphrase-wrapped in the officer's browser. Revocation bumps status and
 * triggers the re-wrap ceremony: remaining officers re-seal each case content
 * key to the surviving set (never to the revoked key).
 */
class OfficerKeyService
{
	public const GROUP = 'whistlecheck_officers';

	public function __construct(
		private readonly OfficerMapper $officers,
		private readonly CaseKeyWrapMapper $wraps,
		private readonly EnvelopeService $envelope,
		private readonly AuditService $audit,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly \OCP\IDBConnection $db,
	) {
	}

	public function isOfficer(?string $uid): bool
	{
		if ($uid === null || $uid === '') {
			return false;
		}
		return $this->groupManager->isInGroup($uid, self::GROUP);
	}

	/**
	 * First-time key registration. Re-registering replaces the pubkey only when
	 * the caller proves they can still hold a valid officer membership — a
	 * stolen-session key-swap is prevented by requiring an existing officer to
	 * rotate via explicit ceremony (re-wrap all their grants) rather than a
	 * silent overwrite.
	 *
	 * @throws DomainException
	 */
	/**
	 * @param array<int,string> $selfWraps case_id → sealed-box envelope of the
	 *   case key to the NEW pubkey. Allows atomic rotation+re-wrap: without it
	 *   a rotation wipes the officer's wraps and orphans every case they held
	 *   until another wrap-holder re-grants (or forever, if none exists).
	 *   Self-wraps may only name cases the officer already holds — a wrap row
	 *   is the authorization oracle, so minting one for a foreign case would
	 *   be a blind-write privilege escalation.
	 */
	public function register(string $uid, string $pubkeyB64, ?string $actorUid, array $selfWraps = []): Officer
	{
		if (!$this->isOfficer($uid)) {
			throw new DomainException('not_officer', 'Not a case officer', 403);
		}
		if (base64_decode($pubkeyB64, true) === false
			|| strlen((string)base64_decode($pubkeyB64, true)) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			throw new ValidationException('invalid_pubkey', 'Invalid public key', ['pubkey' => 'invalid key']);
		}
		$fp = $this->envelope->pubkeyFingerprint($pubkeyB64);

		// Snapshot held case ids BEFORE any wipe: a self-wrap may only
		// re-cover a case the officer provably held under the old key —
		// and on first registration the held set is empty, so wraps are
		// rejected there too rather than silently ignored.
		$held = [];
		foreach ($this->wraps->findForOfficer($uid) as $w) {
			$held[$w->getCaseId()] = true;
		}
		foreach ($selfWraps as $caseId => $envJson) {
			if (!isset($held[(int)$caseId])) {
				throw new ValidationException('invalid_wrap', 'Cannot re-wrap a case you do not hold', ['wraps' => 'foreign case']);
			}
			if (!is_string($envJson) || !$this->envelope->isValidEnvelope($envJson, EnvelopeService::ALG_SEALEDBOX)) {
				throw new ValidationException('invalid_wrap', 'Invalid wrapped key', ['wraps' => 'invalid wrap']);
			}
		}

		$existing = $this->officers->findByUid($uid);
		if ($existing !== null && $existing->getStatus() === Officer::STATUS_ACTIVE) {
			if ($existing->getPubkey() === $pubkeyB64) {
				return $existing; // idempotent re-register — NOT a rotation
			}
			// Atomic: rotate + wipe + re-wrap in one commit — a failure
			// mid-ceremony can no longer leave the officer wrapless.
			$this->db->beginTransaction();
			try {
				$existing->setPubkey($pubkeyB64);
				$existing->setFingerprint($fp);
				$existing->setKeyVersion($existing->getKeyVersion() + 1);
				$this->officers->update($existing);
				$this->wraps->deleteForOfficer($uid);
				foreach ($selfWraps as $caseId => $envJson) {
					$wrap = new \OCA\WhistleCheck\Db\CaseKeyWrap();
					$wrap->setCaseId((int)$caseId);
					$wrap->setOfficerUid($uid);
					$wrap->setWrappedKey($envJson);
					$wrap->setGrantedBy($uid);
					$wrap->setGrantedAt(new \DateTime('now', new \DateTimeZone('UTC')));
					$this->wraps->insert($wrap);
				}
				$this->audit->record('officer_key_rotated', $uid, null, ['uid' => $uid, 'version' => $existing->getKeyVersion(), 'count' => count($selfWraps)]);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $existing;
		}

		$officer = $existing ?? new Officer();
		$officer->setUid($uid);
		$officer->setPubkey($pubkeyB64);
		$officer->setFingerprint($fp);
		$officer->setStatus(Officer::STATUS_ACTIVE);
		$officer->setKeyVersion(1);
		$officer->setCreatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
		$officer->setRevokedAt(null);
		$officer->setRevokedBy(null);
		if ($existing === null) {
			$officer = $this->officers->insert($officer);
		} else {
			$this->officers->update($officer);
		}
		$this->audit->record('officer_key_registered', $actorUid ?? $uid, null, ['uid' => $uid]);
		return $officer;
	}

	/**
	 * Revoke: mark revoked + delete all case key wraps for that officer.
	 * Clients then run the re-wrap ceremony for every case they hold — the
	 * server re-issues wrap slots only for ACTIVE officers.
	 *
	 * @throws DomainException
	 */
	public function revoke(string $targetUid, string $actorUid): void
	{
		$target = $this->officers->findByUid($targetUid);
		$inGroup = $this->isOfficer($targetUid);
		// A group member who never registered has no row — but they still have
		// full officer metadata access, so revoke must still eject them.
		if ((!$inGroup) && ($target === null || $target->getStatus() === Officer::STATUS_REVOKED)) {
			throw new DomainException('not_found', 'Not found', 404);
		}
		// Revoked + de-wrapped in one commit — a split failure must never
		// leave a "revoked" officer with live wraps (still decrypts) or a
		// de-wrapped officer still marked active (access without reads).
		$this->db->beginTransaction();
		try {
			if ($target !== null && $target->getStatus() !== Officer::STATUS_REVOKED) {
				$now = new \DateTime('now', new \DateTimeZone('UTC'));
				$target->setStatus(Officer::STATUS_REVOKED);
				$target->setRevokedAt($now);
				$target->setRevokedBy($actorUid);
				$this->officers->update($target);
			}
			$this->wraps->deleteForOfficer($targetUid);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Eject from the officer group — `isOfficer` is the group's only gate,
		// so without this a revoked officer keeps full API access and could
		// even re-register a key to revive themselves.
		$group = $this->groupManager->get(self::GROUP);
		$user = $this->userManager->get($targetUid);
		if ($group !== null && $user !== null && $group->inGroup($user)) {
			$group->removeUser($user);
		}
		$this->audit->record('officer_revoked', $actorUid, null, ['target_uid' => $targetUid]);
	}

	/**
	 * @return list<array{uid:string,pubkey:string,fingerprint:string}> active officers
	 */
	public function activePubkeys(): array
	{
		return array_map(static fn (Officer $o) => [
			'uid' => $o->getUid(),
			'pubkey' => $o->getPubkey(),
			'fingerprint' => $o->getFingerprint(),
			// Embeds feed boot-data lists where clients branch on status
			// (officers page decides setup-vs-rotate on it). Tautological
			// here — findActive() only returns active — but the field must
			// exist or every consumer's status check silently misses.
			'status' => $o->getStatus(),
		], $this->officers->findActive());
	}

	/**
	 * Store client-produced wraps (content key sealed to each officer). Every
	 * entry must carry the `fp` (fingerprint) of the officer public key it
	 * was sealed to: the check runs as a FOR UPDATE read inside a
	 * transaction, so it serializes against a concurrent rotation's row
	 * UPDATE — a wrap sealed to a soon-dead key can never land (it would be
	 * a permanently unreadable case for that officer; sole-officer installs
	 * would lose the report entirely). Stale fp → 409 so the client
	 * re-fetches pubkeys, re-seals and retries.
	 *
	 * Wire forms: list of {uid, wrap, fp} or map uid => {wrap, fp}.
	 * Lock order is sorted by uid so two concurrent writes cannot deadlock.
	 *
	 * @throws ValidationException|DomainException
	 */
	public function storeWraps(int $caseId, array $wraps, ?string $grantedBy): void
	{
		$ownTx = !$this->db->inTransaction();
		if ($ownTx) {
			$this->db->beginTransaction();
		}
		try {
			$this->storeWrapsLocked($caseId, $wraps, $grantedBy);
			if ($ownTx) {
				$this->db->commit();
			}
		} catch (\Throwable $e) {
			if ($ownTx) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	private function storeWrapsLocked(int $caseId, array $wraps, ?string $grantedBy): void
	{
		// Grantor invariant — a re-wrap may only be minted by an officer who
		// holds a wrap on this case. Checked inside the transaction so a
		// concurrent revoke cannot interleave between check and insert.
		// The initial anonymous submit passes $grantedBy=null: the reporter
		// holds nothing, the invariant does not apply.
		if ($grantedBy !== null
			&& $this->wraps->findForCaseAndOfficer($caseId, $grantedBy) === null) {
			throw new DomainException('not_a_holder', 'You do not hold a wrap for this case', 403);
		}

		// Normalize wire forms to uid => {wrap, fp}.
		$wrapsByUid = [];
		foreach ($wraps as $key => $entry) {
			if (is_int($key) && is_array($entry)) {
				$uid = (string)($entry['uid'] ?? '');
				$wrapsByUid[$uid] = ['wrap' => $entry['wrap'] ?? null, 'fp' => $entry['fp'] ?? null];
			} else {
				$wrapsByUid[(string)$key] = is_array($entry)
					? ['wrap' => $entry['wrap'] ?? null, 'fp' => $entry['fp'] ?? null]
					: ['wrap' => $entry, 'fp' => null];
			}
		}
		ksort($wrapsByUid, SORT_STRING); // deterministic lock order
		foreach ($wrapsByUid as $uid => $entry) {
			$envelopeJson = $entry['wrap'];
			$fp = $entry['fp'];
			// FOR UPDATE: sees the post-rotation fingerprint even when this
			// transaction's consistent snapshot predates the rotation commit.
			$officer = $this->officers->findByUidForUpdate((string)$uid);
			if ($officer === null || $officer->getStatus() !== Officer::STATUS_ACTIVE) {
				throw new ValidationException('invalid_wrap', 'Wrap for unknown officer', ['officers' => 'unknown officer']);
			}
			if (!is_string($fp) || $fp === '') {
				throw new ValidationException('invalid_wrap', 'Missing key fingerprint', ['officers' => 'missing fingerprint']);
			}
			if (!hash_equals($officer->getFingerprint(), $fp)) {
				throw new DomainException('stale_officer_key', 'Officer key changed — refresh and retry', 409);
			}
			if (!is_string($envelopeJson) || !$this->envelope->isValidEnvelope($envelopeJson, EnvelopeService::ALG_SEALEDBOX)) {
				throw new ValidationException('invalid_wrap', 'Invalid wrapped key', ['officers' => 'invalid wrap']);
			}
		}
		foreach ($wrapsByUid as $uid => $entry) {
			$existing = $this->wraps->findForCaseAndOfficer($caseId, (string)$uid);
			if ($existing !== null) {
				// Never clobber a live wrap: the server cannot verify sealed-box
				// contents, so an overwrite slot is an insider denial-of-access
				// hole. Rotation frees slots by deleting stale wraps first
				// (see register()).
				$this->audit->record('wrap_overwrite_rejected', $grantedBy, $caseId, ['target_uid' => (string)$uid]);
				throw new ValidationException('wrap_exists', 'Officer already holds a wrap', ['officers' => 'wrap exists']);
			}
			$wrap = new \OCA\WhistleCheck\Db\CaseKeyWrap();
			$wrap->setCaseId($caseId);
			$wrap->setOfficerUid((string)$uid);
			$wrap->setWrappedKey($entry['wrap']);
			$wrap->setGrantedBy($grantedBy);
			$wrap->setGrantedAt(new \DateTime('now', new \DateTimeZone('UTC')));
			$this->wraps->insert($wrap);
		}
	}
}
