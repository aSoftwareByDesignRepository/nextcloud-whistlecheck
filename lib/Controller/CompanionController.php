<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Controller;

use OCA\WhistleCheck\Db\CaseKeyWrapMapper;
use OCA\WhistleCheck\Db\OfficerMapper;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Service\CaseService;
use OCA\WhistleCheck\Service\CompanionService;
use OCA\WhistleCheck\Service\DeadlineService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCA\WhistleCheck\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Companion-app API (officer mobile). Bearer-device auth, NOT session auth —
 * the app holds a paired device token, not a Nextcloud login.
 *
 * Same-origin CSRF rules do not apply, so NoCSRFRequired + PublicPage are
 * correct here; authentication is the wcd_ bearer token + X-Wc-Ts replay
 * window. Payloads stay ciphertext-only, content-free metadata aside.
 */
class CompanionController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CompanionService $companion,
		private readonly OfficerKeyService $officerKeys,
		private readonly OfficerMapper $officers,
		private readonly WcaseMapper $caseMapper,
		private readonly CaseKeyWrapMapper $wrapMapper,
		private readonly CaseService $cases,
		private readonly DeadlineService $deadlines,
		private readonly RateLimitService $rate,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Resolve bearer token → officer uid. Enforces the replay window.
	 *
	 * @return array{0:string,1:string} uid, deviceId
	 * @throws DomainException 401 uniform
	 */
	private function deviceAuth(): array
	{
		$auth = (string)$this->request->getHeader('Authorization');
		if (!str_starts_with($auth, 'Bearer ')) {
			throw new DomainException('unauthorized', 'Unauthorized', 401);
		}
		$ts = $this->request->getHeader('X-Wc-Ts');
		if (!is_numeric($ts) || abs(time() - (int)$ts) > 300) {
			throw new DomainException('unauthorized', 'Unauthorized', 401);
		}
		$resolved = $this->companion->resolve(substr($auth, 7));
		if ($resolved === null) {
			throw new DomainException('unauthorized', 'Unauthorized', 401);
		}
		[$uid, $deviceId] = explode('|', $resolved, 2);
		$this->companion->touch($uid, $deviceId);
		// Per-uid rate bucket — key is the uid, no IP storage needed.
		$this->rate->check('device:' . $uid, 'reply');
		return [$uid, $deviceId];
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function profile(): JSONResponse
	{
		try {
			[$uid] = $this->deviceAuth();
			$officer = $this->officers->findByUid($uid);
			return $this->ok([
				'uid' => $uid,
				'fingerprint' => $officer?->getFingerprint(),
				'key_version' => $officer?->getKeyVersion(),
				'pubkey' => $officer?->getPubkey(),
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inbox(): JSONResponse
	{
		try {
			[$uid] = $this->deviceAuth();
			$today = $this->deadlines->today();
			$out = [];
			foreach ($this->caseMapper->listInbox() as $case) {
				$wrap = $this->wrapMapper->findForCaseAndOfficer($case->getId(), $uid);
				if ($wrap === null) {
					continue;
				}
				$out[] = [
					'id' => $case->getId(),
					'status' => $case->getStatus(),
					'overdue' => $case->isOverdue($today),
					'received_at' => $case->getReceivedAt()->format(\DateTime::ATOM),
					'feedback_due' => $case->getFeedbackDue()->format('Y-m-d'),
					'wrap' => $wrap->getWrappedKey(),
					'title_cipher' => $case->getTitleCipher(),
				];
			}
			return $this->ok(['cases' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function caseBundle(int $id): JSONResponse
	{
		try {
			[$uid] = $this->deviceAuth();
			$case = $this->caseMapper->find($id);
			$wrap = $case === null ? null : $this->wrapMapper->findForCaseAndOfficer($id, $uid);
			if ($wrap === null) {
				return $this->notFound();
			}
			$data = $this->cases->officerView($case, $uid);
			$data['wrap'] = $wrap->getWrappedKey();
			return $this->ok($data);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reply(int $id): JSONResponse
	{
		try {
			[$uid] = $this->deviceAuth();
			$cipher = (string)$this->request->getParam('cipher', '');
			$revision = $this->intParam($this->request->getParam('revision'));
			if ($revision === null) {
				return $this->fail('CONFLICT', 'Missing revision', 409);
			}
			if ($this->wrapMapper->findForCaseAndOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$case = $this->cases->reply($id, 'officer', $cipher, $uid, $revision);
			return $this->ok(['revision' => $case->getRevision()], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setStatus(int $id): JSONResponse
	{
		try {
			[$uid] = $this->deviceAuth();
			$to = (string)$this->request->getParam('status', '');
			$revision = $this->intParam($this->request->getParam('revision'));
			if ($revision === null) {
				return $this->fail('CONFLICT', 'Missing revision', 409);
			}
			if ($this->wrapMapper->findForCaseAndOfficer($id, $uid) === null) {
				return $this->notFound();
			}
			$case = $this->cases->setStatus($id, $to, $revision, $uid);
			return $this->ok(['status' => $case->getStatus(), 'revision' => $case->getRevision()]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myWraps(): JSONResponse
	{
		try {
			[$uid] = $this->deviceAuth();
			$out = array_map(static fn ($w) => [
				'case_id' => $w->getCaseId(),
				'wrapped_key' => $w->getWrappedKey(),
			], $this->wrapMapper->findForOfficer($uid));
			return $this->ok(['wraps' => $out]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function registerKey(string $pubkey): JSONResponse
	{
		try {
			[$uid] = $this->deviceAuth();
			$officer = $this->officerKeys->register($uid, $pubkey, $uid);
			return $this->ok(['fingerprint' => $officer->getFingerprint(), 'key_version' => $officer->getKeyVersion()], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
