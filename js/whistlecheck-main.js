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

	// Canonical status pill — wc-badge variant per state (dot + text, never
	// colour alone). Mirrors CaseService::STATUS.
	const STATUS_VARIANT = { received: 'info', in_review: 'warning', action_taken: 'success' };
	function paintStatusBadge(el, status) {
		const v = STATUS_VARIANT[status];
		el.className = 'wc-badge' + (v ? ' wc-badge--' + v : '');
		el.innerHTML = '';
		const dot = document.createElement('span');
		dot.className = 'wc-badge__dot';
		dot.setAttribute('aria-hidden', 'true');
		el.appendChild(dot);
		el.appendChild(document.createTextNode(t('status_' + status, status)));
	}
	function badgeEl(kind, text) {
		const b = document.createElement('span');
		b.className = 'wc-badge' + (kind ? ' wc-badge--' + kind : '');
		const dot = document.createElement('span');
		dot.className = 'wc-badge__dot';
		dot.setAttribute('aria-hidden', 'true');
		b.appendChild(dot);
		b.appendChild(document.createTextNode(text));
		return b;
	}

	// ── inbox ────────────────────────────────────────────────────────────

	// Latest initInbox call wins: the unlock-triggered re-render must not be
	// overwritten by an older in-flight fetch resolving after it.
	let inboxSeq = 0;
	// Fetched rows + decrypted titles cached per load so filtering never
	// re-hits the API or re-runs crypto.
	let inboxCases = [];
	let inboxPriv = null;
	let inboxMyPub = null;
	const inboxFilter = { q: '', status: '', mine: '' };

	function inboxMatches(c, me) {
		if (inboxFilter.status && c.status !== inboxFilter.status) return false;
		if (inboxFilter.mine === '1' && c.assigned_to !== me) return false;
		if (inboxFilter.q) {
			const hay = (c._title || '') + ' #' + c.id;
			if (hay.toLowerCase().indexOf(inboxFilter.q) < 0) return false;
		}
		return true;
	}

	function renderInbox(root, tbody, me) {
		const rows = inboxCases.filter((c) => inboxMatches(c, me));
		const countEl = root.querySelector('[data-wc-inbox-count]');
		if (countEl) {
			countEl.textContent = rows.length === inboxCases.length
				? t('count_cases', '{n} cases').replace('{n}', String(rows.length))
				: t('count_cases_of', '{n} of {m} cases').replace('{n}', String(rows.length)).replace('{m}', String(inboxCases.length));
		}
		tbody.innerHTML = '';
		if (rows.length === 0) {
			const tr = document.createElement('tr');
			const td = document.createElement('td');
			td.colSpan = 5;
			const empty = document.createElement('div');
			empty.className = 'wc-empty-state';
			const h = document.createElement('p');
			h.className = 'wc-empty-state__title';
			h.textContent = inboxCases.length === 0
				? t('inbox_empty_title', 'No cases yet.')
				: t('inbox_empty_filtered', 'No cases match the filter.');
			const p = document.createElement('p');
			p.className = 'wc-empty-state__text';
			p.textContent = inboxCases.length === 0
				? t('inbox_empty_text', 'New reports appear here as soon as they are submitted.')
				: t('inbox_empty_filtered_hint', 'Clear the filter to see all cases again.');
			empty.append(h, p);
			td.appendChild(empty);
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		for (const c of rows) {
			tbody.appendChild(inboxRow(c));
		}
	}

	function renderInboxStats(root, me) {
		const strip = root.querySelector('[data-wc-inbox-stats]');
		if (!strip) return;
		const open = inboxCases.filter((c) => c.status !== 'closed' && c.status !== 'archived').length;
		const overdue = inboxCases.filter((c) => !!c.overdue).length;
		const mine = inboxCases.filter((c) => c.assigned_to === me).length;
		const set = (sel, v) => { const el = strip.querySelector(sel); if (el) el.textContent = String(v); };
		set('[data-wc-stat-open]', open);
		set('[data-wc-stat-overdue]', overdue);
		set('[data-wc-stat-mine]', mine);
		strip.hidden = false;
	}

	async function initInbox(root) {
		const tbody = root.querySelector('[data-wc-inbox]');
		if (!tbody) return;
		const seq = ++inboxSeq;
		const me = boot().wcUid;

		const filterForm = root.querySelector('[data-wc-inbox-filter]');
		if (filterForm && !filterForm.dataset.wcBound) {
			filterForm.dataset.wcBound = '1';
			const apply = (v) => {
				inboxFilter.q = (v.q || '').trim().toLowerCase();
				inboxFilter.status = v.status || '';
				inboxFilter.mine = v.mine || '';
				renderInbox(root, tbody, me);
			};
			if (window.CheckViewComponents) {
				window.CheckViewComponents.bindListFilter(filterForm, apply);
			} else {
				// Fallback: plain submit/change without the shared helper.
				filterForm.addEventListener('submit', (e) => { e.preventDefault(); apply(Object.fromEntries(new FormData(filterForm))); });
			}
		}

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
		} else {
			// No key on this device at all — "locked" rows alone are a dead
			// end. Point the officer at the fix instead.
			const hint = root.querySelector('[data-wc-inbox-hint]');
			if (hint) {
				hint.textContent = t('nokey_hint', 'Your officer key is not on this device. Set it up on the Officers page to read cases.') + ' ';
				const a = document.createElement('a');
				a.href = base + '/officers';
				a.textContent = t('nokey_link', 'Open key settings');
				hint.appendChild(a);
			}
		}

		try {
			const data = await api('/api/inbox');
			if (seq !== inboxSeq) return;
			const myPub = priv ? await pubKey(me) : null;
			inboxPriv = priv;
			inboxMyPub = myPub;
			inboxCases = data.cases || [];
			// Decrypt titles once per fetch so filtering is a pure re-render.
			for (const c of inboxCases) {
				if (seq !== inboxSeq) return;
				c._title = await inboxTitle(c, priv, myPub);
			}
			tbody.removeAttribute('aria-busy');
			renderInboxStats(root, me);
			renderInbox(root, tbody, me);
			const trunc = root.querySelector('[data-wc-truncated]');
			if (trunc) trunc.hidden = !data.truncated;
		} catch (e) {
			if (seq === inboxSeq) showLoadError(tbody, 5, () => initInbox(root));
		}
	}

	async function inboxTitle(c, priv, myPub) {
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
		return title;
	}

	function inboxRow(c) {
		const tr = document.createElement('tr');
		tr.dataset.caseId = c.id;

		const titleTd = document.createElement('td');
		titleTd.className = 'wc-col-title';
		titleTd.dataset.cell = t('col_case', 'Case');
		const title = c._title || (t('case_fallback_title', 'Case #') + c.id);
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
		statusTd.dataset.cell = t('col_status', 'Status');
		const badge = badgeEl('', '');
		paintStatusBadge(badge, c.status);
		statusTd.appendChild(badge);
		if (c.overdue) {
			const o = badgeEl('danger', t('overdue', 'OVERDUE'));
			o.classList.add('wc-badge-overdue');
			statusTd.appendChild(o);
		}
		tr.appendChild(statusTd);

		const dueTd = document.createElement('td');
		dueTd.dataset.cell = t('col_due', 'Feedback due');
		const due = c.feedback_due ?? '—';
		dueTd.textContent = due + (c.days_until_due !== null && c.days_until_due !== undefined ? ' (' + c.days_until_due + 'd)' : '');
		tr.appendChild(dueTd);

		const recvTd = document.createElement('td');
		recvTd.dataset.cell = t('col_received', 'Received');
		recvTd.textContent = String(c.received_at || '—').slice(0, 10);
		tr.appendChild(recvTd);

		const assTd = document.createElement('td');
		assTd.dataset.cell = t('col_assigned', 'Assigned');
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

	// Mirrors CaseService::TRANSITIONS — the server stays authoritative;
	// this only hides buttons that can never succeed.
	const CASE_TRANSITIONS = {
		received: ['in_review', 'closed', 'archived'],
		in_review: ['action_taken', 'closed', 'archived'],
		action_taken: ['closed', 'archived'],
		closed: ['in_review', 'archived'],
		archived: [],
	};

	function renderStatusControls(root, status) {
		const group = root.querySelector('[data-wc-status-actions]');
		if (!group) return;
		const legal = CASE_TRANSITIONS[status] || [];
		let shown = 0;
		group.querySelectorAll('[data-wc-status]').forEach((btn) => {
			const show = legal.includes(btn.dataset.wcStatus);
			btn.hidden = !show;
			if (show) shown++;
		});
		group.hidden = shown === 0;
		const terminal = root.querySelector('[data-wc-status-terminal]');
		if (terminal) terminal.hidden = shown !== 0;
		// Archived is read-only server-side (reply → 409). Archive is terminal,
		// so this only ever *adds* the lock — never re-enables — which keeps
		// it safe to run before decryption wired the forms.
		if (status === 'archived') {
			const hint = root.querySelector('[data-wc-reply-archived]');
			if (hint) hint.hidden = false;
			const replyForm = root.querySelector('[data-wc-reply-form]');
			if (replyForm) replyForm.querySelectorAll('textarea, [type=submit]').forEach((el) => { el.disabled = true; });
		}
	}

	function renderCaseMeta(root, data) {
		const recv = root.querySelector('[data-wc-meta-received]');
		if (recv) recv.textContent = String(data.received_at || '—').slice(0, 10);
		const due = root.querySelector('[data-wc-meta-due]');
		if (due) due.textContent = data.feedback_due || '—';
		const terminal = data.status === 'closed' || data.status === 'archived';
		const overdue = !terminal && data.feedback_due && data.feedback_due < new Date().toISOString().slice(0, 10);
		const odBadge = root.querySelector('[data-wc-overdue-badge]');
		if (odBadge) odBadge.hidden = !overdue;
	}

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
		// Crypto-bound controls (reply/note submit) stay inert until
		// decryption succeeds and bindCryptoActions wires them. Metadata
		// actions (status, assign, hold, escalate) need NO case key —
		// they are bound immediately so a locked case is still workable.
		body.querySelectorAll('form [type=submit]').forEach((el) => { el.disabled = true; });
		const me = boot().wcUid;
		root.dataset.revision = data.revision;
		renderClosePending(root, data.close_requested_by, data.close_requested_to, me);
		initEscalation(root, caseId, data);
		// Plaintext chrome renders immediately — it doesn't need the key.
		const badge = root.querySelector('[data-wc-status-badge]');
		if (badge) paintStatusBadge(badge, data.status);
		const assignSel = root.querySelector('[data-wc-assign]');
		if (assignSel) assignSel.value = data.assigned_to || '';
		renderCaseMeta(root, data);
		renderStatusControls(root, data.status);
		bindMetaActions(root, data, me);

		// Locked-content note — rendered up front so an officer who never
		// unlocks sees *why* the content area is empty, not a dead page.
		// Removed again when decryption succeeds.
		const lockNote = root.querySelector('[data-wc-locked-note]');
		const hasBlob = !!storedKeyBlob(me);
		const setLockNote = (text, linkOfficers) => {
			if (!lockNote) return;
			lockNote.textContent = text;
			if (linkOfficers) {
				lockNote.appendChild(document.createTextNode(' '));
				const a = document.createElement('a');
				a.href = base + '/officers';
				a.textContent = t('nokey_link', 'Open key settings');
				lockNote.appendChild(a);
			}
			lockNote.hidden = false;
		};
		if (!data.wrap || !hasBlob) {
			setLockNote(
				hasBlob
					? t('err_nowrap', 'This case is not encrypted for your key — another officer must re-grant access.')
					: t('nokey_hint', 'Your officer key is not on this device. Set it up on the Officers page to read cases.'),
				!hasBlob);
		} else {
			setLockNote(t('case_locked_note', 'Case contents are locked — unlock your key above to read them.'), false);
		}

		const priv = await unlockPrivateKey();
		if (!priv) return;
		// No wrap row = the case was never granted to this officer's key
		// (e.g. key rotated without re-wrap) — a grant problem, not a
		// decryption failure.
		if (!data.wrap) return;
		const pk = await pubKey(me);
		const caseKey = pk ? await window.WcCrypto.openSealed(data.wrap, pk, priv) : null;
		if (!caseKey) {
			setLockNote(t('err_decrypt', 'Decryption failed.'), false);
			return;
		}
		if (lockNote) lockNote.hidden = true;

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
			btn.textContent = '⬇ ' + name;
			btn.title = a.mime;
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

		bindCryptoActions(root, data, caseKey);
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

	/**
	 * One-way escalation export (FC-WCHK-EXPORT). Officer-authored text only —
	 * nothing from the decrypted case is read, copied, or prefilled here.
	 * Hidden entirely when the TicketCheck facade probe fails.
	 */
	function initEscalation(root, caseId, data) {
		const sec = root.querySelector('[data-wc-esc]');
		if (!sec) return;
		if (!data.escalate_available) { sec.hidden = true; return; }
		sec.hidden = false;

		const list = root.querySelector('[data-wc-esc-list]');
		const renderList = (items) => {
			list.replaceChildren();
			if (!items || items.length === 0) {
				const li = document.createElement('li');
				li.className = 'wc-esc-empty';
				li.textContent = t('esc_none', 'No escalations yet.');
				list.append(li);
				return;
			}
			for (const e of items) {
				const li = document.createElement('li');
				li.className = 'wc-esc-item';
				const num = document.createElement('strong');
				num.textContent = e.ticket_number;
				const meta = document.createElement('span');
				meta.className = 'wc-esc-meta';
				meta.textContent = ' ' + t('esc_by', 'by') + ' ' + e.officer_uid + ' · ' + e.created_at;
				li.append(num, meta);
				list.append(li);
			}
		};
		renderList(data.escalations || []);

		const toggle = root.querySelector('[data-wc-esc-toggle]');
		const form = root.querySelector('[data-wc-esc-form]');
		// Escalation is officer-authored metadata — it does not need the case
		// key. initCase blanket-disables form submits until decryption; undo
		// that here so exporting works even while the case stays locked.
		const escSubmit = form.querySelector('[type=submit]');
		if (escSubmit) escSubmit.disabled = false;
		let idemKey = null;
		// One key per form-open: a retry or double-submit replays the same
		// export server-side instead of minting a second ticket.
		toggle.addEventListener('click', () => {
			const opening = form.hidden;
			form.hidden = !opening;
			toggle.setAttribute('aria-expanded', String(opening));
			if (opening) {
				idemKey = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random());
				form.querySelector('[name=title]').focus();
			}
		});
		form.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			const btn = form.querySelector('[type=submit]');
			btn.disabled = true;
			try {
				const res = await api('/api/cases/' + caseId + '/escalate', {
					method: 'POST',
					body: {
						title: form.elements.title.value,
						description: form.elements.description.value,
						category: form.elements.category.value,
						priority: form.elements.priority.value,
						idemKey: idemKey,
					},
				});
				toast(res.replayed ? 'success' : 'success',
					res.replayed ? t('esc_replayed', 'This export was already sent.') : t('esc_created', 'Ticket created.'));
				data.escalations = [res.escalation].concat(data.escalations || []);
				renderList(data.escalations);
				form.reset();
				form.hidden = true;
				toggle.setAttribute('aria-expanded', 'false');
				idemKey = null;
			} catch (e) {
				toast('error', (e.payload && e.payload.message) || t('err_generic', 'Something went wrong. Please try again.'));
			} finally {
				btn.disabled = false;
			}
		});
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

	/**
	 * Metadata actions — status, assign, legal hold. None of these need the
	 * case key, so they must work even while the content stays locked.
	 * Revision is shared via root.dataset (crypto actions read it too).
	 */
	function bindMetaActions(root, data, me) {
		const refreshRev = (d) => { root.dataset.revision = d.revision; };
		const revision = () => parseInt(root.dataset.revision || '0', 10);

		root.querySelectorAll('[data-wc-status], [data-wc-assign], [data-wc-hold-form] [type=submit]').forEach((el) => { el.disabled = false; });

		root.querySelectorAll('[data-wc-status]').forEach((btn) => {
			btn.addEventListener('click', async () => {
				if (btn.disabled) return;
				btn.disabled = true;
				try {
					const res = await api('/api/cases/' + data.id + '/status', { method: 'PUT', body: { status: btn.dataset.wcStatus, revision: revision() } });
					refreshRev(res);
					const badge = root.querySelector('[data-wc-status-badge]');
					if (badge) paintStatusBadge(badge, res.status);
					renderCaseMeta(root, Object.assign({}, data, { status: res.status }));
					renderStatusControls(root, res.status);
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
					const res = await api('/api/cases/' + data.id + '/hold', { method: 'PUT', body: { hold, reason, revision: revision() } });
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
					const res = await api('/api/cases/' + data.id + '/assign', { method: 'PUT', body: { uid: assignSel.value || null, revision: revision() } });
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

	/**
	 * Crypto-bound actions — reply, internal note, re-grant. These need the
	 * decrypted case key; they only exist after a successful unlock.
	 */
	function bindCryptoActions(root, data, caseKey) {
		const me = boot().wcUid;
		const refreshRev = (d) => { root.dataset.revision = d.revision; };
		const revision = () => parseInt(root.dataset.revision || '0', 10);
		// Decryption succeeded — content forms go live.
		root.querySelectorAll('form [type=submit]').forEach((el) => { el.disabled = false; });

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
					const res = await api('/api/cases/' + data.id + '/messages', { method: 'POST', body: { cipher, revision: revision() } });
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

		// ── re-grant: officers who lost access (rotation without re-wrap) ──
		const grantSec = root.querySelector('[data-wc-grant]');
		const grantList = root.querySelector('[data-wc-grant-list]');
		if (grantSec && grantList) {
			let officers = [];
			try { officers = JSON.parse(boot().wcOfficers || '[]'); } catch (e) { officers = []; }
			const holders = new Set(data.granted_to || []);
			for (const o of officers) {
				if (!o.uid || o.uid === me || holders.has(o.uid) || !o.pubkey || !o.fingerprint) continue;
				const li = document.createElement('li');
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'wc-btn-secondary wc-btn-inline';
				btn.textContent = t('grant_btn', 'Grant access');
				const label = document.createElement('span');
				label.textContent = o.uid + ' ';
				li.appendChild(label);
				li.appendChild(btn);
				btn.addEventListener('click', async () => {
					if (btn.disabled) return;
					btn.disabled = true;
					try {
						let target = o;
						let res = null;
						for (let attempt = 0; attempt < 2; attempt++) {
							const wrap = await window.WcCrypto.sealToPubkey(caseKey, target.pubkey);
							try {
								res = await api('/api/officers/grants', { method: 'POST', body: { wraps: { [data.id + ':' + target.uid]: { wrap, fp: target.fingerprint } } } });
								break;
							} catch (e) {
								// Target rotated mid-grant — refetch the live key set once.
								if (e.status === 409 && e.payload && e.payload.error === 'STALE_OFFICER_KEY' && attempt === 0) {
									const fresh = await api('/api/officers');
									target = (fresh.officers || []).find((x) => x.uid === o.uid && x.status === 'active') || null;
									if (!target || !target.pubkey) throw e;
									continue;
								}
								throw e;
							}
						}
						li.remove();
						if (grantList.children.length === 0) grantSec.hidden = true;
						toast('success', t('grant_done', 'Access granted.'));
					} catch (e) {
						toast('error', e.message || t('err_generic', 'Something went wrong.'));
						btn.disabled = false;
					}
				});
				grantList.appendChild(li);
			}
			if (grantList.children.length > 0) grantSec.hidden = false;
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
			const officers = data.officers || [];
			if (officers.length === 0) {
				const tr = document.createElement('tr');
				const td = document.createElement('td');
				td.colSpan = 5;
				td.textContent = t('officers_empty', 'No officers yet.');
				tr.appendChild(td);
				list.appendChild(tr);
				return;
			}
			const colLabels = [t('col_user', 'User'), t('col_fingerprint', 'Fingerprint'), t('col_status', 'Status'), t('col_keyver', 'Key version')];
			for (const o of officers) {
				const tr = document.createElement('tr');
				// user cell: uid + "You" marker for the current officer
				const uidTd = document.createElement('td');
				uidTd.dataset.cell = colLabels[0];
				uidTd.appendChild(document.createTextNode(String(o.uid)));
				if (o.uid === me) {
					uidTd.appendChild(document.createTextNode(' '));
					uidTd.appendChild(badgeEl('info', t('you', 'You')));
				}
				tr.appendChild(uidTd);
				const fpTd = document.createElement('td');
				fpTd.dataset.cell = colLabels[1];
				const code = document.createElement('code');
				code.className = 'wc-fp';
				code.textContent = String(o.fingerprint).slice(0, 16) + '…';
				code.title = String(o.fingerprint);
				fpTd.appendChild(code);
				tr.appendChild(fpTd);
				const stTd = document.createElement('td');
				stTd.dataset.cell = colLabels[2];
				stTd.appendChild(badgeEl(o.status === 'active' ? 'success' : 'danger',
					o.status === 'active' ? t('active', 'Active') : t('status_revoked', 'Revoked')));
				tr.appendChild(stTd);
				const kvTd = document.createElement('td');
				kvTd.dataset.cell = colLabels[3];
				kvTd.textContent = String(o.key_version ?? '');
				tr.appendChild(kvTd);
				const td = document.createElement('td');
				td.dataset.cell = t('col_actions', 'Actions');
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
				const cats = data.categories || [];
				if (cats.length === 0) {
					const tr = document.createElement('tr');
					const td = document.createElement('td');
					td.colSpan = 4;
					const empty = document.createElement('div');
					empty.className = 'wc-empty-state';
					const p = document.createElement('p');
					p.className = 'wc-empty-state__text';
					p.textContent = t('cats_empty', 'No categories yet — add the first one below.');
					empty.appendChild(p);
					td.appendChild(empty);
					tr.appendChild(td);
					list.appendChild(tr);
					return;
				}
				const catCols = [t('col_label_de', 'Label (German)'), t('col_label_en', 'Label (English)'), t('col_status', 'Status')];
				for (const c of cats) {
					const tr = document.createElement('tr');
					const lde = document.createElement('td'); lde.dataset.cell = catCols[0]; lde.textContent = c.label.de || '';
					const len = document.createElement('td'); len.dataset.cell = catCols[1]; len.textContent = c.label.en || '';
					const st = document.createElement('td'); st.dataset.cell = catCols[2]; st.textContent = c.archived ? t('archived', 'archived') : t('active', 'active');
					const act = document.createElement('td');
					act.dataset.cell = t('col_actions', 'Actions');
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
			const chainBox = root.querySelector('[data-wc-chain]');
			if (chainBox) {
				chainBox.innerHTML = '';
				chainBox.classList.remove('wc-callout--success', 'wc-callout--danger');
				chainBox.classList.add(data.chain_ok ? 'wc-callout--success' : 'wc-callout--danger');
				const title = document.createElement('strong');
				title.className = 'wc-callout__title';
				title.textContent = data.chain_ok ? t('chain_ok', 'Chain intact') : t('chain_broken', 'CHAIN BROKEN');
				const txt = document.createElement('p');
				txt.textContent = data.chain_ok
					? t('chain_ok_text', 'All recorded events form an unbroken chain.')
					: t('chain_broken_text', 'The audit chain is broken — treat this log as untrusted and investigate before relying on it.');
				chainBox.append(title, txt);
			}
			const events = data.events || [];
			const countEl = root.querySelector('[data-wc-audit-count]');
			if (countEl) {
				countEl.textContent = t('audit_entries', '{n} entries').replace('{n}', String(events.length));
			}
			tbody.innerHTML = '';
			tbody.removeAttribute('aria-busy');
			const auditCols = ['#', t('col_event', 'Event'), t('col_actor', 'Actor'), t('col_case', 'Case'), t('col_time', 'Time')];
			for (const e of events) {
				const tr = document.createElement('tr');
				[e.seq, e.event, e.actor || '—', e.case_id ?? '—', e.at].forEach((v, i) => {
					const td = document.createElement('td');
					td.dataset.cell = auditCols[i];
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
			const openEl = root.querySelector('[data-wc-open]');
			if (openEl) {
				const bs = data.by_status || {};
				openEl.textContent = String(
					(bs.received || 0) + (bs.in_review || 0) + (bs.action_taken || 0));
			}
			const tbody = root.querySelector('[data-wc-statstatus]');
			if (tbody) Object.keys(data.by_status || {}).forEach((s) => {
				const tr = document.createElement('tr');
				const a = document.createElement('td'); a.dataset.cell = t('col_status', 'Status'); a.textContent = t('status_' + s, s);
				const b = document.createElement('td'); b.dataset.cell = t('col_count', 'Count'); b.textContent = data.by_status[s];
				tr.append(a, b); tbody.appendChild(tr);
			});
			const cat = root.querySelector('[data-wc-statcat]');
			if (cat) for (const c of (data.by_category || [])) {
				const tr = document.createElement('tr');
				const a = document.createElement('td'); a.dataset.cell = t('col_category', 'Category'); a.textContent = c.label.en || c.label.de || ('#' + c.id);
				const b = document.createElement('td'); b.dataset.cell = t('col_count', 'Count');
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

	// ── verbal ───────────────────────────────────────────────────────────

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
				if (!src || !src.textContent) {
					toast('error', t('err_load', 'Could not load.'));
					return;
				}
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
			stats: initStats, verbal: initVerbal,
		}[page];
		// Promise.resolve: initSettings is sync — a bare .catch on its
		// undefined return throws a pageerror on every settings load.
		if (init) Promise.resolve(init(root)).catch((e) => toast('error', e.message || 'error'));
	});
})();
