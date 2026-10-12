<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Controller;

use OCA\WhistleCheck\Db\AuditEventMapper;
use OCA\WhistleCheck\Db\CaseKeyWrapMapper;
use OCA\WhistleCheck\Db\Category;
use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Db\Officer;
use OCA\WhistleCheck\Db\OfficerMapper;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Db\WnoteMapper;
use OCA\WhistleCheck\Db\WattachmentMapper;
use OCA\WhistleCheck\Db\WmessageMapper;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\CaseService;
use OCA\WhistleCheck\Service\CompanionService;
use OCA\WhistleCheck\Service\ConfigService;
use OCA\WhistleCheck\Service\DeadlineService;
use OCA\WhistleCheck\Service\EvidenceExportService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCA\WhistleCheck\Service\StatsService;
use OCA\WhistleCheck\Service\VerbalChannelService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Officer JSON API. Every endpoint requires an authenticated member of the
 * `whistlecheck_officers` group — checked once, centrally, in officerGuard().
 * Content is transported as ciphertext envelopes; the API never sees
 * plaintext reporter data.
 */
class ApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly OfficerKeyService $officerKeys,
		private readonly OfficerMapper $officers,
		private readonly CaseService $cases,
		private readonly WcaseMapper $caseMapper,
		private readonly WmessageMapper $messageMapper,
		private readonly WattachmentMapper $attachmentMapper,
		private readonly WnoteMapper $noteMapper,
		private readonly CaseKeyWrapMapper $wrapMapper,
		private readonly CategoryMapper $categoryMapper,
		private readonly AuditEventMapper $auditMapper,
		private readonly AuditService $audit,
		private readonly ConfigService $config,
		private readonly StatsService $stats,
		private readonly DeadlineService $deadlines,
		private readonly EvidenceExportService $export,
		private readonly CompanionService $companion,
		private readonly VerbalChannelService $verbal,
		private readonly EnvelopeService $envelope,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Central officer gate — uid + group membership (api-security §2 applied
	 * to the CALLER; targets get the same double check in services).
	 *
	 * @throws DomainException 403 when not an officer
	 */
	private function officerGuard(): string
	{
		$uid = $this->userSession->getUser()?->getUID();
		if (!$uid || !$this->officerKeys->isOfficer($uid)) {
			throw new DomainException('not_officer', 'Not a case officer', 403);
		}
		return $uid;
	}

	/**
	 * Case access boundary: a case exists for an officer iff they hold a
	 * case-key wrap for it (spec US-007 AC-2). Reads AND writes use this —
	 * an officer without a wrap must not even learn the case exists.
	 */
	private function caseForOfficer(int $id, string $uid): ?\OCA\WhistleCheck\Db\Wcase
	{
		$case = $this->caseMapper->find($id);
		if ($case === null
			|| $this->wrapMapper->findForCaseAndOfficer($id, $uid) === null) {
			return null;
		}
		return $case;
	}

	#[NoAdminRequired]
	public function inbox(): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$today = $this->deadlines->today();
			$out = [];
			foreach ($this->caseMapper->listInbox() as $case) {
				$wrap = $this->wrapMapper->findForCaseAndOfficer($case->getId(), $uid);
				if ($wrap === null) {
					continue; // US-007 AC-2: pre-grant cases are invisible, not "locked"
				}
				$out[] = [
					'id' => $case->getId(),
					'status' => $case->getStatus(),
					'overdue' => $case->isOverdue($today),
					'assigned_to' => $case->getAssignedTo(),
					'received_at' => $case->getReceivedAt()->format(\DateTime::ATOM),
					'feedback_due' => $case->getFeedbackDue()->format('Y-m-d'),
					'days_until_due' => $case->daysUntilDue($today),
					'legal_hold' => $case->getLegalHold() === 1,
					'close_requested_by' => $case->getCloseRequestedBy(),
				'close_requested_to' => $case->getCloseRequestedTo(),
					'category_id' => $case->getCategoryId(),
					// Officer's wrapped content key — they decrypt client-side.
					'wrap' => $wrap?->getWrappedKey(),
					'title_cipher' => $case->getTitleCipher(),
				];
			}
			return $this->ok(['cases' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function caseBundle(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$case = $this->caseMapper->find($id);
			if ($case === null) {
				return $this->notFound();
			}
			$wrap = $this->wrapMapper->findForCaseAndOfficer($id, $uid);
			if ($wrap === null) {
				// Same canonical 404 — an unwrapped case is indistinguishable
				// from absent to this officer (no "exists but locked" oracle).
				return $this->notFound();
			}
			$this->audit->record('case_opened', $uid, $id, []);
			$data = $this->cases->officerView($case, $uid);
			$data['wrap'] = $wrap->getWrappedKey();
			return $this->ok($data);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function postMessage(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$cipher = (string)$this->request->getParam('cipher', '');
			$revision = $this->intParam($this->request->getParam('revision'));
			if ($revision === null) {
				return $this->fail('CONFLICT', 'Missing revision', 409);
			}
			if ($this->caseForOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$case = $this->cases->reply($id, 'officer', $cipher, $uid, $revision);
			return $this->ok(['revision' => $case->getRevision()], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function setStatus(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$to = (string)$this->request->getParam('status', '');
			$revision = $this->intParam($this->request->getParam('revision'));
			if ($revision === null) {
				return $this->fail('CONFLICT', 'Missing revision', 409);
			}
			if ($this->caseForOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$case = $this->cases->setStatus($id, $to, $revision, $uid);
			return $this->ok([
				'status' => $case->getStatus(),
				'revision' => $case->getRevision(),
				'close_requested_by' => $case->getCloseRequestedBy(),
				'close_requested_to' => $case->getCloseRequestedTo(),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function assign(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$target = $this->request->getParam('uid');
			$revision = $this->intParam($this->request->getParam('revision'));
			if ($revision === null) {
				return $this->fail('CONFLICT', 'Missing revision', 409);
			}
			if ($this->caseForOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$case = $this->cases->assign($id, $target === null || $target === '' ? null : (string)$target, $revision, $uid);
			return $this->ok(['assigned_to' => $case->getAssignedTo(), 'revision' => $case->getRevision()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function setHold(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			// Explicit boolean parse — "false" is a string (api-security §4).
			$hold = $this->boolParam($this->request->getParam('hold'));
			$reason = $this->request->getParam('reason');
			$revision = $this->intParam($this->request->getParam('revision'));
			if ($revision === null) {
				return $this->fail('CONFLICT', 'Missing revision', 409);
			}
			if ($this->caseForOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$case = $this->cases->setHold($id, $hold, is_string($reason) ? $reason : null, $revision, $uid);
			return $this->ok(['legal_hold' => $case->getLegalHold() === 1, 'revision' => $case->getRevision()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function confirmClose(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$revision = $this->intParam($this->request->getParam('revision'));
			if ($revision === null) {
				return $this->fail('CONFLICT', 'Missing revision', 409);
			}
			if ($this->caseForOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$case = $this->cases->confirmClose($id, $revision, $uid);
			return $this->ok(['status' => $case->getStatus(), 'revision' => $case->getRevision()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function notes(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			if ($this->caseForOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$out = array_map(static fn ($n) => [
				'id' => $n->getId(),
				'cipher' => $n->getCipher(),
				'author' => $n->getAuthorUid(),
				'at' => $n->getCreatedAt()->format(\DateTime::ATOM),
			], $this->noteMapper->findForCase($id));
			return $this->ok(['notes' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function postNote(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			if ($this->caseForOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$cipher = (string)$this->request->getParam('cipher', '');
			if (strlen($cipher) > \OCA\WhistleCheck\Service\CaseService::MAX_MESSAGE_CIPHER_BYTES
				|| !$this->envelope->isValidEnvelope($cipher)) {
				throw new ValidationException('invalid', 'Invalid note', ['note' => 'invalid']);
			}
			$note = new \OCA\WhistleCheck\Db\Wnote();
			$note->setCaseId($id);
			$note->setCipher($cipher);
			$note->setAuthorUid($uid);
			$note->setCreatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
			$this->noteMapper->insert($note);
			$this->audit->record('note_added', $uid, $id, []);
			return $this->ok(['id' => $note->getId()], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteNote(int $id, int $noteId): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			if ($this->caseForOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$note = $this->noteMapper->find($noteId);
			if ($note === null || $note->getCaseId() !== $id || $note->getAuthorUid() !== $uid) {
				return $this->notFound(); // not found OR not yours — one payload
			}
			$this->noteMapper->delete($note);
			$this->audit->record('note_deleted', $uid, $id, []);
			return $this->ok([]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function auditExport(int $id): JSONResponse|DataDownloadResponse
	{
		try {
			$uid = $this->officerGuard();
			$case = $this->caseForOfficer($id, $uid);
			if ($case === null) {
				return $this->notFound();
			}
			$digests = [];
			foreach ($this->messageMapper->findForCase($id) as $m) {
				$digests[] = ['kind' => 'message', 'id' => $m->getId(), 'sha256' => hash('sha256', $m->getCipher())];
			}
			foreach ($this->attachmentMapper->findForCase($id) as $a) {
				$digests[] = ['kind' => 'attachment', 'id' => $a->getId(), 'sha256' => hash('sha256', $a->getCipher())];
			}
			foreach ($this->noteMapper->findForCase($id) as $n) {
				$digests[] = ['kind' => 'note', 'id' => $n->getId(), 'sha256' => hash('sha256', $n->getCipher())];
			}
			$built = $this->export->build($case, $digests, $uid);
			$this->audit->record('evidence_exported', $uid, $id, []);
			return new DataDownloadResponse($built['pdf'], 'whistlecheck-case-' . $id . '-evidence.pdf', 'application/pdf');
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function officers(): JSONResponse
	{
		try {
			$this->officerGuard();
			$out = array_map(static fn (Officer $o) => [
				'uid' => $o->getUid(),
				'fingerprint' => $o->getFingerprint(),
				'status' => $o->getStatus(),
				'key_version' => $o->getKeyVersion(),
				'created_at' => $o->getCreatedAt()->format(\DateTime::ATOM),
				'revoked_at' => $o->getRevokedAt()?->format(\DateTime::ATOM),
			], $this->officers->findAll());
			return $this->ok(['officers' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function registerKey(string $pubkey): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$officer = $this->officerKeys->register($uid, $pubkey, $uid);
			return $this->ok(['fingerprint' => $officer->getFingerprint(), 'key_version' => $officer->getKeyVersion()], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Re-wrap ceremony endpoint: body {"wraps": {"<caseId>:<uid>": envelope}}.
	 * Grantor must already hold a wrap on each case (proves they can re-wrap).
	 */
	#[NoAdminRequired]
	public function grantWraps(): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$wraps = $this->request->getParam('wraps');
			if (!is_array($wraps)) {
				throw new ValidationException('invalid', 'Missing wraps', ['wraps' => 'required']);
			}
			$granted = 0;
			foreach ($wraps as $key => $envelopeJson) {
				if (!is_string($key) || !is_string($envelopeJson) || !str_contains($key, ':')) {
					continue;
				}
				[$caseIdStr, $targetUid] = explode(':', $key, 2);
				$caseId = $this->intParam($caseIdStr);
				if ($caseId === null) {
					continue;
				}
				// Grantor must hold a wrap on the case — this is the
				// re-wrap authorization invariant (prevents foreign grants).
				if ($this->wrapMapper->findForCaseAndOfficer($caseId, $uid) === null) {
					continue;
				}
				$this->officerKeys->storeWraps($caseId, [$targetUid => $envelopeJson], $uid);
				$granted++;
			}
			$this->audit->record('wraps_granted', $uid, null, ['count' => $granted]);
			return $this->ok(['granted' => $granted]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function revokeOfficer(string $uid): JSONResponse
	{
		try {
			$actor = $this->officerGuard();
			if ($uid === $actor) {
				return $this->fail('invalid', 'Cannot revoke yourself', 400);
			}
			$this->officerKeys->revoke($uid, $actor);
			$this->companion->purgeOfficerDevices($uid);
			return $this->ok([]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function myWraps(): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$out = array_map(static fn ($w) => [
				'case_id' => $w->getCaseId(),
				'wrapped_key' => $w->getWrappedKey(),
			], $this->wrapMapper->findForOfficer($uid));
			return $this->ok(['wraps' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Directory search for the assign combobox — officers only see officer
	 * group members, existence+membership double-checked.
	 */
	#[NoAdminRequired]
	public function directorySearch(string $q = ''): JSONResponse
	{
		try {
			$this->officerGuard();
			$q = mb_substr(trim($q), 0, 64);
			$out = [];
			foreach ($this->officers->findActive() as $o) {
				$uid = $o->getUid();
				if ($q === '' || mb_stripos($uid, $q) !== false) {
					if ($this->userManager->userExists($uid)) {
						$out[] = ['uid' => $uid, 'display' => $this->userManager->get($uid)?->getDisplayName() ?? $uid];
					}
				}
			}
			return $this->ok(['results' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveSettings(): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$values = $this->request->getParam('values');
			$revisions = $this->request->getParam('revisions') ?? [];
			if (!is_array($values)) {
				throw new ValidationException('invalid', 'Missing values', []);
			}
			foreach ($values as $key => $value) {
				$rev = is_array($revisions) && isset($revisions[$key]) ? $this->intParam($revisions[$key]) : null;
				if ($rev === null) {
					return $this->fail('CONFLICT', 'Missing revision for ' . $key, 409);
				}
				$this->config->set((string)$key, (string)$value, $rev, $uid);
				$this->audit->record('config_changed', $uid, null, ['key' => (string)$key]);
			}
			return $this->ok(['revisions' => $this->config->revisions()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function categories(): JSONResponse
	{
		try {
			$this->officerGuard();
			return $this->ok(['categories' => $this->categoryList()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function postCategory(): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$labels = $this->request->getParam('labels');
			if (!is_array($labels) || ($labels['de'] ?? '') === '' || ($labels['en'] ?? '') === '') {
				throw new ValidationException('invalid', 'Both labels required', ['labels' => 'de + en required']);
			}
			$cat = new Category();
			$cat->setLabelJson((string)json_encode(['de' => mb_substr((string)$labels['de'], 0, 200), 'en' => mb_substr((string)$labels['en'], 0, 200)], JSON_THROW_ON_ERROR));
			$cat->setArchived(0);
			$cat->setSort(count($this->categoryMapper->findAll()));
			$this->categoryMapper->insert($cat);
			$this->audit->record('category_added', $uid, null, []);
			return $this->ok(['categories' => $this->categoryList()], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function putCategory(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$cat = $this->categoryMapper->find($id);
			if ($cat === null) {
				return $this->notFound();
			}
			$labels = $this->request->getParam('labels');
			if (is_array($labels)) {
				$cur = json_decode((string)$cat->getLabelJson(), true) ?: [];
				foreach (['de', 'en'] as $l) {
					if (isset($labels[$l]) && is_string($labels[$l])) {
						$cur[$l] = mb_substr($labels[$l], 0, 200);
					}
				}
				$cat->setLabelJson((string)json_encode($cur, JSON_THROW_ON_ERROR));
			}
			$this->categoryMapper->update($cat);
			$this->audit->record('category_updated', $uid, null, []);
			return $this->ok(['categories' => $this->categoryList()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Archive — categories referenced by cases are never deleted (US-008 AC-3).
	 */
	#[NoAdminRequired]
	public function archiveCategory(int $id): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$cat = $this->categoryMapper->find($id);
			if ($cat === null) {
				return $this->notFound();
			}
			$cat->setArchived(1);
			$this->categoryMapper->update($cat);
			$this->audit->record('category_archived', $uid, null, []);
			return $this->ok(['categories' => $this->categoryList()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function auditList(int $caseId = 0): JSONResponse
	{
		try {
			$this->officerGuard();
			$rows = $caseId > 0 ? $this->auditMapper->findForCase($caseId) : $this->auditMapper->findRecent(500);
			$out = array_map(static fn ($e) => [
				'seq' => $e->getSeq(),
				'event' => $e->getEventType(),
				'actor' => $e->getActorUid(),
				'case_id' => $e->getCaseId(),
				'payload' => $e->getPayloadJson() !== null ? json_decode($e->getPayloadJson(), true) : null,
				'at' => $e->getCreatedAt()->format(\DateTime::ATOM),
			], $rows);
			return $this->ok(['events' => $out, 'chain_ok' => $this->audit->verifyChain() === -1]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function stats(): JSONResponse
	{
		try {
			$this->officerGuard();
			return $this->ok($this->stats->dashboard());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function verbalText(): JSONResponse
	{
		try {
			$this->officerGuard();
			return $this->ok($this->verbal->offerText());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Pair a companion device — token shown once, only its hash persists.
	 */
	#[NoAdminRequired]
	public function pairDevice(string $name = ''): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			if (trim($name) === '') {
				throw new ValidationException('invalid', 'Device name required', ['name' => 'required']);
			}
			return $this->ok($this->companion->pair($uid, $name), 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listDevices(): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$out = [];
			foreach ($this->companion->devices($uid) as $id => $d) {
				$out[] = ['id' => $id, 'name' => $d['name'], 'created' => $d['created'], 'last_seen' => $d['last_seen']];
			}
			return $this->ok(['devices' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function revokeDevice(string $deviceId): JSONResponse
	{
		try {
			$uid = $this->officerGuard();
			$this->companion->revoke($uid, $deviceId);
			return $this->ok([]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/** @return list<array<string,mixed>> */
	private function categoryList(): array
	{
		return array_map(static fn (Category $c) => [
			'id' => $c->getId(),
			'label' => json_decode((string)$c->getLabelJson(), true) ?: [],
			'archived' => $c->getArchived() === 1,
			'sort' => $c->getSort(),
		], $this->categoryMapper->findAll());
	}
}
