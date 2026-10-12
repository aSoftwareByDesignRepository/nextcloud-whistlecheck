<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Crypto\CaseCodeService;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Db\IdemKey;
use OCA\WhistleCheck\Db\IdemKeyMapper;
use OCA\WhistleCheck\Db\Wattachment;
use OCA\WhistleCheck\Db\WattachmentMapper;
use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Db\Wmessage;
use OCA\WhistleCheck\Db\WmessageMapper;
use OCA\WhistleCheck\Db\Wnote;
use OCA\WhistleCheck\Db\WnoteMapper;
use OCA\WhistleCheck\Exception\ConflictException;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Case lifecycle engine (spec §5–§8).
 *
 * Security invariants enforced here, centrally:
 *
 *  - two-phase access: params(hint) → verify(hint+verifier). Both phases return
 *    IDENTICAL payloads for unknown/valid codes — pseudo-salts keep shapes the
 *    same, the only difference is a real vs fake decryption setup that fails
 *    client-side on a wrong code anyway. No oracle.
 *  - CAS on `revision` for every mutation (status, assign, hold, close).
 *  - Two-person close (MH-05/NH-05): close request records requester; second
 *    distinct officer confirms. One officer cannot close alone.
 *  - Automatic receipt: `message.officer` row created in the same transaction
 *    as intake (MH-04).
 *  - Idempotent submit via unique `ikey`; unique-violation → return existing.
 *  - Content is only ever stored as validated envelopes; plaintext exists only
 *    transiently on the honest no-JS path.
 */
class CaseService
{
	/** Cipher JSON bound for a single message (text + overhead; attachments are separate). */
	public const MAX_MESSAGE_CIPHER_BYTES = 2 * 1024 * 1024;

	/** Max attachments per submission (each blob is separately size-capped). */
	public const MAX_ATTACHMENTS = 10;

	/** Spec §9 state machine. OVERDUE is computed, never stored. */
	public const STATUS = [
		Wcase::STATUS_RECEIVED,
		Wcase::STATUS_IN_REVIEW,
		Wcase::STATUS_ACTION_TAKEN,
		Wcase::STATUS_CLOSED,
		Wcase::STATUS_ARCHIVED,
	];
	private const TRANSITIONS = [
		Wcase::STATUS_RECEIVED => [Wcase::STATUS_IN_REVIEW, Wcase::STATUS_CLOSED, Wcase::STATUS_ARCHIVED],
		Wcase::STATUS_IN_REVIEW => [Wcase::STATUS_ACTION_TAKEN, Wcase::STATUS_CLOSED, Wcase::STATUS_ARCHIVED],
		Wcase::STATUS_ACTION_TAKEN => [Wcase::STATUS_CLOSED, Wcase::STATUS_ARCHIVED],
		Wcase::STATUS_CLOSED => [Wcase::STATUS_IN_REVIEW, Wcase::STATUS_ARCHIVED], // reopen, audited
		Wcase::STATUS_ARCHIVED => [],                                            // terminal
	];

	public function __construct(
		private readonly WcaseMapper $cases,
		private readonly WmessageMapper $messages,
		private readonly WattachmentMapper $attachments,
		private readonly WnoteMapper $notes,
		private readonly IdemKeyMapper $idem,
		private readonly CategoryMapper $categories,
		private readonly CaseCodeService $codes,
		private readonly EnvelopeService $envelope,
		private readonly DeadlineService $deadlines,
		private readonly ConfigService $config,
		private readonly AttachmentService $attachmentPolicy,
		private readonly OfficerKeyService $officerKeys,
		private readonly AuditService $audit,
		private readonly NotificationService $notify,
		private readonly SanitizeService $sanitize,
		private readonly IDBConnection $db,
		private readonly IUserManager $userManager,
		private readonly IConfig $config0,
	) {
	}

	/**
	 * Phase 1 — params. Identical payload whether or not the hint exists;
	 * unknown hints get deterministic pseudo-salts so client-side argon2 runs
	 * either way and response shape/bytes are the same.
	 *
	 * @return array{salt:string,key_salt:string}
	 * @throws ValidationException
	 */
	public function params(string $code): array
	{
		$bytes = $this->codes->decodeLoose($code);
		if ($bytes === null) {
			throw new ValidationException('invalid', 'Invalid request', ['code' => 'invalid']);
		}
		return $this->paramsByHint($this->codes->lookupHint($bytes));
	}

	/**
	 * JS path: lookup by sha256 hint only — the raw code never crosses the wire.
	 *
	 * @return array{salt:string,key_salt:string}
	 */
	public function paramsByHint(string $hint): array
	{
		if (!ctype_xdigit($hint) || strlen($hint) !== 64) {
			throw new ValidationException('invalid', 'Invalid request', ['code' => 'invalid']);
		}
		$hint = strtolower($hint);
		$case = $this->cases->findByHint($hint);
		if ($case === null) {
			$secret = $this->instanceSecret();
			return [
				'salt' => $this->codes->pseudoSalt($hint, $secret),
				'key_salt' => $this->codes->pseudoSalt($hint . '|k', $secret),
			];
		}
		return ['salt' => $case->getCodeSalt(), 'key_salt' => $case->getKeySalt()];
	}

	/**
	 * Phase 2 — verify + fetch. Uniform INVALID_OR_EXPIRED on miss or mismatch;
	 * identical 401 either way (spec US-002 AC-5).
	 *
	 * @return array<string,mixed>
	 * @throws DomainException
	 */
	public function open(string $code, string $verifierHex): array
	{
		$bytes = $this->codes->decodeLoose($code);
		if ($bytes === null) {
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
		return $this->openByHint($this->codes->lookupHint($bytes), $verifierHex);
	}

	/**
	 * JS path — caller authenticates with hint + verifier; the raw code stays
	 * client-side (NN-09: the server must not hold reporter key material).
	 *
	 * @return array<string,mixed>
	 * @throws DomainException
	 */
	public function openByHint(string $hint, string $verifierHex): array
	{
		if (!ctype_xdigit($hint) || strlen($hint) !== 64 || !ctype_xdigit($verifierHex)) {
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
		$case = $this->cases->findByHint(strtolower($hint));
		if ($case === null || !$this->codes->verify($verifierHex, strtolower($case->getCodeHash()))) {
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
		return $this->publicView($case);
	}

	/**
	 * JS submit (E2E). The code never arrives — only hint + argon2 verifier.
	 * Idempotent on `ikey`.
	 *
	 * @param array<string,mixed> $in
	 * @return array{case_id:int,revision:int}
	 * @throws ValidationException|DomainException
	 */
	public function submit(array $in, bool $e2e): array
	{
		$ikey = is_string($in['ikey'] ?? null) ? $in['ikey'] : '';
		if (preg_match('/^[A-Za-z0-9\-_]{8,128}$/', $ikey) !== 1) {
			throw new ValidationException('invalid', 'Missing idempotency key', ['ikey' => 'required']);
		}
		$existing = $this->idem->findByKey($ikey);
		if ($existing !== null) {
			return ['case_id' => $existing->getCaseId(), 'revision' => 0];
		}

		$hint = (string)($in['hint'] ?? '');
		$verifier = (string)($in['verifier'] ?? '');
		$codeSalt = (string)($in['code_salt'] ?? '');
		$keySalt = (string)($in['key_salt'] ?? '');
		$reporterWrap = (string)($in['reporter_wrap'] ?? '');
		$titleCipher = (string)($in['title_cipher'] ?? '');
		$bodyCipher = (string)($in['body'] ?? '');
		$contactCipher = (string)($in['contact_cipher'] ?? '');
		$wraps = $in['wraps'] ?? null;
		$categoryId = $in['category_id'] ?? null;

		$fields = [];
		if (!ctype_xdigit($hint) || strlen($hint) !== 64) {
			$fields['body'] = 'invalid submission';
		}
		if (!ctype_xdigit($verifier) || strlen($verifier) !== 64) {
			$fields['body'] = 'invalid submission';
		}
		foreach (['code_salt' => $codeSalt, 'key_salt' => $keySalt] as $n => $s) {
			if (!ctype_xdigit($s) || strlen($s) !== 32) {
				$fields['body'] = 'invalid submission';
			}
		}
		if (!$this->envelope->isValidEnvelope($bodyCipher, EnvelopeService::ALG_SECRETBOX)
			&& !$this->envelope->isValidEnvelope($bodyCipher, EnvelopeService::ALG_CHUNKED)) {
			$fields['body'] = 'required';
		}
		if ($titleCipher !== '' && !$this->envelope->isValidEnvelope($titleCipher)) {
			$fields['title'] = 'invalid';
		}
		if ($contactCipher !== '' && !$this->envelope->isValidEnvelope($contactCipher)) {
			$fields['contact'] = 'invalid';
		}
		if (!$this->envelope->isValidEnvelope($reporterWrap, EnvelopeService::ALG_SECRETBOX)) {
			$fields['body'] = 'invalid submission';
		}
		if (!is_array($wraps) || $wraps === []) {
			$fields['body'] = 'invalid submission';
		}
		if ($categoryId !== null) {
			$cat = is_int($categoryId) ? $this->categories->find($categoryId) : null;
			if ($cat === null || $cat->getArchived() !== 0) {
				$fields['category'] = 'invalid';
			}
		}
		if ($fields !== []) {
			throw new ValidationException('invalid', 'Invalid submission', $fields);
		}

		$attachmentsIn = is_array($in['attachments'] ?? null) ? $in['attachments'] : [];
		if (count($attachmentsIn) > self::MAX_ATTACHMENTS) {
			throw new ValidationException('invalid', 'Too many attachments', ['attachments' => 'too_many']);
		}
		foreach ($attachmentsIn as $att) {
			$this->attachmentPolicy->validateCipher(
				(string)($att['cipher'] ?? ''),
				(string)($att['mime'] ?? '')
			);
		}

		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		$due = $this->deadlines->feedbackDue($now);

		try {
			$this->db->beginTransaction();
			$case = new Wcase();
			$case->setLookupHint($hint);
			$case->setCodeHash(strtolower(hash('sha256', $verifier)));
			$case->setCodeSalt($codeSalt);
			$case->setKeySalt($keySalt);
			$case->setReporterWrap($reporterWrap);
			$case->setTitleCipher($titleCipher !== '' ? $titleCipher : null);
			$case->setContactCipher($contactCipher !== '' ? $contactCipher : null);
			$case->setCategoryId(is_int($categoryId) ? $categoryId : null);
			$case->setStatus(Wcase::STATUS_RECEIVED);
			$case->setLegalHold(0);
			$case->setReceivedAt($now);
			$case->setFeedbackDue($due);
			// retention_until is set at close time: closed_at + retention (spec §9).
			$case->setRetentionUntil(null);
			$case->setCreatedE2e($e2e ? 1 : 0);
			$case->setRevision(0);
			$case = $this->cases->insert($case);

			$body = new Wmessage();
			$body->setCaseId($case->getId());
			$body->setDirection('reporter');
			$body->setCipher($bodyCipher);
			$body->setAuthorUid(null);
			$body->setCreatedAt($now);
			$this->messages->insert($body);

			foreach ($attachmentsIn as $att) {
				$a = new Wattachment();
				$a->setCaseId($case->getId());
				$a->setMessageId(null);
				$a->setCipher((string)$att['cipher']);
				$a->setNameCipher(isset($att['name_cipher']) ? (string)$att['name_cipher'] : null);
				$a->setSize(strlen((string)$att['cipher']));
				$a->setMimeClaimed($this->sanitize->filename((string)($att['mime'] ?? 'application/octet-stream')));
				$a->setCreatedAt($now);
				$this->attachments->insert($a);
			}

			// Automatic receipt confirmation — same transaction (MH-04).
			$receipt = new Wmessage();
			$receipt->setCaseId($case->getId());
			$receipt->setDirection('officer');
			$receipt->setCipher((string)($in['receipt_cipher'] ?? $bodyCipher));
			$receipt->setAuthorUid('system');
			$receipt->setCreatedAt($now);
			$this->messages->insert($receipt);

			$this->officerKeys->storeWraps($case->getId(), $wraps, null);

			$ik = new IdemKey();
			$ik->setIkey($ikey);
			$ik->setCaseId($case->getId());
			$ik->setCreatedAt($now);
			$this->idem->insert($ik);

			$this->audit->record('case_submitted', null, $case->getId(), ['mode' => $e2e ? 'e2e' : 'nojs']);
			$this->db->commit();
		} catch (DbException $e) {
			$this->db->rollBack();
			// Unique-violation on ikey or hint → return the existing case (replay-safe).
			$again = $this->idem->findByKey($ikey);
			if ($again !== null) {
				return ['case_id' => $again->getCaseId(), 'revision' => 0];
			}
			throw new DomainException('submission_failed', 'Submission failed', 500);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->notify->notifyOfficers('wchk.new_report', $case->getId());
		return ['case_id' => $case->getId(), 'revision' => $case->getRevision()];
	}

	/**
	 * Reporter follow-up: verify code+verifier, then append. Same uniform
	 * failure as open() — no oracle for code existence.
	 *
	 * @throws DomainException
	 */
	public function replyAsReporter(string $code, string $verifierHex, string $cipher): int
	{
		$bytes = $this->codes->decodeLoose($code);
		if ($bytes === null) {
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
		return $this->replyAsReporterByHint($this->codes->lookupHint($bytes), $verifierHex, $cipher);
	}

	/**
	 * JS path — hint + verifier only, raw code never transmitted.
	 *
	 * @throws DomainException
	 */
	public function replyAsReporterByHint(string $hint, string $verifierHex, string $cipher): int
	{
		if (!ctype_xdigit($hint) || strlen($hint) !== 64 || !ctype_xdigit($verifierHex)) {
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
		$case = $this->cases->findByHint(strtolower($hint));
		if ($case === null || !$this->codes->verify($verifierHex, strtolower($case->getCodeHash()))) {
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
		$this->reply($case->getId(), 'reporter', $cipher, null, null);
		return $case->getId();
	}

	/**
	 * Officer adds an encrypted reply; reporter adds a follow-up. CAS via case
	 * revision when caller supplies one (officers must; reporters use their
	 * verified session-free flow without revision).
	 *
	 * @throws DomainException|ValidationException|ConflictException
	 */
	public function reply(int $caseId, string $direction, string $cipher, ?string $authorUid, ?int $expectedRevision): Wcase
	{
		$case = $this->cases->find($caseId);
		if ($case === null) {
			throw new DomainException('not_found', 'Not found', 404);
		}
		// Reporter side is read-only once the procedure ended (spec US-002 AC-4).
		if ($case->isTerminal()) {
			throw new DomainException('case_closed', 'Case closed', 409);
		}
		if (!in_array($direction, ['reporter', 'officer'], true)
			|| !$this->envelope->isValidEnvelope($cipher)) {
			throw new ValidationException('invalid', 'Invalid message', ['body' => 'invalid']);
		}
		if ($direction === 'officer' && $expectedRevision === null) {
			throw new ConflictException(); // officer replies carry CAS (fail closed)
		}
		if ($expectedRevision !== null && $case->getRevision() !== $expectedRevision) {
			throw new ConflictException();
		}

		if (strlen($cipher) > self::MAX_MESSAGE_CIPHER_BYTES) {
			throw new ValidationException('invalid', 'Message too large', ['body' => 'too_large']);
		}

		// Insert + CAS persist in ONE transaction — a lost CAS race must not
		// leave a committed message the caller was told failed (orphan bug).
		$this->db->beginTransaction();
		try {
			$now = new \DateTime('now', new \DateTimeZone('UTC'));
			$msg = new Wmessage();
			$msg->setCaseId($caseId);
			$msg->setDirection($direction);
			$msg->setCipher($cipher);
			$msg->setAuthorUid($authorUid);
			$msg->setCreatedAt($now);
			$this->messages->insert($msg);

			// First officer reply satisfies the 3-month feedback clock (spec US-005).
			if ($direction === 'officer' && $case->getFeedbackSentAt() === null) {
				$case->setFeedbackSentAt($now);
			}
			if ($direction === 'officer' && $case->getStatus() === Wcase::STATUS_RECEIVED) {
				$case->setStatus(Wcase::STATUS_IN_REVIEW);
			}
			$this->persist($case, $case->getRevision());
			$this->audit->record('message_added', $authorUid, $caseId, ['direction' => $direction, 'message_id' => $msg->getId()]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $case;
	}

	/**
	 * Officer status transition — CAS, transition map enforced.
	 * Two-person close: `closed` requires a prior distinct requester (NH-05).
	 *
	 * @throws DomainException|ConflictException|ValidationException
	 */
	public function setStatus(int $caseId, string $to, int $revision, string $actorUid): Wcase
	{
		$case = $this->cases->find($caseId);
		if ($case === null) {
			throw new DomainException('not_found', 'Not found', 404);
		}
		if ($case->getRevision() !== $revision) {
			throw new ConflictException();
		}
		if (!in_array($to, self::STATUS, true)) {
			throw new ValidationException('invalid', 'Invalid status', ['status' => 'invalid']);
		}
		$from = $case->getStatus();
		if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
			throw new ValidationException('invalid', 'Invalid transition', ['status' => 'not allowed']);
		}
		$now = new \DateTime('now', new \DateTimeZone('UTC'));

		if ($to === Wcase::STATUS_CLOSED || $to === Wcase::STATUS_ARCHIVED) {
			// NH-05 two-person rule — only when ≥2 active officers exist; a
			// single-officer org must not be locked out of closing.
			$needsSecond = count($this->officerKeys->activePubkeys()) >= 2;
			if ($needsSecond) {
				$requester = $case->getCloseRequestedBy();
				// NULL target = legacy row from before close_requested_to existed;
				// the old confirm path always applied `closed`, so NULL maps to it.
				$pendingTo = $case->getCloseRequestedTo() ?? Wcase::STATUS_CLOSED;
				if ($requester !== null && $pendingTo === $from) {
					// Moot request: the case already reached the pending target
					// (e.g. legacy rows). Drop the stale marker — it could never
					// be confirmed because the transition is no longer legal.
					$case->setCloseRequestedBy(null);
					$case->setCloseRequestedTo(null);
					$requester = null;
				}
				if ($requester === null) {
					$case->setCloseRequestedBy($actorUid);
					$case->setCloseRequestedTo($to);
					$this->persist($case, $revision);
					$this->audit->record('close_requested', $actorUid, $caseId, ['to' => $to]);
					return $case;
				}
				if ($requester === $actorUid) {
					// The requester may revise their own pending request to a
					// different terminal target — it still needs second-officer
					// confirmation, and the change is audited. A repeated
					// identical request stays rejected.
					if ($pendingTo !== $to) {
						$case->setCloseRequestedTo($to);
						$this->persist($case, $revision);
						$this->audit->record('close_requested', $actorUid, $caseId, ['to' => $to, 'revised' => true]);
						return $case;
					}
					throw new DomainException('second_officer_required', 'A second officer must confirm', 409);
				}
				if ($pendingTo !== $to) {
					throw new DomainException('pending_mismatch', 'A different terminal transition is already pending', 409);
				}
			}
			$case->setStatus($to);
			$case->setClosedAt($now);
			$case->setClosedBy($actorUid);
			$case->setCloseRequestedBy(null);
			$case->setCloseRequestedTo(null);
			// Retention clock starts at closure (spec §9: retention_until = closed_at + N).
			$case->setRetentionUntil($this->deadlines->retentionUntil($now, $this->config->getInt('retention_months')));
			$this->persist($case, $revision);
			$this->audit->record($to === Wcase::STATUS_CLOSED ? 'case_closed' : 'case_archived', $actorUid, $caseId, ['from' => $from]);
			return $case;
		}

		// Reopen: closed → in_review clears the closure stamp but keeps history in audit.
		// Any stale pending terminal request must be cleared too — otherwise the
		// next officer requesting closed/archived would count as an instant
		// "second confirmation" of a request that no longer makes sense.
		if ($from === Wcase::STATUS_CLOSED && $to === Wcase::STATUS_IN_REVIEW) {
			$case->setClosedAt(null);
			$case->setClosedBy(null);
			$case->setRetentionUntil(null);
			$case->setCloseRequestedBy(null);
			$case->setCloseRequestedTo(null);
		}
		$case->setStatus($to);
		$this->persist($case, $revision);
		$this->audit->record('status_changed', $actorUid, $caseId, ['from' => $from, 'to' => $to]);
		return $case;
	}

	/**
	 * Assign a case — officer uids only, existence + membership both checked.
	 *
	 * @throws DomainException|ConflictException|ValidationException
	 */
	public function assign(int $caseId, ?string $targetUid, int $revision, string $actorUid): Wcase
	{
		$case = $this->cases->find($caseId);
		if ($case === null) {
			throw new DomainException('not_found', 'Not found', 404);
		}
		if ($case->getRevision() !== $revision) {
			throw new ConflictException();
		}
		if ($targetUid !== null) {
			// Ghost-uid guard: user must exist AND hold officer membership (§2).
			if (!$this->userManager->userExists($targetUid) || !$this->officerKeys->isOfficer($targetUid)) {
				throw new ValidationException('invalid', 'Invalid assignee', ['assignee' => 'not an officer']);
			}
		}
		$case->setAssignedTo($targetUid);
		$this->persist($case, $revision);
		$this->audit->record('case_assigned', $actorUid, $caseId, ['target_uid' => $targetUid ?? '']);
		return $case;
	}

	/**
	 * Legal hold toggle — halts retention deletion (never silently).
	 * `flag` must be an explicit boolean by the caller (no "false" strings here).
	 */
	public function setHold(int $caseId, bool $hold, ?string $reason, int $revision, string $actorUid): Wcase
	{
		$case = $this->cases->find($caseId);
		if ($case === null) {
			throw new DomainException('not_found', 'Not found', 404);
		}
		if ($case->getRevision() !== $revision) {
			throw new ConflictException();
		}
		$case->setLegalHold($hold ? 1 : 0);
		$case->setHoldReason($hold ? ($reason !== null ? $this->sanitize->plainText($reason, 255) : null) : null);
		$case->setHoldSetBy($hold ? $actorUid : null);
		$this->persist($case, $revision);
		$this->audit->record($hold ? 'hold_set' : 'hold_released', $actorUid, $caseId, ['flag' => $hold]);
		return $case;
	}

	/**
	 * Officer-side view: ciphertext + metadata for their own wrap. Reporter
	 * fields (contact cipher, reporter wrap) are included — officers decrypt.
	 *
	 * @return array<string,mixed>
	 */
	public function officerView(Wcase $case, string $uid): array
	{
		$msgs = array_map(fn (Wmessage $m) => [
			'id' => $m->getId(),
			'direction' => $m->getDirection(),
			'cipher' => $m->getCipher(),
			'author' => $m->getAuthorUid(),
			'at' => $m->getCreatedAt()->format(\DateTime::ATOM),
		], $this->messages->findForCase($case->getId()));
		$atts = array_map(fn (Wattachment $a) => [
			'id' => $a->getId(),
			'message_id' => $a->getMessageId(),
			'cipher' => $a->getCipher(),
			'name_cipher' => $a->getNameCipher(),
			'size' => $a->getSize(),
			'mime' => $a->getMimeClaimed(),
			'at' => $a->getCreatedAt()->format(\DateTime::ATOM),
		], $this->attachments->findForCase($case->getId()));
		$notes = array_map(fn (Wnote $n) => [
			'id' => $n->getId(),
			'cipher' => $n->getCipher(),
			'author' => $n->getAuthorUid(),
			'at' => $n->getCreatedAt()->format(\DateTime::ATOM),
		], $this->notes->findForCase($case->getId()));
		return [
			'id' => $case->getId(),
			'status' => $case->getStatus(),
			'revision' => $case->getRevision(),
			'assigned_to' => $case->getAssignedTo(),
			'close_requested_by' => $case->getCloseRequestedBy(),
			'close_requested_to' => $case->getCloseRequestedTo(),
			'legal_hold' => $case->getLegalHold() === 1,
			'hold_reason' => $case->getHoldReason(),
			'received_at' => $case->getReceivedAt()->format(\DateTime::ATOM),
			'feedback_due' => $case->getFeedbackDue()->format('Y-m-d'),
			'retention_until' => $case->getRetentionUntil()?->format('Y-m-d'),
			'category_id' => $case->getCategoryId(),
			'created_e2e' => $case->getCreatedE2e() === 1,
			'title_cipher' => $case->getTitleCipher(),
			'contact_cipher' => $case->getContactCipher(),
			'messages' => $msgs,
			'attachments' => $atts,
			'notes' => $notes,
		];
	}

	/**
	 * Reporter-side view: only what the code-bearer may see. No notes, no
	 * officer identities beyond 'officer', no internal fields.
	 *
	 * @return array<string,mixed>
	 */
	public function publicView(Wcase $case): array
	{
		$msgs = array_map(fn (Wmessage $m) => [
			'direction' => $m->getDirection(),
			'cipher' => $m->getCipher(),
			'at' => $m->getCreatedAt()->format(\DateTime::ATOM),
		], $this->messages->findForCase($case->getId()));
		return [
			'status' => $case->getStatus(),
			'received_at' => $case->getReceivedAt()->format(\DateTime::ATOM),
			'feedback_due' => $case->getFeedbackDue()->format('Y-m-d'),
			'readonly' => $case->isTerminal(),
			'reporter_wrap' => $case->getReporterWrap(),
			'key_salt' => $case->getKeySalt(),
			'messages' => $msgs,
		];
	}

	/**
	 * Fail-closed optimistic-lock write: the WHERE clause pins the revision the
	 * caller checked, so a concurrent mutation turns into a 409 instead of a
	 * silent last-writer-wins overwrite.
	 */
	private function persist(Wcase $case, int $expectedRevision): void
	{
		if ($this->cases->updateWithRevision($case, $expectedRevision) === 0) {
			throw new ConflictException();
		}
	}

	/**
	 * Second officer confirms whatever terminal transition was requested —
	 * the stored target, never a hardcoded one.
	 */
	public function confirmClose(int $caseId, int $revision, string $actorUid): Wcase
	{
		$case = $this->cases->find($caseId);
		if ($case === null) {
			throw new DomainException('not_found', 'Not found', 404);
		}
		$requester = $case->getCloseRequestedBy();
		if ($requester === null) {
			throw new DomainException('nothing_pending', 'No close request pending', 409);
		}
		if ($requester === $actorUid) {
			throw new DomainException('second_officer_required', 'A second officer must confirm', 409);
		}
		// Legacy pending rows (pre-`close_requested_to`) targeted `closed`.
		$target = $case->getCloseRequestedTo() ?? Wcase::STATUS_CLOSED;
		if ($target === $case->getStatus()) {
			// Moot pending request — already in the target state; clear the
			// stale marker so the case isn't wedged.
			$case->setCloseRequestedBy(null);
			$case->setCloseRequestedTo(null);
			$this->persist($case, $revision);
			return $case;
		}
		return $this->setStatus($caseId, $target, $revision, $actorUid);
	}

	private function instanceSecret(): string
	{
		$s = $this->config0->getSystemValueString('secret', '');
		return $s !== '' ? $s : 'wchk-fallback-secret';
	}
}
