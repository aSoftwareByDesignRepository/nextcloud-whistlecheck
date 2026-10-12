<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Controller;

use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Db\FormToken;
use OCA\WhistleCheck\Db\FormTokenMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Service\AttachmentService;
use OCA\WhistleCheck\Service\ConfigService;
use OCA\WhistleCheck\Service\NojsSubmissionService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCA\WhistleCheck\Service\RateLimitService;
use OCA\WhistleCheck\Service\CaseService;
use OCA\WhistleCheck\Service\SanitizeService;
use OCA\WhistleCheck\Crypto\CaseCodeService;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Standalone anonymous reporter pages — no Nextcloud chrome, no session, no
 * cookies, strict self-only CSP (spec §6, NN-01, NN-07).
 *
 * The same routes serve the JS app (E2E) and the no-JS fallback form
 * (server-side encryption, honestly flagged).
 */
class PublicPageController extends Controller
{
	private const FORM_TOKEN_TTL = 7200;
	private const MIN_SUBMIT_DELAY = 3; // seconds — bot floor for the no-JS path

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CategoryMapper $categories,
		private readonly ConfigService $config,
		private readonly NojsSubmissionService $nojs,
		private readonly RateLimitService $rate,
		private readonly FormTokenMapper $formTokens,
		private readonly CaseService $cases,
		private readonly CaseCodeService $codes,
		private readonly EnvelopeService $envelope,
		private readonly SanitizeService $sanitize,
		private readonly OfficerKeyService $officerKeys,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function reportForm(): TemplateResponse
	{
		try {
			$this->rate->check($this->ip(), 'params');
		} catch (DomainException $e) {
			return $this->limited($e);
		}
		return $this->standalone('public-report', $this->formParams());
	}

	/**
	 * No-JS submit POST — single-use form token + min-delay + honeypot.
	 * (JS submits go through the JSON API and never hit this route.)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function reportSubmit(): TemplateResponse
	{
		try {
			$this->rate->check($this->ip(), 'submit');
			if (!$this->config->getBool('nojs_enabled')) {
				throw new DomainException('nojs_disabled', 'This channel requires JavaScript', 403);
			}
			$this->consumeFormToken();
			// Honeypot: real users never fill the hidden "website" field.
			if ((string)$this->request->getParam('website', '') !== '') {
				throw new DomainException('invalid', 'Invalid submission', 400);
			}

			$files = [];
			foreach ((array)($this->request->getUploadedFile('attachments') ?? []) as $i => $f) {
				if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
					continue;
				}
				$data = file_get_contents((string)$f['tmp_name']);
				if ($data === false) {
					continue;
				}
				$files[] = [
					'data' => $data,
					'mime' => (string)($f['type'] ?? 'application/octet-stream'),
					'name' => (string)($f['name'] ?? 'attachment'),
				];
			}

			$result = $this->nojs->submit(
				(string)$this->request->getParam('title', ''),
				(string)$this->request->getParam('body', ''),
				(string)$this->request->getParam('contact', ''),
				$this->intParam($this->request->getParam('category_id')),
				$files,
				'nojs-' . bin2hex(random_bytes(16))
			);
			return $this->standalone('public-code', array_merge($this->bootParams(), ['code' => $result['code'], 'e2e' => false]));
		} catch (DomainException|ValidationException $e) {
			if ($e instanceof DomainException && $e->httpStatus === 429) {
				return $this->limited($e);
			}
			return $this->standalone('public-report', array_merge($this->formParams(), [
				'error' => $this->errorText($e),
				'fields' => $e instanceof ValidationException ? $e->getFields() : [],
			]));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function followForm(): TemplateResponse
	{
		try {
			$this->rate->check($this->ip(), 'params');
		} catch (DomainException $e) {
			return $this->limited($e);
		}
		return $this->standalone('public-follow', $this->followParams());
	}

	/**
	 * No-JS follow-up: verify code, render the decrypted thread. The no-JS
	 * path derives keys server-side — same honesty flag as submission.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function followOpen(): TemplateResponse
	{
		try {
			$this->rate->check($this->ip(), 'verify');
			if (!$this->config->getBool('nojs_enabled')) {
				throw new DomainException('nojs_disabled', 'This channel requires JavaScript', 403);
			}
			$this->consumeFormToken();
			$code = (string)$this->request->getParam('code', '');
			$bytes = $this->codes->decodeLoose($code);
			if ($bytes === null) {
				throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
			}
			$case = $this->cases->params($code); // real salts if it exists
			$hint = $this->codes->lookupHint($bytes);
			// open() performs the uniform-oracle-safe verify — we reuse it.
			$view = $this->cases->open($code, $this->codes->verifier($bytes, $case['salt']));
			return $this->standalone('public-thread', array_merge($this->bootParams(), [
				'view' => $this->decryptForNojs($view, $bytes, $case['key_salt']),
				'code' => $code,
				'form_token' => $this->mintFormToken(),
			]));
		} catch (DomainException|ValidationException $e) {
			if ($e instanceof DomainException && $e->httpStatus === 429) {
				return $this->limited($e);
			}
			return $this->standalone('public-follow', array_merge($this->followParams(), [
				'error' => $this->l->t('Invalid or expired case code.'),
			]));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function followReply(): TemplateResponse
	{
		try {
			$this->rate->check($this->ip(), 'reply');
			if (!$this->config->getBool('nojs_enabled')) {
				throw new DomainException('nojs_disabled', 'This channel requires JavaScript', 403);
			}
			$this->consumeFormToken();
			$code = (string)$this->request->getParam('code', '');
			$body = (string)$this->request->getParam('body', '');
			$bytes = $this->codes->decodeLoose($code);
			if ($bytes === null) {
				throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
			}
			$params = $this->cases->params($code);
			$verifier = $this->codes->verifier($bytes, $params['salt']);
			$kRep = $this->codes->reporterKey($bytes, $params['key_salt']);
			// Recover the case key from the reporter wrap, then re-encrypt reply.
			$view = $this->cases->open($code, $verifier);
			$caseKey = $this->envelope->open((string)$view['reporter_wrap'], $kRep);
			if ($caseKey === null) {
				throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
			}
			$cipher = $this->envelope->seal($body, $caseKey);
			sodium_memzero($caseKey);
			sodium_memzero($kRep);
			$this->cases->replyAsReporter($code, $verifier, $cipher);
			return $this->standalone('public-follow', array_merge($this->followParams(), [
				'notice' => $this->l->t('Reply sent.'),
				'code' => $code,
			]));
		} catch (DomainException|ValidationException $e) {
			if ($e instanceof DomainException && $e->httpStatus === 429) {
				return $this->limited($e);
			}
			return $this->standalone('public-follow', array_merge($this->followParams(), [
				'error' => $this->errorText($e),
				'code' => (string)$this->request->getParam('code', ''),
			]));
		}
	}

	// ── internals ────────────────────────────────────────────────────────

	/**
	 * Decrypt a public case view for the no-JS render. Every message goes
	 * through the plaintext sanitizer — output is safe ONLY because templates
	 * escape it; double protection.
	 *
	 * @param array<string,mixed> $view
	 * @return array<string,mixed>
	 */
	private function decryptForNojs(array $view, string $codeBytes, string $keySalt): array
	{
		$kRep = $this->codes->reporterKey($codeBytes, $keySalt);
		$caseKey = $this->envelope->open((string)$view['reporter_wrap'], $kRep);
		if ($caseKey === null) {
			sodium_memzero($kRep);
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
		$msgs = [];
		foreach ($view['messages'] as $m) {
			$plain = $this->envelope->open((string)$m['cipher'], $caseKey);
			$msgs[] = [
				'direction' => $m['direction'],
				'text' => $plain !== null ? $this->sanitize->plainText($plain) : '',
				'at' => $m['at'],
			];
		}
		sodium_memzero($caseKey);
		sodium_memzero($kRep);
		return [
			'status' => $view['status'],
			'received_at' => $view['received_at'],
			'feedback_due' => $view['feedback_due'],
			'readonly' => $view['readonly'],
			'messages' => $msgs,
			'e2e' => false,
		];
	}

	private function formParams(): array
	{
		return array_merge($this->bootParams(), [
			'categories' => array_map(static fn ($c) => [
				'id' => $c->getId(),
				'label' => json_decode((string)$c->getLabelJson(), true) ?: [],
			], $this->categories->findActive()),
			'welcome' => $this->config->get('welcome_text'),
			'attachments_enabled' => $this->config->getBool('attachments_enabled'),
			'max_attachment_mb' => $this->config->getInt('max_attachment_mb'),
			'form_token' => $this->mintFormToken(),
		]);
	}

	private function followParams(): array
	{
		return array_merge($this->bootParams(), [
			'form_token' => $this->mintFormToken(),
			'code' => '',
		]);
	}

	/**
	 * Bootstrap data every public template emits as data-* attributes —
	 * strict 'self' CSP forbids inline scripts, so data travels in markup.
	 *
	 * @return array<string,mixed>
	 */
	private function bootParams(): array
	{
		return [
			'org' => $this->config->get('org_name'),
			// Relative, not absolute: connect-src 'self' must match the page origin —
			// linkToRouteAbsolute would bake overwrite.cli.url (e.g. 127.0.0.1)
			// while the user may be on localhost / a vhost → CSP blocks fetch.
			'api_base' => rtrim(dirname($this->urlGenerator->linkToRoute('whistlecheck.publicApi.bootstrap')), '/'),
			'officers' => $this->officerPubkeys(),
			'i18n' => $this->publicI18n(),
		];
	}

	/**
	 * Officers embedded in public pages. Must carry the same `fp` fingerprint
	 * the bootstrap endpoint serves — storeWraps pins every wrap to the key
	 * version it was sealed under and rejects wraps without one.
	 *
	 * @return list<array{uid:string,pubkey:string,fp:string}>
	 */
	private function officerPubkeys(): array
	{
		return array_map(static fn ($o) => ['uid' => $o['uid'], 'pubkey' => $o['pubkey'], 'fp' => $o['fingerprint']],
			$this->officerKeys->activePubkeys());
	}

	/** @return array<string,string> public-surface strings in the request locale */
	private function publicI18n(): array
	{
		return [
			'err_required' => $this->l->t('Please fill in the highlighted fields.'),
			'err_generic' => $this->l->t('Something went wrong. Please try again.'),
			'err_invalid_code' => $this->l->t('Invalid or expired case code.'),
			'err_notready' => $this->l->t('This channel is not ready yet. Please try again later.'),
			'e2e_badge' => $this->l->t('End-to-end encrypted'),
			'nojs_badge' => $this->l->t('Encrypted in transit and at rest (JavaScript disabled)'),
			'receipt_text' => $this->l->t('Your report was received. Case officers can now read and answer it.'),
		];
	}

	private function mintFormToken(): string
	{
		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		$tok = new FormToken();
		$tok->setToken(bin2hex(random_bytes(32)));
		$tok->setBucketHash($this->rate->bucketFor($this->ip(), 'formtoken'));
		$tok->setIssuedAt($now);
		$tok->setMinSubmitAt((new \DateTime('@' . ($now->getTimestamp() + self::MIN_SUBMIT_DELAY))));
		$tok->setUsed(0);
		$this->formTokens->insert($tok);
		return $tok->getToken();
	}

	/**
	 * Single-use token + minimum delay. Unknown/used/early → uniform invalid.
	 *
	 * @throws DomainException
	 */
	private function consumeFormToken(): void
	{
		$token = (string)$this->request->getParam('form_token', '');
		$row = $this->formTokens->findByToken($token);
		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		if ($row === null || $now < $row->getMinSubmitAt()
			|| $now->getTimestamp() - $row->getIssuedAt()->getTimestamp() > self::FORM_TOKEN_TTL) {
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
		// Atomic claim — a single conditional UPDATE, so two concurrent
		// requests cannot both observe used=0 (replay race).
		if (!$this->formTokens->claim($token)) {
			throw new DomainException('invalid_or_expired', 'Invalid or expired', 401);
		}
	}

	/**
	 * Rate-limited reporter gets a friendly, honest page — never the generic
	 * Nextcloud 500 "Interner Serverfehler" that suggests the channel is broken.
	 * Keeps the strict guest CSP / no-store headers from standalone().
	 */
	private function limited(DomainException $e): TemplateResponse
	{
		$res = $this->standalone('public-limited', array_merge($this->bootParams(), [
			'retry_after' => $e->retryAfterSeconds,
		]));
		$res->setStatus($e->httpStatus);
		if ($e->retryAfterSeconds !== null && $e->retryAfterSeconds > 0) {
			$res->addHeader('Retry-After', (string)$e->retryAfterSeconds);
		}
		return $res;
	}

	private function standalone(string $template, array $params): TemplateResponse
	{
		Util::addStyle('whistlecheck', 'whistlecheck-public');
		Util::addScript('whistlecheck', 'vendor/libsodium-sumo');
		Util::addScript('whistlecheck', 'vendor/libsodium-wrappers');
		Util::addScript('whistlecheck', 'common/wc-crypto');
		Util::addScript('whistlecheck', 'common/field-errors');
		Util::addScript('whistlecheck', 'whistlecheck-public');
		$res = new TemplateResponse('whistlecheck', $template, array_merge(['urlGenerator' => $this->urlGenerator], $params), TemplateResponse::RENDER_AS_GUEST);
		$res->addHeader('Cache-Control', 'no-store');
		$res->addHeader('X-Content-Type-Options', 'nosniff');
		$res->addHeader('Referrer-Policy', 'no-referrer');
		// Strict self-only CSP — no third-party anything (NN-07).
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedScriptDomain("'self'");
		// libsodium-sumo compiles its WebAssembly module inline — without this
		// keyword the CSP blocks instantiation and every page silently degrades
		// to the no-JS server-side path.
		$csp->addAllowedScriptDomain("'wasm-unsafe-eval'");
		$csp->addAllowedStyleDomain("'self'");
		$csp->addAllowedImageDomain("'self'");
		$csp->addAllowedConnectDomain("'self'");
		$csp->addAllowedFontDomain("'self'");
		$res->setContentSecurityPolicy($csp);
		return $res;
	}

	private function errorText(\Throwable $e): string
	{
		return match (true) {
			$e instanceof DomainException && $e->httpStatus === 429 => $this->l->t('Too many requests — please try again later.'),
			$e instanceof DomainException && $e->httpStatus === 401 => $this->l->t('Invalid or expired case code.'),
			$e instanceof ValidationException => $this->l->t('Please check the highlighted fields.'),
			default => $this->l->t('Something went wrong. Please try again.'),
		};
	}

	private function intParam(mixed $v): ?int
	{
		return is_string($v) && preg_match('/^\d+$/', $v) === 1 ? (int)$v : (is_int($v) ? $v : null);
	}

	private function ip(): string
	{
		return $this->request->getRemoteAddress() ?: 'unknown';
	}
}
