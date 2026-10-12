<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// ── Officer UI pages ────────────────────────────────────────────────
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#inbox', 'url' => '/cases', 'verb' => 'GET'],
		['name' => 'page#case', 'url' => '/cases/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#officers', 'url' => '/officers', 'verb' => 'GET'],
		['name' => 'page#audit', 'url' => '/audit', 'verb' => 'GET'],
		['name' => 'page#stats', 'url' => '/stats', 'verb' => 'GET'],
		['name' => 'page#verbal', 'url' => '/verbal', 'verb' => 'GET'],
		['name' => 'page#settingsIndex', 'url' => '/settings', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings/{section}', 'verb' => 'GET',
			'requirements' => ['section' => 'access|channel|categories|attachments|retention|verbal|companion|audit|support']],

		// ── Public reporter pages (anonymous, no session) ───────────────────
		['name' => 'publicPage#reportForm', 'url' => '/report', 'verb' => 'GET'],
		['name' => 'publicPage#reportSubmit', 'url' => '/report', 'verb' => 'POST'],
		['name' => 'publicPage#followForm', 'url' => '/case', 'verb' => 'GET'],
		['name' => 'publicPage#followOpen', 'url' => '/case', 'verb' => 'POST'],
		['name' => 'publicPage#followReply', 'url' => '/case/reply', 'verb' => 'POST'],

		// ── Public reporter JSON API (anonymous, stateless) ─────────────────
		['name' => 'publicApi#bootstrap', 'url' => '/api/public/bootstrap', 'verb' => 'GET'],
		['name' => 'publicApi#pow', 'url' => '/api/public/pow', 'verb' => 'POST'],
		['name' => 'publicApi#submit', 'url' => '/api/public/submit', 'verb' => 'POST'],
		['name' => 'publicApi#caseParams', 'url' => '/api/public/case/params', 'verb' => 'POST'],
		['name' => 'publicApi#caseOpen', 'url' => '/api/public/case/open', 'verb' => 'POST'],
		['name' => 'publicApi#caseReply', 'url' => '/api/public/case/reply', 'verb' => 'POST'],
		['name' => 'publicApi#attachment', 'url' => '/api/public/attachment', 'verb' => 'POST'],

		// ── Officer JSON API ────────────────────────────────────────────────
		['name' => 'api#inbox', 'url' => '/api/inbox', 'verb' => 'GET'],
		['name' => 'api#caseBundle', 'url' => '/api/cases/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#postMessage', 'url' => '/api/cases/{id}/messages', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#setStatus', 'url' => '/api/cases/{id}/status', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#assign', 'url' => '/api/cases/{id}/assign', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#setHold', 'url' => '/api/cases/{id}/hold', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#confirmClose', 'url' => '/api/cases/{id}/confirm-close', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#notes', 'url' => '/api/cases/{id}/notes', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#postNote', 'url' => '/api/cases/{id}/notes', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'api#deleteNote', 'url' => '/api/cases/{id}/notes/{noteId}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+', 'noteId' => '\d+']],
		['name' => 'api#auditExport', 'url' => '/api/cases/{id}/audit-export', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'api#officers', 'url' => '/api/officers', 'verb' => 'GET'],
		['name' => 'api#registerKey', 'url' => '/api/officers/key', 'verb' => 'POST'],
		['name' => 'api#grantWraps', 'url' => '/api/officers/grants', 'verb' => 'POST'],
		['name' => 'api#revokeOfficer', 'url' => '/api/officers/{uid}', 'verb' => 'DELETE'],
		['name' => 'api#myWraps', 'url' => '/api/my-wraps', 'verb' => 'GET'],
		['name' => 'api#directorySearch', 'url' => '/api/directory/search', 'verb' => 'GET'],
		['name' => 'api#saveSettings', 'url' => '/api/settings', 'verb' => 'PUT'],
		['name' => 'api#categories', 'url' => '/api/categories', 'verb' => 'GET'],
		['name' => 'api#postCategory', 'url' => '/api/categories', 'verb' => 'POST'],
		['name' => 'api#putCategory', 'url' => '/api/categories/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'api#archiveCategory', 'url' => '/api/categories/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'api#auditList', 'url' => '/api/audit', 'verb' => 'GET'],
		['name' => 'api#stats', 'url' => '/api/stats', 'verb' => 'GET'],
		['name' => 'api#verbalText', 'url' => '/api/verbal-text', 'verb' => 'GET'],
		['name' => 'api#pairDevice', 'url' => '/api/devices', 'verb' => 'POST'],
		['name' => 'api#listDevices', 'url' => '/api/devices', 'verb' => 'GET'],
		['name' => 'api#revokeDevice', 'url' => '/api/devices/{deviceId}', 'verb' => 'DELETE'],

		// ── Companion API (officer mobile app; NC-auth, E2E transport) ───────
		['name' => 'companion#profile', 'url' => '/api/companion/profile', 'verb' => 'GET'],
		['name' => 'companion#inbox', 'url' => '/api/companion/inbox', 'verb' => 'GET'],
		['name' => 'companion#caseBundle', 'url' => '/api/companion/cases/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'companion#reply', 'url' => '/api/companion/cases/{id}/reply', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'companion#setStatus', 'url' => '/api/companion/cases/{id}/status', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'companion#myWraps', 'url' => '/api/companion/wraps', 'verb' => 'GET'],
		['name' => 'companion#registerKey', 'url' => '/api/companion/key', 'verb' => 'POST'],
	],
];
