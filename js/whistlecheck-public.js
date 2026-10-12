/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * WhistleCheck public reporter JS — full E2E path.
 *
 * The case code is generated and kept client-side. The server only ever sees:
 * hint (sha256), verifier (argon2id), and ciphertext. When libsodium or
 * fetch is unavailable the page keeps working through the no-JS <form> —
 * the honesty banner explains the difference.
 */
(function () {
	'use strict';

	const bootEl = () => document.getElementById('content') || document.body;
	const dataset = () => (bootEl() && bootEl().dataset) || {};
	const i18n = (() => { try { return JSON.parse(dataset().wcI18n || '{}'); } catch (e) { return {}; } })();
	const t = (key, fallback) => i18n[key] || fallback || key;
	const apiBase = (() => {
		const d = dataset().wcApiBase || '';
		return d.replace(/\/api\/public.*$/, '');
	})();
	const officers = (() => { try { return JSON.parse(dataset().wcOfficers || '[]'); } catch (e) { return []; } })();

	function showError(msg, fields) {
		const box = document.getElementById('wc-form-error');
		if (box) {
			box.textContent = msg;
			box.hidden = false;
			box.focus();
		}
		if (window.CheckFieldErrors && fields) {
			window.CheckFieldErrors.markValidationFields(fields);
		}
	}

	function clearError() {
		const box = document.getElementById('wc-form-error');
		if (box) { box.hidden = true; box.textContent = ''; }
	}

	// Rate-bucket kind per endpoint — the challenge must be issued for the
	// same bucket the request is checked against (spec US-006).
	const POW_KIND = {
		'/api/public/submit': 'submit',
		'/api/public/attachment': 'submit',
		'/api/public/case/params': 'params',
		'/api/public/case/open': 'verify',
		'/api/public/case/reply': 'reply',
	};

	/**
	 * PoW bind for a request body — mirrors the server's derivation in
	 * PublicApiController: ikey for submit, hint for case ops, sha256 hex of
	 * the envelope for attachments. Binds the proof to THIS request so a
	 * solved challenge cannot be replayed against different payloads.
	 */
	async function powBind(body) {
		if (body && body.ikey) return String(body.ikey);
		if (body && body.hint) return String(body.hint);
		if (body && body.cipher) return window.WcCrypto.sha256Hex(body.cipher);
		return '';
	}

	/**
	 * fetch wrapper with one automatic PoW retry: on 429/pow_required the
	 * challenge is solved and the request re-issued with proof headers.
	 */
	async function api(path, body) {
		const res = await post(path, body, {});
		if (res.status === 429) {
			const payload = await safeJson(res);
			if (payload && (payload.error === 'POW_REQUIRED' || payload.error === 'pow_required')) {
				const ch = await post('/api/public/pow', { kind: POW_KIND[path] || 'submit' }, {});
				const chBody = await safeJson(ch);
				if (chBody && chBody.success && chBody.data && chBody.data.challenge) {
					const nonce = await window.WcCrypto.solvePow(chBody.data.challenge, chBody.data.difficulty || 18, await powBind(body));
					return post(path, body, { 'X-Wc-Pow-Challenge': chBody.data.challenge, 'X-Wc-Pow-Nonce': nonce });
				}
			}
			return res;
		}
		return res;
	}

	function post(path, body, headers) {
		return fetch(apiBase + path, {
			method: 'POST',
			credentials: 'omit', // never send cookies — public surface is stateless
			headers: Object.assign({ 'Content-Type': 'application/json', 'Accept': 'application/json' }, headers),
			body: JSON.stringify(body || {}),
		});
	}

	async function safeJson(res) {
		try { return await res.json(); } catch (e) { return null; }
	}

	async function readFile(file, maxBytes) {
		if (file.size > maxBytes) throw new Error('too_large');
		return new Uint8Array(await file.arrayBuffer());
	}

	// ── submit flow ──────────────────────────────────────────────────────

	async function submitFlow(form) {
		clearError();
		const C = window.WcCrypto;
		const body = form.querySelector('[name=body]').value.trim();
		const title = form.querySelector('[name=title]') ? form.querySelector('[name=title]').value.trim() : '';
		const contact = form.querySelector('[name=contact]') ? form.querySelector('[name=contact]').value.trim() : '';
		const catEl = form.querySelector('[name=category_id]');
		const categoryId = catEl && catEl.value ? parseInt(catEl.value, 10) : null;

		if (!body) {
			showError(t('err_required', 'Please fill in the report.'), { body: t('err_required', 'Required') });
			return;
		}

		const btn = form.querySelector('[type=submit]');
		btn.disabled = true;
		try {
			const genBytes = crypto.getRandomValues(new Uint8Array(16));
			const codeCanonical = C.encode(genBytes);

			const saltV = await C.randomHex(16);
			const saltK = await C.randomHex(16);
			const caseKey = await C.randomKey();
			const kRep = await C.reporterKey(genBytes, saltK);

			// officers pubkeys embedded by the page (bootstrap data).
			if (officers.length === 0) {
				showError(t('err_notready', 'This channel is not ready yet. Please try again later.'));
				return;
			}
			const wraps = {};
			for (const o of officers) {
				wraps[o.uid] = await C.sealToPubkey(caseKey, o.pubkey);
			}

			const files = form.querySelector('[name=attachments]')?.files || [];
			const maxMb = parseInt(form.dataset.maxMb || '10', 10);
			const attachments = [];
			for (const f of files) {
				const data = await readFile(f, maxMb * 1048576);
				attachments.push({
					cipher: await C.sealChunked(data, caseKey),
					mime: f.type || 'application/octet-stream',
					name_cipher: await C.seal(f.name, caseKey),
				});
			}

			const payload = {
				ikey: crypto.randomUUID(),
				hint: await C.hint(genBytes),
				verifier: await C.verifier(genBytes, saltV),
				code_salt: saltV,
				key_salt: saltK,
				reporter_wrap: await C.sealBytes(caseKey, kRep),
				title_cipher: title ? await C.seal(title, caseKey) : '',
				body: await C.seal(body, caseKey),
				contact_cipher: contact ? await C.seal(contact, caseKey) : '',
				category_id: Number.isInteger(categoryId) ? categoryId : null,
				wraps,
				attachments,
				receipt_cipher: await C.seal('wchk:receipt', caseKey),
			};

			const res = await api('/api/public/submit', payload);
			const json = await safeJson(res);
			if (res.ok && json && json.success) {
				showCode(C.format(codeCanonical));
			} else {
				showError((json && json.message) || t('err_generic', 'Something went wrong.'), json && json.fields);
			}
		} catch (e) {
			showError(t('err_generic', 'Something went wrong. Please try again.'));
		} finally {
			btn.disabled = false;
		}
	}

	function showCode(formatted) {
		const form = document.getElementById('wc-report-form');
		const done = document.getElementById('wc-code-panel');
		if (!done) return;
		const codeEl = done.querySelector('.wc-code-value');
		if (codeEl) codeEl.textContent = formatted;
		if (form) form.hidden = true;
		done.hidden = false;
		done.focus();
	}

	// ── follow-up flow ───────────────────────────────────────────────────

	async function followFlow(form) {
		clearError();
		const C = window.WcCrypto;
		const codeInput = form.querySelector('[name=code]').value;
		const bytes = C.decode(codeInput);
		if (!bytes) {
			showError(t('err_invalid_code', 'Invalid or expired case code.'));
			return;
		}
		const btn = form.querySelector('[type=submit]');
		btn.disabled = true;
		try {
			// Zero-knowledge: the raw code NEVER leaves the client — the server
			// only gets its sha256 lookup hint (NN-09).
			const hint = await C.hint(bytes);
			const p = await api('/api/public/case/params', { hint });
			const params = (await safeJson(p))?.data;
			if (!params) {
				showError(t('err_invalid_code', 'Invalid or expired case code.'));
				return;
			}
			const verifier = await C.verifier(bytes, params.salt);
			const kRep = await C.reporterKey(bytes, params.key_salt);

			const o = await api('/api/public/case/open', { hint, verifier });
			const openJson = await safeJson(o);
			if (!o.ok || !openJson || !openJson.success) {
				showError(t('err_invalid_code', 'Invalid or expired case code.'));
				return;
			}
			const view = openJson.data;
			// reporter_wrap carries the RAW 32-byte case key — openBytes, not utf8.
			const rawKey = await C.openBytes(view.reporter_wrap, kRep);
			if (!rawKey) {
				showError(t('err_invalid_code', 'Invalid or expired case code.'));
				return;
			}
			renderThread(view, rawKey, hint, verifier);
		} catch (e) {
			showError(t('err_generic', 'Something went wrong. Please try again.'));
		} finally {
			btn.disabled = false;
		}
	}

	async function renderThread(view, caseKey, hint, verifier) {
		const C = window.WcCrypto;
		const form = document.getElementById('wc-follow-form');
		const panel = document.getElementById('wc-thread');
		if (!panel) return;
		const list = panel.querySelector('.wc-messages');
		list.innerHTML = '';
		for (const m of view.messages) {
			const plain = await C.open(m.cipher, caseKey);
			const li = document.createElement('li');
			li.className = 'wc-msg wc-msg-' + (m.direction === 'reporter' ? 'me' : 'them');
			// textContent ONLY — reporter bytes never enter the DOM as HTML (NN-05).
			const p = document.createElement('p');
			p.textContent = plain === 'wchk:receipt' ? t('receipt_text', 'Your report was received. Case officers can now read and answer it.') : (plain || '…');
			const time = document.createElement('time');
			time.textContent = m.at;
			li.appendChild(p); li.appendChild(time);
			list.appendChild(li);
		}
		const statusEl = panel.querySelector('.wc-status');
		if (statusEl) statusEl.textContent = view.status;
		const dueEl = panel.querySelector('.wc-due');
		if (dueEl) dueEl.textContent = view.feedback_due;
		if (form) form.hidden = true;
		panel.hidden = false;
		panel.focus();

		const replyForm = document.getElementById('wc-reply-form');
		if (replyForm && !view.readonly) {
			replyForm.hidden = false;
			replyForm.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const btn = replyForm.querySelector('[type=submit]');
				const text = replyForm.querySelector('[name=body]').value.trim();
				if (!text) return;
				btn.disabled = true;
				try {
					const cipher = await C.seal(text, caseKey);
					const res = await api('/api/public/case/reply', { hint, verifier, cipher });
					const json = await safeJson(res);
					if (res.ok && json && json.success) {
						replyForm.querySelector('[name=body]').value = '';
						const li = document.createElement('li');
						li.className = 'wc-msg wc-msg-me';
						const p = document.createElement('p');
						p.textContent = text;
						li.appendChild(p);
						list.appendChild(li);
					} else {
						showError((json && json.message) || t('err_generic', 'Something went wrong.'));
					}
				} catch (e) {
					// Network/crypto failure — the user must see it, not a silent hang.
					showError(t('err_generic', 'Something went wrong.'));
				} finally {
					btn.disabled = false;
				}
			}, { once: false });
		}
	}

	// ── boot ─────────────────────────────────────────────────────────────

	// ── case code input: forgiving formatting ────────────────────────────
	// Live-normalize into XXXXX-XXXXX-… groups — dashes/spaces/paste errors
	// are stripped automatically; decode() already maps O→0, I/L→1.
	function bindCodeInput(input) {
		input.addEventListener('input', () => {
			const clean = input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 26);
			input.value = clean.replace(/(.{5})(?=.)/g, '$1-');
		});
	}

	function copyCode(btn) {
		const val = document.querySelector('#wc-code-panel .wc-code-value');
		if (!val || !val.textContent) return;
		const done = () => { btn.textContent = t('copied', 'Copied.'); };
		// Never claim "Copied." when it wasn't — select the text so the
		// reporter can copy it manually.
		const fail = () => {
			const range = document.createRange();
			range.selectNodeContents(val);
			const sel = window.getSelection();
			if (sel) { sel.removeAllRanges(); sel.addRange(range); }
			btn.textContent = t('copy_manual', 'Select the code and copy it manually');
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(val.textContent).then(done, fail);
			return;
		}
		const ta = document.createElement('textarea');
		ta.value = val.textContent;
		document.body.appendChild(ta);
		ta.select();
		let ok = false;
		try { ok = document.execCommand('copy'); } catch (e) { /* noop */ }
		ta.remove();
		if (ok) done(); else fail();
	}

	document.addEventListener('DOMContentLoaded', () => {
		if (window.CheckFieldErrors) window.CheckFieldErrors.install({ prefix: 'wc' });
		const reportForm = document.getElementById('wc-report-form');
		const followForm = document.getElementById('wc-follow-form');
		const codeInput = document.getElementById('wc-code');
		if (codeInput) bindCodeInput(codeInput);
		const copyBtn = document.querySelector('[data-wc-copy-code]');
		if (copyBtn) copyBtn.addEventListener('click', () => copyCode(copyBtn));
		const e2eCapable = !!(window.sodium && window.crypto && window.crypto.randomUUID);

		// Honesty: JS + libsodium present → E2E badge; else the no-JS form
		// already carries the honesty banner in its markup.
		const badge = document.querySelector('[data-wc-e2e-badge]');
		if (badge) {
			badge.textContent = e2eCapable ? t('e2e_badge', 'End-to-end encrypted') : t('nojs_badge', 'Encrypted in transit and at rest (JavaScript disabled)');
		}

		if (reportForm && e2eCapable) {
			reportForm.addEventListener('submit', (ev) => {
				ev.preventDefault();
				submitFlow(reportForm);
			});
		}
		if (followForm && e2eCapable) {
			followForm.addEventListener('submit', (ev) => {
				ev.preventDefault();
				followFlow(followForm);
			});
		}
	});
})();
