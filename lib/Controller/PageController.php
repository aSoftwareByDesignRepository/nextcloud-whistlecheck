<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Controller;

use OCA\WhistleCheck\Service\ConfigService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Util;

/**
 * Officer UI shell. Every page guards officer membership first — a non-officer
 * gets the honest "not your channel" page (still inside their session, so a
 * real 404-style message is fine here; the strict oracle rules govern the
 * public API, not a logged-in navigation shell).
 */
class PageController extends Controller
{
	private const SECTIONS = ['access', 'channel', 'categories', 'attachments', 'retention', 'verbal', 'companion', 'audit', 'support'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly OfficerKeyService $officerKeys,
		private readonly ConfigService $config,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return $this->guard() ?? $this->render('inbox');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inbox(): TemplateResponse
	{
		return $this->guard() ?? $this->render('inbox');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function case(int $id): TemplateResponse
	{
		return $this->guard() ?? $this->render('case', ['caseId' => $id]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function officers(): TemplateResponse
	{
		return $this->guard() ?? $this->render('officers');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function audit(): TemplateResponse
	{
		return $this->guard() ?? $this->render('audit');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stats(): TemplateResponse
	{
		return $this->guard() ?? $this->render('stats');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function verbal(): TemplateResponse
	{
		return $this->guard() ?? $this->render('verbal');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsIndex(): TemplateResponse
	{
		return $this->guard() ?? $this->render('settings', ['section' => 'channel']);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $section): TemplateResponse
	{
		$deny = $this->guard();
		if ($deny !== null) {
			return $deny;
		}
		if (!in_array($section, self::SECTIONS, true)) {
			$section = 'channel';
		}
		return $this->render('settings', ['section' => $section]);
	}

	private function render(string $template, array $params = []): TemplateResponse
	{
		Util::addScript('whistlecheck', 'vendor/libsodium-sumo');
		Util::addScript('whistlecheck', 'vendor/libsodium-wrappers');
		Util::addScript('whistlecheck', 'common/wc-crypto');
		Util::addScript('whistlecheck', 'common/field-errors');
		Util::addScript('whistlecheck', 'whistlecheck-main');
		Util::addStyle('whistlecheck', 'whistlecheck');
		$res = new TemplateResponse('whistlecheck', $template, array_merge([
			'urlGenerator' => $this->urlGenerator,
			'config' => $this->config->getAll(),
			'revisions' => $this->config->revisions(),
			'officers' => $this->officerKeys->activePubkeys(),
			'wc_uid' => $this->userSession->getUser()?->getUID() ?? '',
			'i18n' => $this->officerI18n(),
		], $params));
		// Officer pages decrypt client-side — libsodium-sumo needs WASM.
		$csp = $res->getContentSecurityPolicy();
		$csp->addAllowedScriptDomain("'wasm-unsafe-eval'");
		$res->setContentSecurityPolicy($csp);
		return $res;
	}

	/**
	 * Strings the officer JS needs (statuses, errors, prompts) — delivered via
	 * data-wc-i18n so the client stays translatable without inline scripts.
	 *
	 * @return array<string,string>
	 */
	private function officerI18n(): array
	{
		return [
			'status_received' => $this->l->t('Received'),
			'status_in_review' => $this->l->t('In review'),
			'status_action_taken' => $this->l->t('Action taken'),
			'status_closed' => $this->l->t('Closed'),
			'status_archived' => $this->l->t('Archived'),
			'overdue' => $this->l->t('OVERDUE'),
			'inbox_empty' => $this->l->t('No cases yet.'),
			'case_fallback_title' => $this->l->t('Case #'),
			'case_locked' => $this->l->t('Unlock your key to read this case.'),
			'err_nokey' => $this->l->t('Unlock your key to read this case.'),
			'err_decrypt' => $this->l->t('Decryption failed.'),
			'err_pass' => $this->l->t('Wrong passphrase — try again.'),
			'err_passweak' => $this->l->t('Passphrase needs at least 10 characters.'),
			'err_conflict' => $this->l->t('Someone else changed this case — reloading.'),
			'prompt_pass' => $this->l->t('Enter your WhistleCheck passphrase'),
			'prompt_newpass' => $this->l->t('Choose a passphrase for your case key (store it safely — it cannot be recovered)'),
			'confirm_revoke' => $this->l->t('Revoke this officer? You will re-wrap all cases afterwards.'),
			'key_saved' => $this->l->t('Key registered. Keep your passphrase safe.'),
			'status_saved' => $this->l->t('Status updated.'),
			'settings_saved' => $this->l->t('Settings saved.'),
			'note_added' => $this->l->t('Note saved.'),
			'hold_saved' => $this->l->t('Saved.'),
			'assigned' => $this->l->t('Assignment saved.'),
			'chain_ok' => $this->l->t('Chain intact'),
			'chain_broken' => $this->l->t('CHAIN BROKEN'),
			'receipt_text' => $this->l->t('Your report was received. Case officers can now read and answer it.'),
			'revoke' => $this->l->t('Revoke'),
			'archive' => $this->l->t('Archive'),
			'active' => $this->l->t('Active'),
			'archived' => $this->l->t('Archived'),
			'locked' => $this->l->t('Locked'),
			'err_generic' => $this->l->t('Something went wrong. Please try again.'),
		];
	}

	private function guard(): ?TemplateResponse
	{
		$uid = $this->userSession->getUser()?->getUID();
		if (!$uid || !$this->officerKeys->isOfficer($uid)) {
			return new TemplateResponse('whistlecheck', 'denied', ['urlGenerator' => $this->urlGenerator], TemplateResponse::RENDER_AS_GUEST);
		}
		return null;
	}
}
