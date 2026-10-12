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
	public function register(string $uid, string $pubkeyB64, ?string $actorUid): Officer
	{
		if (!$this->isOfficer($uid)) {
			throw new DomainException('not_officer', 'Not a case officer', 403);
		}
		if (base64_decode($pubkeyB64, true) === false
			|| strlen((string)base64_decode($pubkeyB64, true)) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			throw new ValidationException('invalid_pubkey', 'Invalid public key', ['pubkey' => 'invalid key']);
		}
		$fp = $this->envelope->pubkeyFingerprint($pubkeyB64);

		$existing = $this->officers->findByUid($uid);
		if ($existing !== null && $existing->getStatus() === Officer::STATUS_ACTIVE) {
			if ($existing->getPubkey() === $pubkeyB64) {
				return $existing; // idempotent re-register — NOT a rotation
			}
			// Real rotation: new key version + wipe every wrap sealed to the
			// old pubkey — they are undecryptable dead weight, and removing
			// them makes re-wrap grants pure inserts (no clobber path).
			$existing->setPubkey($pubkeyB64);
			$existing->setFingerprint($fp);
			$existing->setKeyVersion($existing->getKeyVersion() + 1);
			$this->officers->update($existing);
			$this->wraps->deleteForOfficer($uid);
			$this->audit->record('officer_key_rotated', $uid, null, ['uid' => $uid, 'version' => $existing->getKeyVersion()]);
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
		if ($target !== null && $target->getStatus() !== Officer::STATUS_REVOKED) {
			$now = new \DateTime('now', new \DateTimeZone('UTC'));
			$target->setStatus(Officer::STATUS_REVOKED);
			$target->setRevokedAt($now);
			$target->setRevokedBy($actorUid);
			$this->officers->update($target);
		}
		$this->wraps->deleteForOfficer($targetUid);
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
		], $this->officers->findActive());
	}

	/**
	 * Store client-produced wraps (content key sealed to each officer). Only
	 * uids in the ACTIVE set are accepted — anything else is rejected outright
	 * so a malicious client cannot smuggle a wrap to a revoked/foreign uid.
	 *
	 * @param array<string,string> $wrapsByUid uid → sealed-box envelope JSON
	 * @throws ValidationException
	 */
	public function storeWraps(int $caseId, array $wraps, ?string $grantedBy): void
	{
		// Wire form is a list of {uid, wrap}; normalize to uid => envelope.
		$wrapsByUid = [];
		foreach ($wraps as $key => $entry) {
			if (is_int($key) && is_array($entry)) {
				$uid = (string)($entry['uid'] ?? '');
				$wrapsByUid[$uid] = $entry['wrap'] ?? null;
			} else {
				$wrapsByUid[(string)$key] = $entry;
			}
		}
		$active = array_column($this->activePubkeys(), 'uid');
		foreach ($wrapsByUid as $uid => $envelopeJson) {
			if (!in_array($uid, $active, true)) {
				throw new ValidationException('invalid_wrap', 'Wrap for unknown officer', ['officers' => 'unknown officer']);
			}
			if (!is_string($envelopeJson) || !$this->envelope->isValidEnvelope($envelopeJson, EnvelopeService::ALG_SEALEDBOX)) {
				throw new ValidationException('invalid_wrap', 'Invalid wrapped key', ['officers' => 'invalid wrap']);
			}
		}
		foreach ($wrapsByUid as $uid => $envelopeJson) {
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
			$wrap->setWrappedKey($envelopeJson);
			$wrap->setGrantedBy($grantedBy);
			$wrap->setGrantedAt(new \DateTime('now', new \DateTimeZone('UTC')));
			$this->wraps->insert($wrap);
		}
	}
}
