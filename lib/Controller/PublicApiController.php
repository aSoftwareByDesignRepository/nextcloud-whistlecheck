<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Controller;

use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Service\AttachmentService;
use OCA\WhistleCheck\Service\CaseService;
use OCA\WhistleCheck\Service\ConfigService;
use OCA\WhistleCheck\Service\PowService;
use OCA\WhistleCheck\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Anonymous reporter API — stateless, no session, no cookies.
 *
 * Every endpoint: origin-checked, rate-limited through rotating-salt buckets,
 * uniform error payloads. A public response NEVER carries Set-Cookie and is
 * always Cache-Control: no-store (spec §6/C1: nothing persists that can
 * identify a reporter).
 */
class PublicApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseService $cases,
		private readonly RateLimitService $rate,
		private readonly PowService $pow,
		private readonly ConfigService $config,
		private readonly CategoryMapper $categories,
		private readonly AttachmentService $attachmentPolicy,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Static bootstrap data for the reporter UI: categories + honesty flags.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		try {
			$this->rate->check($this->ip(), 'params');
			$cats = array_map(static fn ($c) => [
				'id' => $c->getId(),
				'label' => json_decode((string)$c->getLabelJson(), true) ?: [],
			], $this->categories->findActive());
			return $this->ok([
				'categories' => $cats,
				'attachments_enabled' => $this->config->getBool('attachments_enabled'),
				'max_attachment_mb' => $this->config->getInt('max_attachment_mb'),
				'welcome' => $this->config->get('welcome_text'),
				'org' => $this->config->get('org_name'),
				'e2e' => true,
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Issue a PoW challenge for the caller's bucket (escalation step).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function pow(string $kind): JSONResponse
	{
		try {
			$kind = in_array($kind, ['submit', 'params', 'verify', 'reply'], true) ? $kind : 'submit';
			return $this->ok($this->pow->issue($this->rate->bucketFor($this->ip(), $kind)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * E2E submit — encrypted payload only; the case code never reaches us.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function submit(): JSONResponse
	{
		try {
			$powOk = $this->powProof('submit');
			$this->rate->check($this->ip(), 'submit', $powOk);
			$result = $this->cases->submit($this->request->getParams(), true);
			return $this->ok($result, 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Phase 1: fetch KDF params for a code. Unknown codes get deterministic
	 * pseudo-salts — identical shape either way.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function caseParams(string $code): JSONResponse
	{
		try {
			$powOk = $this->powProof('params');
			$this->rate->check($this->ip(), 'params', $powOk);
			return $this->ok($this->cases->params($code));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Phase 2: verify + fetch case. Uniform 401 on any miss.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function caseOpen(string $code, string $verifier): JSONResponse
	{
		try {
			$powOk = $this->powProof('verify');
			$this->rate->check($this->ip(), 'verify', $powOk);
			return $this->ok($this->cases->open($code, $verifier));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Reporter reply: code + verifier + ciphertext (encrypted client-side with
	 * the recovered case key).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function caseReply(string $code, string $verifier, string $cipher): JSONResponse
	{
		try {
			$powOk = $this->powProof('reply');
			$this->rate->check($this->ip(), 'reply', $powOk);
			$caseId = $this->cases->replyAsReporter($code, $verifier, $cipher);
			return $this->ok(['case_id' => $caseId], 201);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Attachment upload alias — handled inside submit's attachments array; this
	 * endpoint exists for the chunked-upload UI path and validates one blob.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function attachment(string $cipher, string $mime): JSONResponse
	{
		try {
			$powOk = $this->powProof('submit');
			$this->rate->check($this->ip(), 'submit', $powOk);
			$size = $this->attachmentPolicy->validateCipher($cipher, $mime);
			return $this->ok(['size' => $size]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ── internals ────────────────────────────────────────────────────────

	private function ip(): string
	{
		return $this->request->getRemoteAddress() ?: 'unknown';
	}

	/**
	 * Optional PoW proof headers — when present and valid, the bucket limit
	 * doubles for this window (escalation, not lockout).
	 */
	private function powProof(string $kind): bool
	{
		$challenge = (string)$this->request->getHeader('X-Wc-Pow-Challenge');
		$nonce = (string)$this->request->getHeader('X-Wc-Pow-Nonce');
		if ($challenge === '' || $nonce === '') {
			return false;
		}
		return $this->pow->verify($this->rate->bucketFor($this->ip(), $kind), $challenge, $nonce);
	}
}
