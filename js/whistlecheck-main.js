/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * WhistleCheck officer UI — all decryption client-side (spec MH-01).
 * Reporter bytes are rendered via textContent only (NN-05).
 */
(function () {
	'use strict';

	const bootEl = () => document.getElementById("app-content") || document.body;
	const boot = () => bootEl().dataset || {};
	const i18n = (() => { try { return JSON.parse(boot().wcI18n || "{}"); } catch (e) { return {}; } })();
	const t = (key, fb) => i18n[key] || fb || key;
	const base = OC.generateUrl('/apps/whistlecheck');

	// ── shared api + toast ────────────────────────────────────────────────

	function api(path, opts) {
		opts = opts || {};
		return fetch(base + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: Object.assign(
				{ 'Accept': 'application/json', 'requesttoken': OC.requestToken },
				opts.body ? { 'Content-Type': 'application/json' } : {}),
			body: opts.body ? JSON.stringify(opts.body) : undefined,
		}).then(async (res) => {
			const json = await res.json().catch(() => null);
			if (!res.ok || !json || json.success === false) {
				if (json && json.fields && window.CheckFieldErrors) {
					window.CheckFieldErrors.markValidationFields(json.fields);
				}
				const err = new Error((json && json.message) || 'error');
				err.payload = json;
				err.status = res.status;
				throw err;
			}
			return json.data;
		});
	}

	// App-owned toast container — dedup on kind+text, reset timer (rule §6).
	const toastState = {};
	function toast(kind, text) {
		let host = document.getElementById('wc-toasts');
		if (!host) {
			host = document.createElement('div');
			host.id = 'wc-toasts';
			host.setAttribute('aria-live', 'polite');
			document.body.appendChild(host);
		}
		const key = kind + '|' + text;
		if (toastState[key]) {
			clearTimeout(toastState[key].timer);
			toastState[key].timer = setTimeout(() => dismiss(key), 4500);
			return;
		}
		const el = document.createElement('div');
		el.className = 'wc-toast wc-toast-' + kind;
		el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		el.textContent = text;
		host.appendChild(el);
		toastState[key] = { el, timer: setTimeout(() => dismiss(key), 4500) };
	}
	function dismiss(key) {
		if (toastState[key]) { toastState[key].el.remove(); delete toastState[key]; }
	}

	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		const ta = document.createElement('textarea');
		ta.value = text;
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) { /* noop */ }
		ta.remove();
		return Promise.resolve();
	}

	// ── loading / error states ───────────────────────────────────────────

	/** Swap a tbody/list's loading row for an error row with a retry button. */
	function showLoadError(container, colSpan, retry) {
		container.innerHTML = '';
		const tr = document.createElement('tr');
		const td = document.createElement('td');
		td.colSpan = colSpan;
		td.className = 'wc-load-error';
		td.appendChild(document.createTextNode(t('err_load', 'Could not load. ')));
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'wc-btn-secondary wc-btn-inline';
		btn.textContent = t('retry', 'Try again');
		btn.addEventListener('click', retry);
		td.appendChild(btn);
		tr.appendChild(td);
		container.appendChild(tr);
		container.removeAttribute('aria-busy');
	}

	// ── officer key handling ─────────────────────────────────────────────

	const LS_PRIV = 'wc_priv_wrapped';

	function storedKeyBlob(uid) {
		try { return localStorage.getItem(LS_PRIV + '_' + uid); } catch (e) { return null; }
	}
	function localSet(key, value) {
		try { localStorage.setItem(key, value); return true; } catch (e) { return false; }
	}
	function sessGet(key) {
		try { return sessionStorage.getItem(key); } catch (e) { return null; }
	}
	function sessSet(key, value) {
		try { sessionStorage.setItem(key, value); } catch (e) { /* storage blocked */ }
	}
	function sessDel(key) {
		try { sessionStorage.removeItem(key); } catch (e) { /* storage blocked */ }
	}

	let pendingUnlock = null;
	// Listeners bind exactly once per page load; the in-flight prompt's state
	// lives in unlockCtx so a second prompt never stacks stale handlers.
	let unlockCtx = null;

	/**
	 * Resolve the officer's unwrapped private key. Uses the session-cached
	 * passphrase first; otherwise shows the inline unlock panel and resolves
	 * only when the passphrase verifies — never a modal window.prompt.
	 */
	function unlockPrivateKey() {
		const uid = boot().wcUid;
		const blob = storedKeyBlob(uid);
		if (!blob) return Promise.resolve(null);
		const cached = sessGet('wc_pass');
		if (cached) {
			return window.WcCrypto.unwrapPrivateKey(blob, cached).then((priv) => {
				if (priv) return priv;
				sessDel('wc_pass');
				return promptInline(blob);
			});
		}
		return promptInline(blob);
	}

	function promptInline(blob) {
		if (pendingUnlock) return pendingUnlock;
		const panel = document.querySelector('[data-wc-unlock]');
		if (!panel) return Promise.resolve(null);
		const input = panel.querySelector('[data-wc-unlock-pass]');
		const err = panel.querySelector('[data-wc-unlock-err]');
		const btn = panel.querySelector('[data-wc-unlock-go]');
		if (!input || !btn) return Promise.resolve(null);
		pendingUnlock = new Promise((resolve) => { unlockCtx = { blob, resolve, busy: false }; });
		if (!panel.dataset.wcBound) {
			panel.dataset.wcBound = '1';
			const attempt = async () => {
				const ctx = unlockCtx;
				if (!ctx || ctx.busy) return;
				const pass = input.value;
				if (!pass) { input.focus(); return; }
				ctx.busy = true;
				btn.disabled = true;
				if (err) err.hidden = true;
				input.removeAttribute('aria-invalid');
				try {
					const priv = await window.WcCrypto.unwrapPrivateKey(ctx.blob, pass);
					if (priv) {
						sessSet('wc_pass', pass);
						input.value = '';
						panel.hidden = true;
						pendingUnlock = null;
						unlockCtx = null;
						ctx.resolve(priv);
					} else if (err) {
						err.textContent = t('err_pass', 'Wrong passphrase — try again.');
						err.hidden = false;
						input.setAttribute('aria-invalid', 'true');
						input.select();
					}
				} catch (e) {
					if (err) {
						err.textContent = t('err_generic', 'Something went wrong. Please try again.');
						err.hidden = false;
					}
				} finally {
					ctx.busy = false;
					btn.disabled = false;
				}
			};
			btn.addEventListener('click', attempt);
			input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); attempt(); } });
		}
		panel.hidden = false;
		input.value = '';
		if (err) { err.hidden = true; err.textContent = ''; }
		input.removeAttribute('aria-invalid');
		input.focus();
		return pendingUnlock;
	}

	async function pubKey(uid) {
		const s = await window.WcCrypto.sodium();
		let officers = [];
		try { officers = JSON.parse(boot().wcOfficers || '[]'); } catch (e) { return null; }
		const me = officers.find((o) => o.uid === uid);
		try {
			return me && me.pubkey ? s.from_base64(me.pubkey, s.base64_variants.ORIGINAL) : null;
		} catch (e) {
			return null;
		}
	}

	// ── inbox ────────────────────────────────────────────────────────────

	// Latest initInbox call wins: the unlock-triggered re-render must not be
	// overwritten by an older in-flight fetch resolving after it.
	let inboxSeq = 0;

	async function initInbox(root) {
		const tbody = root.querySelector('[data-wc-inbox]');
		if (!tbody) return;
		const seq = ++inboxSeq;
		const me = boot().wcUid;

		// Never block the list on the unlock prompt: try the session-cached
		// passphrase silently; otherwise show the panel and re-render titles
		// once the officer unlocks.
		let priv = null;
		const blob = storedKeyBlob(me);
		if (blob) {
			const cached = sessGet('wc_pass');
			if (cached) {
				priv = await window.WcCrypto.unwrapPrivateKey(blob, cached);
				if (!priv) sessDel('wc_pass');
			}
			if (!priv) {
				unlockPrivateKey().then((k) => { if (k && seq === inboxSeq) initInbox(root); }).catch(() => {});
			}
		}

		try {
			const data = await api('/api/inbox');
			if (seq !== inboxSeq) return;
			tbody.innerHTML = '';
			tbody.removeAttribute('aria-busy');
			const myPub = priv ? await pubKey(me) : null;
			for (const c of (data.cases || [])) {
				if (seq !== inboxSeq) return;
				tbody.appendChild(await inboxRow(c, priv, myPub));
			}
			if (!data.cases || data.cases.length === 0) {
				const tr = document.createElement('tr');
				const td = document.createElement('td');
				td.colSpan = 5;
				td.textContent = t('inbox_empty', 'No cases yet.');
				tr.appendChild(td);
				tbody.appendChild(tr);
			}
			const trunc = root.querySelector('[data-wc-truncated]');
			if (trunc) trunc.hidden = !data.truncated;
		} catch (e) {
			if (seq === inboxSeq) showLoadError(tbody, 5, () => initInbox(root));
		}
	}

	async function inboxRow(c, priv, myPub) {
		const tr = document.createElement('tr');
		tr.dataset.caseId = c.id;

		const titleTd = document.createElement('td');
		titleTd.className = 'wc-col-title';
		titleTd.dataset.label = t('col_case', 'Case');
		let title = t('case_fallback_title', 'Case #') + c.id;
		if (priv && c.wrap && c.title_cipher && myPub) {
			const key = await window.WcCrypto.openSealed(c.wrap, myPub, priv);
			if (key) {
				const dec = await window.WcCrypto.open(c.title_cipher, key);
				if (dec) title = dec;
			}
		} else if (!c.wrap || !priv) {
			title += ' — ' + t('locked', 'locked');
		}
		// A real anchor in the title cell carries the native semantics
		// (keyboard activation, open-in-new-tab, screen-reader "link") while
		// the row click stays a pointer convenience — no fake role on <tr>.
		const link = document.createElement('a');
		link.href = base + '/cases/' + c.id;
		link.className = 'wc-case-link';
		link.textContent = title;
		titleTd.appendChild(link);
		tr.appendChild(titleTd);

		const statusTd = document.createElement('td');
		statusTd.dataset.label = t('col_status', 'Status');
		const badge = document.createElement('span');
		badge.className = 'wc-status wc-status-' + c.status;
		badge.textContent = t('status_' + c.status, c.status);
		statusTd.appendChild(badge);
		if (c.overdue) {
			const o = document.createElement('span');
			o.className = 'wc-badge-overdue';
			o.textContent = ' ' + t('overdue', 'OVERDUE');
			statusTd.appendChild(o);
		}
		tr.appendChild(statusTd);

		const dueTd = document.createElement('td');
		dueTd.dataset.label = t('col_due', 'Feedback due');
		const due = c.feedback_due ?? '—';
		dueTd.textContent = due + (c.days_until_due !== null && c.days_until_due !== undefined ? ' (' + c.days_until_due + 'd)' : '');
		tr.appendChild(dueTd);

		const recvTd = document.createElement('td');
		recvTd.dataset.label = t('col_received', 'Received');
		recvTd.textContent = String(c.received_at || '—').slice(0, 10);
		tr.appendChild(recvTd);

		const assTd = document.createElement('td');
		assTd.dataset.label = t('col_assigned', 'Assigned');
		assTd.textContent = c.assigned_to || '—';
		tr.appendChild(assTd);

		// Pointer convenience only — the title link already provides
		// keyboard/AT activation; skip it so a link click doesn't fire twice.
		tr.addEventListener('click', (e) => {
			if (e.target.closest('a, button')) return;
			location.href = base + '/cases/' + c.id;
		});
		return tr;
	}

	// ── case view ────────────────────────────────────────────────────────

	async function initCase(root) {
		const caseId = parseInt(root.dataset.wcCaseId, 10);
		const body = root.querySelector('[data-wc-case-body]');
		const loading = root.querySelector('[data-wc-loading]');
		const done = () => { if (loading) loading.hidden = true; body.removeAttribute('aria-busy'); };

		const showError = (msg) => {
			const errEl = document.createElement('p');
			errEl.className = 'wc-error';
			errEl.setAttribute('role', 'alert');
			errEl.textContent = msg;
			body.prepend(errEl);
		};

		let data;
		try {
			data = await api('/api/cases/' + caseId);
		} catch (e) {
			done();
			showError((e.payload && e.payload.message) || t('err_generic', 'Something went wrong. Please try again.'));
			return;
		}
		// Fetch is done — the visible blocker is now the unlock panel
		// (user action), not the spinner. aria-busy must reflect that.
		done();
		// Action buttons stay inert until decryption succeeds and
		// bindCaseActions wires their handlers — otherwise a click before
		// unlock would fire an unbound native form submit and lose the text.
		body.querySelectorAll('form [type=submit], [data-wc-status], [data-wc-assign]').forEach((el) => { el.disabled = true; });
		const me = boot().wcUid;
		root.dataset.revision = data.revision;
		renderClosePending(root, data.close_requested_by, data.close_requested_to, me);
		// Plaintext chrome renders immediately — it doesn't need the key.
		const badge = root.querySelector('[data-wc-status-badge]');
		if (badge) { badge.textContent = t('status_' + data.status, data.status); badge.className = 'wc-status wc-status-' + data.status; }
		const assignSel = root.querySelector('[data-wc-assign]');
		if (assignSel) assignSel.value = data.assigned_to || '';

		const priv = await unlockPrivateKey();
		if (!priv) {
			toast('error', t('err_nokey', 'Unlock your key to read this case.'));
			return;
		}
		// No wrap row = the case was never granted to this officer's key
		// (e.g. key rotated without re-wrap) — a grant problem, not a
		// decryption failure.
		if (!data.wrap) {
			showError(t('err_nowrap', 'This case is not encrypted for your key — another officer must re-grant access.'));
			return;
		}
		const pk = await pubKey(me);
		const caseKey = pk ? await window.WcCrypto.openSealed(data.wrap, pk, priv) : null;
		if (!caseKey) {
			showError(t('err_decrypt', 'Decryption failed.'));
			return;
		}

		if (data.title_cipher) {
			const title = await window.WcCrypto.open(data.title_cipher, caseKey);
			const h = root.querySelector('.wc-case-title');
			if (h && title) h.textContent = title;
		}
		if (data.contact_cipher) {
			const c = await window.WcCrypto.open(data.contact_cipher, caseKey);
			const el = root.querySelector('[data-wc-contact]');
			if (el && c) { el.textContent = c; el.closest('[data-wc-contact-block]').hidden = false; }
		}

		const list = root.querySelector('[data-wc-messages]');
		for (const m of (data.messages || [])) {
			const plain = await window.WcCrypto.open(m.cipher, caseKey);
			list.appendChild(renderMsg(m.direction, plain === 'wchk:receipt' ? t('receipt_text', 'Receipt confirmed.') : plain, m.at, m.author));
		}
		const attList = root.querySelector('[data-wc-attachments]');
		const attachments = data.attachments || [];
		if (attachments.length === 0) {
			const li = document.createElement('li');
			li.className = 'wc-hint';
			li.textContent = t('no_attachments', 'No attachments.');
			attList.appendChild(li);
		}
		for (const a of attachments) {
			const name = a.name_cipher ? await window.WcCrypto.open(a.name_cipher, caseKey) : 'attachment #' + a.id;
			const li = document.createElement('li');
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'wc-attachment';
			btn.textContent = '⬇ ' + name + ' (' + a.mime + ')';
			btn.addEventListener('click', async () => {
				const bytes = await window.WcCrypto.openBytes(a.cipher, caseKey);
				if (!bytes) { toast('error', t('err_decrypt', 'Decryption failed.')); return; }
				const blob = new Blob([bytes], { type: a.mime });
				const url = URL.createObjectURL(blob);
				const link = document.createElement('a');
				link.href = url;
				link.download = name || 'attachment';
				link.click();
				URL.revokeObjectURL(url);
			});
			li.appendChild(btn);
			attList.appendChild(li);
		}
		for (const n of (data.notes || [])) {
			const plain = await window.WcCrypto.open(n.cipher, caseKey);
			root.querySelector('[data-wc-notes]').appendChild(renderMsg('note', plain, n.at, n.author));
		}

		bindCaseActions(root, data, caseKey);
	}

	function renderMsg(dir, text, at, author) {
		const li = document.createElement('li');
		li.className = 'wc-msg wc-msg-' + (dir === 'reporter' ? 'them' : dir === 'note' ? 'note' : 'me');
		const p = document.createElement('p');
		p.textContent = text || '…'; // textContent only — NN-05
		const meta = document.createElement('span');
		meta.className = 'wc-msg-meta';
		meta.textContent = (author ? author + ' · ' : '') + at;
		li.appendChild(p); li.appendChild(meta);
		return li;
	}

	function renderClosePending(root, by, to, me) {
		const el = root.querySelector('[data-wc-close-pending]');
		if (!el) return;
		if (!by) { el.hidden = true; return; }
		el.hidden = false;
		const label = t('status_' + to, to);
		const raw = by === me
			? t('close_pending_self', '"{t}" requested — a second officer must confirm.')
			: t('close_pending_other', '"{t}" requested by {by} — click the same button to confirm.');
		el.querySelector('[data-wc-close-pending-text]').textContent =
			raw.replace('{t}', label).replace('{by}', by);
	}

	function bindCaseActions(root, data, caseKey) {
		const me = boot().wcUid;
		let revision = data.revision;
		const refreshRev = (d) => { revision = d.revision; root.dataset.revision = revision; };
		// initCase disabled all action controls until this point — handlers
		// are being wired now, so everything can go live.
		root.querySelectorAll('form [type=submit], [data-wc-status], [data-wc-assign]').forEach((el) => { el.disabled = false; });

		const replyForm = root.querySelector('[data-wc-reply-form]');
		if (replyForm) {
			replyForm.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const btn = replyForm.querySelector('[type=submit]');
				const text = replyForm.querySelector('[name=body]').value.trim();
				if (!text) return;
				btn.disabled = true;
				try {
					const cipher = await window.WcCrypto.seal(text, caseKey);
					const res = await api('/api/cases/' + data.id + '/messages', { method: 'POST', body: { cipher, revision } });
					refreshRev(res);
					replyForm.querySelector('[name=body]').value = '';
					root.querySelector('[data-wc-messages]').appendChild(renderMsg('officer', text, new Date().toISOString(), me));
				} catch (e) {
					toast('error', e.payload && e.status === 409 ? t('err_conflict', 'Someone else changed this case — reloading.') : e.message);
					if (e.status === 409) location.reload();
				} finally {
					btn.disabled = false;
				}
			});
		}

		const noteForm = root.querySelector('[data-wc-note-form]');
		if (noteForm) {
			noteForm.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const btn = noteForm.querySelector('[type=submit]');
				const text = noteForm.querySelector('[name=note]').value.trim();
				if (!text) return;
				btn.disabled = true;
				try {
					const cipher = await window.WcCrypto.seal(text, caseKey);
					await api('/api/cases/' + data.id + '/notes', { method: 'POST', body: { cipher } });
					noteForm.querySelector('[name=note]').value = '';
					root.querySelector('[data-wc-notes]').appendChild(renderMsg('note', text, new Date().toISOString(), me));
					toast('success', t('note_added', 'Note saved.'));
				} catch (e) {
					toast('error', e.message);
				} finally {
					btn.disabled = false;
				}
			});
		}

		root.querySelectorAll('[data-wc-status]').forEach((btn) => {
			btn.addEventListener('click', async () => {
				if (btn.disabled) return;
				btn.disabled = true;
				try {
					const res = await api('/api/cases/' + data.id + '/status', { method: 'PUT', body: { status: btn.dataset.wcStatus, revision } });
					refreshRev(res);
					const badge = root.querySelector('[data-wc-status-badge]');
					if (badge) { badge.textContent = t('status_' + res.status, res.status); badge.className = 'wc-status wc-status-' + res.status; }
					renderClosePending(root, res.close_requested_by, res.close_requested_to, me);
					toast('success', res.close_requested_by ? t('close_pending_toast', 'Request recorded — a second officer must confirm.') : t('status_saved', 'Status updated.'));
				} catch (e) {
					if (e.status === 409) { toast('error', t('err_conflict', 'Changed elsewhere — reloading.')); location.reload(); }
					else toast('error', e.message);
				} finally {
					btn.disabled = false;
				}
			});
		});

		const holdForm = root.querySelector('[data-wc-hold-form]');
		if (holdForm) {
			holdForm.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const btn = holdForm.querySelector('[type=submit]');
				if (btn) btn.disabled = true;
				try {
					const hold = holdForm.querySelector('[name=hold]').checked;
					const reason = holdForm.querySelector('[name=reason]').value;
					const res = await api('/api/cases/' + data.id + '/hold', { method: 'PUT', body: { hold, reason, revision } });
					refreshRev(res);
					toast('success', t('hold_saved', 'Saved.'));
				} catch (e) {
					if (e.status === 409) { toast('error', t('err_conflict', 'Changed elsewhere — reloading.')); location.reload(); }
					else toast('error', e.message);
				} finally {
					if (btn) btn.disabled = false;
				}
			});
		}

		const assignSel = root.querySelector('[data-wc-assign]');
		if (assignSel) {
			let lastAssigned = data.assigned_to || '';
			assignSel.addEventListener('change', async () => {
				assignSel.disabled = true;
				try {
					const res = await api('/api/cases/' + data.id + '/assign', { method: 'PUT', body: { uid: assignSel.value || null, revision } });
					refreshRev(res);
					lastAssigned = assignSel.value;
					toast('success', t('assigned', 'Assignment saved.'));
				} catch (e) {
					assignSel.value = lastAssigned;
					if (e.status === 409) { toast('error', t('err_conflict', 'Changed elsewhere — reloading.')); location.reload(); }
					else toast('error', e.message);
				} finally {
					assignSel.disabled = false;
				}
			});
		}
	}

	// ── officers page ────────────────────────────────────────────────────

	async function initOfficers(root) {
		const me = boot().wcUid;
		const panel = root.querySelector('[data-wc-keyreg]');
		const genBtn = root.querySelector('[data-wc-genkey]');
		let officers = [];
		try { officers = JSON.parse(boot().wcOfficers || '[]'); } catch (e) { officers = []; }
		const meRec = officers.find((o) => o.uid === me && o.status === 'active');
		const rotating = !!(meRec && meRec.pubkey);
		const hasBlob = !!storedKeyBlob(me);

		// Retry via showLoadError re-runs this init — the keygen listener must
		// never bind twice or one click would race two registrations and could
		// store a wrapped private key that matches the losing POST's pubkey.
		if (!panel || !genBtn || genBtn.dataset.wcBound) {
			return loadOfficerList(root);
		}
		genBtn.dataset.wcBound = '1';
		const err = panel.querySelector('[data-wc-keyreg-err]');

		// Panel is always visible — setup wording for new keys, rotation
		// wording + current-pass field for existing ones. Orphaning is an
		// explicit, informed click, never a surprise.
		if (rotating) {
			panel.querySelector('[data-wc-keyreg-title]').textContent = t('keyreg_rotate_title', 'Rotate your key');
			panel.querySelector('[data-wc-keyreg-copy]').textContent = t('keyreg_rotate_copy',
				'Rotating creates a fresh keypair and re-encrypts every case you can read. Other officers are unaffected.');
			genBtn.textContent = t('keyreg_rotate_btn', 'Rotate my key');
			const warn = panel.querySelector('[data-wc-rotate-warn]');
			if (!hasBlob) {
				warn.textContent = t('keyreg_orphan_warn',
					'Your current key is not on this device — rotating now locks you out of your cases until another officer re-grants access.');
				warn.hidden = false;
			} else {
				panel.querySelector('[data-wc-oldpass-wrap]').hidden = false;
			}
		}

		function keyError(msg, input) {
			if (err) { err.textContent = msg; err.hidden = false; }
			if (input) { input.setAttribute('aria-invalid', 'true'); input.focus(); }
		}

		let allowPartial = false;
		genBtn.addEventListener('click', async () => {
			if (err) err.hidden = true;
			panel.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
			const p1 = panel.querySelector('[data-wc-newpass]');
			const p2 = panel.querySelector('[data-wc-newpass2]');
			const pass = p1.value;
			if (pass.length < 10) { keyError(t('err_passweak', 'Passphrase needs at least 10 characters.'), p1); return; }
			if (pass !== p2.value) { keyError(t('err_passmismatch', 'Passphrases do not match.'), p2); return; }

			genBtn.disabled = true;
			try {
				const kp = await window.WcCrypto.officerKeypair();
				const body = { pubkey: kp.pubkey };
				if (rotating) {
					const held = (await api('/api/my-wraps')).wraps || [];
					const blob = storedKeyBlob(me);
					const oldPassField = panel.querySelector('[data-wc-oldpass]');
					const oldPass = sessGet('wc_pass') || (oldPassField ? oldPassField.value : '');
					const oldPub = await pubKey(me);
					let rewraps = null;
					if (blob && oldPass && oldPub) {
						const oldPriv = await window.WcCrypto.unwrapPrivateKey(blob, oldPass);
						if (!oldPriv) { keyError(t('err_pass', 'Wrong passphrase — try again.'), oldPassField); return; }
						rewraps = {};
						let failed = 0;
						for (const w of held) {
							const ck = await window.WcCrypto.openSealed(w.wrapped_key, oldPub, oldPriv);
							if (ck === null) { failed++; continue; }
							rewraps[w.case_id] = await window.WcCrypto.sealToPubkey(ck, kp.pubkey);
						}
						if (failed > 0 && !allowPartial) {
							keyError(t('rotate_partial', '{n} case(s) could not be re-wrapped — click "Rotate my key" again to rotate anyway.').replace('{n}', String(failed)));
							allowPartial = true;
							return;
						}
					}
					if (rewraps === null && held.length > 0 && !allowPartial) {
						if (blob && !oldPass) {
							// Key IS on this device — the officer just left the
							// passphrase empty. Demand it explicitly instead of
							// the misleading "not on this device" orphan path.
							keyError(t('rotate_need_oldpass',
								'Enter your current passphrase to keep access to your cases. If you have forgotten it, click "Rotate my key" again — you will lose access to {n} case(s) until another officer re-grants them.').replace('{n}', String(held.length)),
								oldPassField);
						} else {
							keyError(t('rotate_orphan_confirm',
								'Your current key is not on this device — rotating locks you out of {n} case(s) until another officer re-grants access. Click again to confirm.').replace('{n}', String(held.length)));
						}
						allowPartial = true;
						return;
					}
					if (rewraps !== null) body.wraps = rewraps;
				}

				const wrapped = await window.WcCrypto.wrapPrivateKey(kp.privkey, pass);
				const blobKey = LS_PRIV + '_' + me;
				const oldBlob = storedKeyBlob(me);
				// Write the new wrapped key BEFORE committing the pubkey — if
				// localStorage is blocked we keep the old server+client pair
				// intact instead of bricking the officer with a server-side
				// pubkey nobody holds the private key for.
				if (!localSet(blobKey, wrapped)) {
					keyError(t('err_storage', 'Could not store the key in this browser — enable site storage and try again.'), p1);
					return;
				}
				try {
					await api('/api/officers/key', { method: 'POST', body });
				} catch (e) {
					if (oldBlob !== null) localSet(blobKey, oldBlob);
					throw e;
				}
				sessSet('wc_pass', pass);
				toast('success', t('key_saved', 'Key registered. Keep your passphrase safe.'));
				location.reload();
			} catch (e) {
				toast('error', e.message || t('err_generic', 'Something went wrong.'));
			} finally {
				genBtn.disabled = false;
			}
		});

		await loadOfficerList(root);
	}

	async function loadOfficerList(root) {
		const me = boot().wcUid;
		const list = root.querySelector('[data-wc-officers]');
		if (!list) return;
		try {
			const data = await api('/api/officers');
			list.innerHTML = '';
			list.removeAttribute('aria-busy');
			for (const o of (data.officers || [])) {
				const tr = document.createElement('tr');
				['uid', 'fingerprint', 'status', 'key_version'].forEach((k) => {
					const td = document.createElement('td');
					td.textContent = k === 'fingerprint' ? String(o[k]).slice(0, 16) + '…' : String(o[k] ?? '');
					tr.appendChild(td);
				});
				const td = document.createElement('td');
				if (o.status === 'active' && o.uid !== me) {
					const btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'wc-btn-danger';
					btn.textContent = t('revoke', 'Revoke');
					btn.addEventListener('click', async () => {
						if (btn.disabled) return;
						if (!window.confirm(t('confirm_revoke', 'Revoke this officer? You will re-wrap all cases afterwards.'))) return;
						btn.disabled = true;
						try {
							await api('/api/officers/' + encodeURIComponent(o.uid), { method: 'DELETE' });
							location.reload();
						} catch (e) {
							btn.disabled = false;
							toast('error', e.message || t('err_generic', 'Something went wrong. Please try again.'));
						}
					});
					td.appendChild(btn);
				}
				tr.appendChild(td);
				list.appendChild(tr);
			}
		} catch (e) {
			showLoadError(list, 5, () => loadOfficerList(root));
		}
	}

	// ── settings (CAS) ───────────────────────────────────────────────────

	function initSettings(root) {
		const form = root.querySelector('[data-wc-settings-form]');
		if (!form) return;
		form.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			const btn = form.querySelector('[type=submit]');
			if (btn) btn.disabled = true;
			const values = {};
			const revisions = {};
			form.querySelectorAll('[data-wc-config]').forEach((el) => {
				const key = el.dataset.wcConfig;
				values[key] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
				revisions[key] = parseInt(el.dataset.wcRevision || '0', 10);
			});
			try {
				const res = await api('/api/settings', { method: 'PUT', body: { values, revisions } });
				form.querySelectorAll('[data-wc-config]').forEach((el) => {
					if (res.revisions[el.dataset.wcConfig] !== undefined) {
						el.dataset.wcRevision = res.revisions[el.dataset.wcConfig];
					}
				});
				toast('success', t('settings_saved', 'Settings saved.'));
			} catch (e) {
				if (e.status === 409) { toast('error', t('err_conflict', 'Settings changed elsewhere — reloading.')); location.reload(); }
				else toast('error', e.message);
			} finally {
				if (btn) btn.disabled = false;
			}
		});
	}

	// ── categories ───────────────────────────────────────────────────────

	async function initCategories(root) {
		const form = root.querySelector('[data-wc-cat-form]');
		const list = root.querySelector('[data-wc-cats]');
		const reload = async () => {
			if (!list) return;
			try {
				const data = await api('/api/categories');
				list.innerHTML = '';
				list.removeAttribute('aria-busy');
				for (const c of (data.categories || [])) {
					const tr = document.createElement('tr');
					const lde = document.createElement('td'); lde.textContent = c.label.de || '';
					const len = document.createElement('td'); len.textContent = c.label.en || '';
					const st = document.createElement('td'); st.textContent = c.archived ? t('archived', 'archived') : t('active', 'active');
					const act = document.createElement('td');
					if (!c.archived) {
						const b = document.createElement('button');
						b.type = 'button';
						b.className = 'wc-btn-secondary wc-btn-inline';
						b.textContent = t('archive', 'Archive');
						b.addEventListener('click', async () => {
							if (b.disabled) return;
							b.disabled = true;
							try {
								await api('/api/categories/' + c.id, { method: 'DELETE' });
								reload();
							} catch (e) {
								b.disabled = false;
								toast('error', e.message || t('err_generic', 'Something went wrong. Please try again.'));
							}
						});
						act.appendChild(b);
					}
					tr.append(lde, len, st, act);
					list.appendChild(tr);
				}
			} catch (e) {
				showLoadError(list, 4, reload);
			}
		};
		if (form && !form.dataset.wcBound) {
			form.dataset.wcBound = '1';
			form.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const btn = form.querySelector('[type=submit]');
				const de = form.querySelector('[name=label_de]').value.trim();
				const en = form.querySelector('[name=label_en]').value.trim();
				if (!de || !en) return;
				if (btn) btn.disabled = true;
				try {
					await api('/api/categories', { method: 'POST', body: { labels: { de, en } } });
					form.reset();
					reload();
				} catch (e) {
					toast('error', e.message || t('err_generic', 'Something went wrong. Please try again.'));
				} finally {
					if (btn) btn.disabled = false;
				}
			});
		}
		await reload();
	}

	// ── audit ────────────────────────────────────────────────────────────

	async function initAudit(root) {
		const tbody = root.querySelector('[data-wc-audit]');
		if (!tbody) return;
		try {
			const data = await api('/api/audit');
			const badge = root.querySelector('[data-wc-chain]');
			if (badge) {
				badge.textContent = data.chain_ok ? t('chain_ok', 'Chain intact') : t('chain_broken', 'CHAIN BROKEN');
				badge.classList.add(data.chain_ok ? 'wc-ok' : 'wc-bad');
			}
			tbody.innerHTML = '';
			tbody.removeAttribute('aria-busy');
			for (const e of (data.events || [])) {
				const tr = document.createElement('tr');
				[e.seq, e.event, e.actor || '—', e.case_id ?? '—', e.at].forEach((v) => {
					const td = document.createElement('td');
					td.textContent = String(v);
					tr.appendChild(td);
				});
				tbody.appendChild(tr);
			}
		} catch (e) {
			showLoadError(tbody, 5, () => initAudit(root));
		}
	}

	// ── stats ────────────────────────────────────────────────────────────

	async function initStats(root) {
		const body = root.querySelector('[data-wc-stats-body]');
		const loading = root.querySelector('[data-wc-loading]');
		try {
			const data = await api('/api/stats');
			if (loading) loading.hidden = true;
			if (body) body.removeAttribute('aria-busy');
			root.querySelector('[data-wc-total]').textContent = data.total;
			root.querySelector('[data-wc-overdue]').textContent = data.overdue;
			const tbody = root.querySelector('[data-wc-statstatus]');
			if (tbody) Object.keys(data.by_status || {}).forEach((s) => {
				const tr = document.createElement('tr');
				const a = document.createElement('td'); a.textContent = t('status_' + s, s);
				const b = document.createElement('td'); b.textContent = data.by_status[s];
				tr.append(a, b); tbody.appendChild(tr);
			});
			const cat = root.querySelector('[data-wc-statcat]');
			if (cat) for (const c of (data.by_category || [])) {
				const tr = document.createElement('tr');
				const a = document.createElement('td'); a.textContent = c.label.en || c.label.de || ('#' + c.id);
				const b = document.createElement('td');
				b.textContent = c.count === null ? '< ' + data.small_cell_floor : c.count; // small-cell suppression
				tr.append(a, b); cat.appendChild(tr);
			}
		} catch (e) {
			if (body) body.removeAttribute('aria-busy');
			if (loading) {
				loading.className = 'wc-load-error';
				loading.textContent = t('err_load', 'Could not load.') + ' ';
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'wc-btn-secondary wc-btn-inline';
				btn.textContent = t('retry', 'Try again');
				btn.addEventListener('click', () => initStats(root));
				loading.appendChild(btn);
			}
		}
	}

	// ── devices + verbal ─────────────────────────────────────────────────

	function initCompanion(root) {
		const form = root.querySelector('[data-wc-pair-form]');
		if (!form) return;
		const tokenPanel = root.querySelector('[data-wc-token-panel]');
		form.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			const btn = form.querySelector('[type=submit]');
			const name = form.querySelector('[name=name]').value.trim();
			if (!name) return;
			if (btn) btn.disabled = true;
			try {
				const res = await api('/api/devices', { method: 'POST', body: { name } });
				const out = root.querySelector('[data-wc-token]');
				out.textContent = res.token; // shown once, never stored server-side
				if (tokenPanel) {
					tokenPanel.hidden = false;
					const copyBtn = tokenPanel.querySelector('[data-wc-copy-token]');
					if (copyBtn) copyBtn.onclick = () => copyText(res.token).then(
						() => toast('success', t('copied', 'Copied.')),
						() => toast('error', t('err_copy', 'Copy failed — select and copy the token manually.')));
				}
				form.reset();
			} catch (e) {
				toast('error', e.message);
			} finally {
				if (btn) btn.disabled = false;
			}
		});
		const list = root.querySelector('[data-wc-devices]');
		if (!list) return;
		const loadDevices = () => api('/api/devices').then((d) => {
			list.innerHTML = '';
			list.removeAttribute('aria-busy');
			if (!d.devices || d.devices.length === 0) {
				const li = document.createElement('li');
				li.className = 'wc-hint';
				li.textContent = t('no_devices', 'No paired devices.');
				list.appendChild(li);
			}
			(d.devices || []).forEach((dev) => {
				const li = document.createElement('li');
				li.textContent = dev.name + ' — ' + (dev.last_seen || dev.created) + ' ';
				const b = document.createElement('button');
				b.type = 'button';
				b.className = 'wc-btn-secondary wc-btn-inline';
				b.textContent = t('revoke', 'Revoke');
				b.addEventListener('click', async () => {
					if (b.disabled) return;
					b.disabled = true;
					try {
						await api('/api/devices/' + dev.id, { method: 'DELETE' });
						li.remove();
						if (!list.children.length) loadDevices();
					} catch (e) {
						b.disabled = false;
						toast('error', e.message || t('err_generic', 'Something went wrong. Please try again.'));
					}
				});
				li.appendChild(b);
				list.appendChild(li);
			});
		}).catch(() => {
			list.innerHTML = '';
			list.removeAttribute('aria-busy');
			const li = document.createElement('li');
			li.className = 'wc-load-error';
			li.textContent = t('err_load', 'Could not load.') + ' ';
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'wc-btn-secondary wc-btn-inline';
			btn.textContent = t('retry', 'Try again');
			btn.addEventListener('click', loadDevices);
			li.appendChild(btn);
			list.appendChild(li);
		});
		loadDevices();
	}

	async function initVerbal(root) {
		try {
			const data = await api('/api/verbal-text');
			root.querySelector('[data-wc-verbal-de]').textContent = data.de;
			root.querySelector('[data-wc-verbal-en]').textContent = data.en;
		} catch (e) {
			toast('error', e.message || t('err_generic', 'Something went wrong. Please try again.'));
			return;
		}
		root.querySelectorAll('[data-wc-copy]').forEach((btn) => {
			btn.addEventListener('click', () => {
				const src = root.querySelector('[data-wc-verbal-' + btn.dataset.wcCopy + ']');
				if (!src || !src.textContent) return;
				copyText(src.textContent).then(
					() => toast('success', t('copied', 'Copied.')),
					() => toast('error', t('err_copy', 'Copy failed — select and copy the text manually.')));
			});
		});
	}

	// ── boot ─────────────────────────────────────────────────────────────

	document.addEventListener('DOMContentLoaded', () => {
		if (window.CheckFieldErrors) window.CheckFieldErrors.install({ prefix: 'wc' });
		const page = boot().wcPage;
		const root = document.getElementById('app-content') || document.body;
		const init = {
			inbox: initInbox, case: initCase, officers: initOfficers,
			settings: initSettings, categories: initCategories, audit: initAudit,
			stats: initStats, companion: initCompanion, verbal: initVerbal,
		}[page];
		if (init) init(root).catch((e) => toast('error', e.message || 'error'));
	});
})();
