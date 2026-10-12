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
				{ 'Accept': 'application/json' },
				opts.body ? { 'Content-Type': 'application/json' } : {},
				opts.method && opts.method !== 'GET' ? { 'requesttoken': OC.requestToken } : {}),
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
			host.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite');
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

	// ── officer key handling ─────────────────────────────────────────────

	const LS_PRIV = 'wc_priv_wrapped';

	function storedKeyBlob(uid) {
		try { return localStorage.getItem(LS_PRIV + '_' + uid); } catch (e) { return null; }
	}

	async function unlockPrivateKey() {
		const uid = boot().dataset.wcUid;
		const blob = storedKeyBlob(uid);
		if (!blob) return null;
		const pass = sessionStorage.getItem('wc_pass');
		if (!pass) {
			const entered = window.prompt(t('prompt_pass', 'Enter your WhistleCheck passphrase'));
			if (!entered) return null;
			sessionStorage.setItem('wc_pass', entered);
		}
		const priv = await window.WcCrypto.unwrapPrivateKey(blob, sessionStorage.getItem('wc_pass'));
		if (!priv) {
			sessionStorage.removeItem('wc_pass');
			toast('error', t('err_pass', 'Wrong passphrase — try again.'));
		}
		return priv;
	}

	// ── inbox ────────────────────────────────────────────────────────────

	async function initInbox(root) {
		const tbody = root.querySelector('[data-wc-inbox]');
		const data = await api('/api/inbox');
		tbody.innerHTML = '';
		const priv = await unlockPrivateKey();
		const me = boot().dataset.wcUid;
		for (const c of data.cases) {
			const tr = document.createElement('tr');
			tr.tabIndex = 0;
			tr.dataset.caseId = c.id;

			const titleTd = document.createElement('td');
			titleTd.className = 'wc-col-title';
			let title = t('case_fallback_title', 'Case #') + c.id;
			if (priv && c.wrap && c.title_cipher) {
				const key = await window.WcCrypto.openSealed(c.wrap,
					(await pubKey(me)), priv);
				if (key) {
					const dec = await window.WcCrypto.open(c.title_cipher, key);
					if (dec) title = dec;
				}
			} else if (!c.wrap) {
				title = t('case_locked', 'Case #') + c.id + ' — ' + t('locked', 'locked');
			}
			titleTd.textContent = title;
			tr.appendChild(titleTd);

			const statusTd = document.createElement('td');
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
			dueTd.textContent = c.feedback_due + (c.days_until_due !== null ? ' (' + c.days_until_due + 'd)' : '');
			tr.appendChild(dueTd);

			const recvTd = document.createElement('td');
			recvTd.textContent = c.received_at.slice(0, 10);
			tr.appendChild(recvTd);

			const assTd = document.createElement('td');
			assTd.textContent = c.assigned_to || '—';
			tr.appendChild(assTd);

			tr.addEventListener('click', () => { location.href = base + '/cases/' + c.id; });
			tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') tr.click(); });
			tbody.appendChild(tr);
		}
		if (data.cases.length === 0) {
			const tr = document.createElement('tr');
			const td = document.createElement('td');
			td.colSpan = 5;
			td.textContent = t('inbox_empty', 'No cases yet.');
			tr.appendChild(td);
			tbody.appendChild(tr);
		}
	}

	async function pubKey(uid) {
		const s = await window.WcCrypto.sodium();
		const officers = JSON.parse(boot().dataset.wcOfficers || '[]');
		const me = officers.find((o) => o.uid === uid);
		return me ? s.from_base64(me.pubkey, s.base64_variants.ORIGINAL) : null;
	}

	// ── case view ────────────────────────────────────────────────────────

	async function initCase(root) {
		const caseId = parseInt(root.dataset.caseId, 10);
		const data = await api('/api/cases/' + caseId);
		const priv = await unlockPrivateKey();
		if (!priv) {
			toast('error', t('err_nokey', 'Unlock your key to read this case.'));
			return;
		}
		const me = boot().dataset.wcUid;
		const pk = await pubKey(me);
		const caseKey = await window.WcCrypto.openSealed(data.wrap, pk, priv);
		if (!caseKey) {
			toast('error', t('err_decrypt', 'Decryption failed.'));
			return;
		}
		root.dataset.revision = data.revision;
		renderClosePending(data.close_requested_by, data.close_requested_to, me);

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
		for (const m of data.messages) {
			const plain = await window.WcCrypto.open(m.cipher, caseKey);
			list.appendChild(renderMsg(m.direction, plain === 'wchk:receipt' ? t('receipt_text', 'Receipt confirmed.') : plain, m.at, m.author));
		}
		for (const a of data.attachments) {
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
			root.querySelector('[data-wc-attachments]').appendChild(li);
		}
		for (const n of data.notes) {
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

	function bindCaseActions(root, data, caseKey) {
		let revision = data.revision;
		const refreshRev = (d) => { revision = d.revision; root.dataset.revision = revision; };

		const replyForm = root.querySelector('[data-wc-reply-form]');
		if (replyForm) {
			replyForm.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const text = replyForm.querySelector('[name=body]').value.trim();
				if (!text) return;
				try {
					const cipher = await window.WcCrypto.seal(text, caseKey);
					const res = await api('/api/cases/' + data.id + '/messages', { method: 'POST', body: { cipher, revision } });
					refreshRev(res);
					replyForm.querySelector('[name=body]').value = '';
					root.querySelector('[data-wc-messages]').appendChild(renderMsg('officer', text, new Date().toISOString(), boot().dataset.wcUid));
				} catch (e) {
					toast('error', e.payload && e.status === 409 ? t('err_conflict', 'Someone else changed this case — reloading.') : e.message);
					if (e.status === 409) location.reload();
				}
			});
		}

		const noteForm = root.querySelector('[data-wc-note-form]');
		if (noteForm) {
			noteForm.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const text = noteForm.querySelector('[name=note]').value.trim();
				if (!text) return;
				const cipher = await window.WcCrypto.seal(text, caseKey);
				await api('/api/cases/' + data.id + '/notes', { method: 'POST', body: { cipher } });
				noteForm.querySelector('[name=note]').value = '';
				root.querySelector('[data-wc-notes]').appendChild(renderMsg('note', text, new Date().toISOString(), boot().dataset.wcUid));
				toast('success', t('note_added', 'Note saved.'));
			});
		}

		function renderClosePending(by, to, me) {
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

		root.querySelectorAll('[data-wc-status]').forEach((btn) => {
			btn.addEventListener('click', async () => {
				try {
					const res = await api('/api/cases/' + data.id + '/status', { method: 'PUT', body: { status: btn.dataset.wcStatus, revision } });
					refreshRev(res);
					const badge = root.querySelector('[data-wc-status-badge]');
					if (badge) { badge.textContent = t('status_' + res.status, res.status); badge.className = 'wc-status wc-status-' + res.status; }
					renderClosePending(res.close_requested_by, res.close_requested_to, me);
					toast('success', res.close_requested_by ? t('close_pending_toast', 'Request recorded — a second officer must confirm.') : t('status_saved', 'Status updated.'));
				} catch (e) {
					if (e.status === 409) { toast('error', t('err_conflict', 'Changed elsewhere — reloading.')); location.reload(); }
					else toast('error', e.message);
				}
			});
		});

		const holdForm = root.querySelector('[data-wc-hold-form]');
		if (holdForm) {
			holdForm.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const hold = holdForm.querySelector('[name=hold]').checked;
				const reason = holdForm.querySelector('[name=reason]').value;
				const res = await api('/api/cases/' + data.id + '/hold', { method: 'PUT', body: { hold, reason, revision } });
				refreshRev(res);
				toast('success', t('hold_saved', 'Saved.'));
			});
		}

		const assignSel = root.querySelector('[data-wc-assign]');
		if (assignSel) {
			assignSel.addEventListener('change', async () => {
				const res = await api('/api/cases/' + data.id + '/assign', { method: 'PUT', body: { uid: assignSel.value || null, revision } });
				refreshRev(res);
				toast('success', t('assigned', 'Assignment saved.'));
			});
		}
	}

	// ── officers page ────────────────────────────────────────────────────

	async function initOfficers(root) {
		const me = boot().dataset.wcUid;
		const myBlob = storedKeyBlob(me);
		const regPanel = root.querySelector('[data-wc-keyreg]');
		if (!myBlob && regPanel) regPanel.hidden = false;

		const genBtn = root.querySelector('[data-wc-genkey]');
		if (genBtn) {
			genBtn.addEventListener('click', async () => {
				const officers = JSON.parse(boot().dataset.wcOfficers || '[]');
				const meRec = officers.find((o) => o.uid === me && o.status === 'active');
				const rotating = !!(meRec && meRec.pubkey);

				const pass = window.prompt(t('prompt_newpass', 'Choose a passphrase for your case key (store it safely — it cannot be recovered)'));
				if (!pass || pass.length < 10) { toast('error', t('err_passweak', 'Passphrase needs at least 10 characters.')); return; }
				const kp = await window.WcCrypto.officerKeypair();

				// ROTATION SAFETY: bumping key_version wipes every wrap sealed
				// to the old key. Without an atomic re-wrap in the SAME request
				// every case the officer holds becomes undecryptable —
				// permanently when they are the only wrap-holder.
				const body = { pubkey: kp.pubkey };
				if (rotating) {
					const held = (await api('/api/my-wraps')).wraps || [];
					const blob = storedKeyBlob(me);
					const oldPass = sessionStorage.getItem('wc_pass')
						|| window.prompt(t('prompt_oldpass', 'Enter your CURRENT passphrase to keep access to your cases'));
					const oldPub = await pubKey(me);
					let rewraps = null;
					if (blob && oldPass && oldPub) {
						const oldPriv = await window.WcCrypto.unwrapPrivateKey(blob, oldPass);
						if (oldPriv) {
							rewraps = {};
							let failed = 0;
							for (const w of held) {
								const ck = await window.WcCrypto.openSealed(w.wrapped_key, oldPub, oldPriv);
								if (ck === null) { failed++; continue; }
								rewraps[w.case_id] = await window.WcCrypto.sealToPubkey(ck, kp.pubkey);
							}
							if (failed > 0) {
								if (!window.confirm(t('confirm_rotate_partial', '{n} case(s) could not be re-wrapped and will need a re-grant from another officer. Rotate anyway?').replace('{n}', String(failed)))) return;
							}
						}
					}
					if (rewraps === null && held.length > 0) {
						if (!window.confirm(t('confirm_rotate_orphan', 'Your current key is not available on this device — rotating now locks you out of {n} case(s) until another officer re-wraps them. Continue?').replace('{n}', String(held.length)))) return;
					}
					if (rewraps !== null) body.wraps = rewraps;
				}

				const wrapped = await window.WcCrypto.wrapPrivateKey(kp.privkey, pass);
				await api('/api/officers/key', { method: 'POST', body });
				localStorage.setItem(LS_PRIV + '_' + me, wrapped);
				toast('success', t('key_saved', 'Key registered. Keep your passphrase safe.'));
				location.reload();
			});
		}

		const list = root.querySelector('[data-wc-officers]');
		const data = await api('/api/officers');
		list.innerHTML = '';
		for (const o of data.officers) {
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
					if (!window.confirm(t('confirm_revoke', 'Revoke this officer? You will re-wrap all cases afterwards.'))) return;
					await api('/api/officers/' + encodeURIComponent(o.uid), { method: 'DELETE' });
					location.reload();
				});
				td.appendChild(btn);
			}
			tr.appendChild(td);
			list.appendChild(tr);
		}
	}

	// ── settings (CAS) ───────────────────────────────────────────────────

	function initSettings(root) {
		const form = root.querySelector('[data-wc-settings-form]');
		if (!form) return;
		form.addEventListener('submit', async (ev) => {
			ev.preventDefault();
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
			}
		});
	}

	// ── categories ───────────────────────────────────────────────────────

	async function initCategories(root) {
		const form = root.querySelector('[data-wc-cat-form]');
		const list = root.querySelector('[data-wc-cats]');
		const reload = async () => {
			const data = await api('/api/categories');
			list.innerHTML = '';
			for (const c of data.categories) {
				const tr = document.createElement('tr');
				const lde = document.createElement('td'); lde.textContent = c.label.de || '';
				const len = document.createElement('td'); len.textContent = c.label.en || '';
				const st = document.createElement('td'); st.textContent = c.archived ? t('archived', 'archived') : t('active', 'active');
				const act = document.createElement('td');
				if (!c.archived) {
					const b = document.createElement('button');
					b.type = 'button';
					b.textContent = t('archive', 'Archive');
					b.addEventListener('click', async () => { await api('/api/categories/' + c.id, { method: 'DELETE' }); reload(); });
					act.appendChild(b);
				}
				tr.append(lde, len, st, act);
				list.appendChild(tr);
			}
		};
		if (form) {
			form.addEventListener('submit', async (ev) => {
				ev.preventDefault();
				const de = form.querySelector('[name=label_de]').value.trim();
				const en = form.querySelector('[name=label_en]').value.trim();
				if (!de || !en) return;
				await api('/api/categories', { method: 'POST', body: { labels: { de, en } } });
				form.reset();
				reload();
			});
		}
		await reload();
	}

	// ── audit ────────────────────────────────────────────────────────────

	async function initAudit(root) {
		const data = await api('/api/audit');
		const badge = root.querySelector('[data-wc-chain]');
		if (badge) {
			badge.textContent = data.chain_ok ? t('chain_ok', 'Chain intact') : t('chain_broken', 'CHAIN BROKEN');
			badge.classList.add(data.chain_ok ? 'wc-ok' : 'wc-bad');
		}
		const tbody = root.querySelector('[data-wc-audit]');
		for (const e of data.events) {
			const tr = document.createElement('tr');
			[e.seq, e.event, e.actor || '—', e.case_id ?? '—', e.at].forEach((v) => {
				const td = document.createElement('td');
				td.textContent = String(v);
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		}
	}

	// ── stats ────────────────────────────────────────────────────────────

	async function initStats(root) {
		const data = await api('/api/stats');
		root.querySelector('[data-wc-total]').textContent = data.total;
		root.querySelector('[data-wc-overdue]').textContent = data.overdue;
		const tbody = root.querySelector('[data-wc-statstatus]');
		Object.keys(data.by_status).forEach((s) => {
			const tr = document.createElement('tr');
			const a = document.createElement('td'); a.textContent = t('status_' + s, s);
			const b = document.createElement('td'); b.textContent = data.by_status[s];
			tr.append(a, b); tbody.appendChild(tr);
		});
		const cat = root.querySelector('[data-wc-statcat]');
		for (const c of data.by_category) {
			const tr = document.createElement('tr');
			const a = document.createElement('td'); a.textContent = c.label.en || c.label.de || ('#' + c.id);
			const b = document.createElement('td');
			b.textContent = c.count === null ? '< ' + data.small_cell_floor : c.count; // small-cell suppression
			tr.append(a, b); cat.appendChild(tr);
		}
	}

	// ── devices + verbal ─────────────────────────────────────────────────

	function initCompanion(root) {
		const form = root.querySelector('[data-wc-pair-form]');
		if (!form) return;
		form.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			const name = form.querySelector('[name=name]').value.trim();
			const res = await api('/api/devices', { method: 'POST', body: { name } });
			const out = root.querySelector('[data-wc-token]');
			out.textContent = res.token; // shown once, never stored server-side
			out.closest('[data-wc-token-panel]').hidden = false;
		});
		const list = root.querySelector('[data-wc-devices]');
		api('/api/devices').then((d) => {
			list.innerHTML = '';
			d.devices.forEach((dev) => {
				const li = document.createElement('li');
				li.textContent = dev.name + ' — ' + (dev.last_seen || dev.created) + ' ';
				const b = document.createElement('button');
				b.type = 'button'; b.textContent = t('revoke', 'Revoke');
				b.addEventListener('click', async () => { await api('/api/devices/' + dev.id, { method: 'DELETE' }); li.remove(); });
				li.appendChild(b);
				list.appendChild(li);
			});
		});
	}

	async function initVerbal(root) {
		const data = await api('/api/verbal-text');
		root.querySelector('[data-wc-verbal-de]').textContent = data.de;
		root.querySelector('[data-wc-verbal-en]').textContent = data.en;
	}

	// ── boot ─────────────────────────────────────────────────────────────

	document.addEventListener('DOMContentLoaded', () => {
		if (window.CheckFieldErrors) window.CheckFieldErrors.install({ prefix: 'wc' });
		const page = boot().dataset.wcPage;
		const root = document.getElementById('app-content') || document.body;
		const init = {
			inbox: initInbox, case: initCase, officers: initOfficers,
			settings: initSettings, categories: initCategories, audit: initAudit,
			stats: initStats, companion: initCompanion, verbal: initVerbal,
		}[page];
		if (init) init(root).catch((e) => toast('error', e.message || 'error'));
	});
})();
