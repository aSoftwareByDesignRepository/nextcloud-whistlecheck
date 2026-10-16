// @ts-check
/**
 * Atlas craft: capture WhistleCheck web surfaces into
 * artifacts/whistlecheck/craft/web/ — ds_chrome lane, web-only app (no AVD).
 *
 * Covers every routable surface: officer pages (inbox, case, officers,
 * audit, stats, verbal, settings sections), the anonymous reporter flow
 * (report form → case code panel → follow-up thread), dialogs (revoke
 * confirm — also the live proof for the dialog-Escape fix), error/empty
 * states, the 403 denied page, dark + high-contrast themes (real user
 * setting, server-rendered vars), and the ≤390px mobile drawer.
 *
 * Run:
 *   npx playwright test e2e/atlas-craft-web.spec.js --project=chromium
 */
import { test, expect } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer, setUserTheme } from './helpers/officer.js'
import { ensureReporterTarget } from './helpers/public-target.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { occ, queryDb } from './helpers/stack.js'

const here = path.dirname(fileURLToPath(import.meta.url))
const outDir = path.resolve(here, '../../../../.cursor/atlas-farm-v3/artifacts/whistlecheck/craft/web')

/** @param {import('@playwright/test').Page} page */
async function shot(page, id, meta) {
	const file = path.join(outDir, `wc-web-${id}.png`)
	await page.waitForTimeout(350)
	await page.screenshot({ path: file, fullPage: false })
	const st = fs.statSync(file)
	expect(st.size, `${id} screenshot too small`).toBeGreaterThan(8_000)
	meta.files.push({ id, path: `craft/web/wc-web-${id}.png`, bytes: st.size })
	console.log('craft', file, st.size)
}

/** Wait for JS-rendered content to replace the loading row. */
async function settled(page) {
	await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 30_000 })
}

const futureDate = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10)

/** Seed a real case row + synthetic wrap so the officer sees it (locked). */
function seedCase(officerUid, status = 'received') {
	const hint = 'wccraft-' + randomBytes(12).toString('hex')
	queryDb(`INSERT INTO oc_wchk_cases
		(lookup_hint, code_hash, code_salt, key_salt, reporter_wrap, status, received_at, feedback_due)
		VALUES ('${hint}', 'wccraft-hash-${randomBytes(6).toString('hex')}', 'wccraft-salt',
		'${randomBytes(6).toString('hex')}', 'wccraft-rwrap', '${status}', NOW(), '${futureDate}')`)
	const caseId = Number(queryDb(`SELECT id FROM oc_wchk_cases WHERE lookup_hint='${hint}'`))
	queryDb(`INSERT INTO oc_wchk_case_keys (case_id, officer_uid, wrapped_key, granted_at)
		VALUES (${caseId}, '${officerUid}', 'craft-synthetic-wrap', NOW())`)
	return caseId
}

function cleanupCase(caseId, officerUid) {
	try {
		queryDb(`DELETE FROM oc_wchk_escalations WHERE case_id=${caseId};
			DELETE FROM oc_wchk_case_keys WHERE case_id=${caseId};
			DELETE FROM oc_wchk_messages WHERE case_id=${caseId};
			DELETE FROM oc_wchk_idem WHERE case_id=${caseId};
			DELETE FROM oc_wchk_cases WHERE id=${caseId};`)
	} catch { /* best-effort */ }
}

test.beforeAll(() => {
	resetRateBuckets()
	ensureReporterTarget()
})

test.describe('atlas craft — officer surfaces (light)', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	let officer
	let caseId

	test.beforeAll(() => {
		officer = provisionOfficer()
		caseId = seedCase(officer.uid)
	})
	test.afterAll(() => {
		if (caseId) cleanupCase(caseId, officer?.uid)
		deprovisionOfficer(officer?.uid)
	})

	test('capture officer surfaces', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		fs.mkdirSync(outDir, { recursive: true })
		const meta = { captured_at: new Date().toISOString(), app: 'whistlecheck', lane: 'ds_chrome', files: [] }
		await login(page, { username: officer.uid, password: officer.password })

		// Inbox with a seeded case row (content stays sealed — honest).
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await settled(page)
		await shot(page, 'inbox', meta)

		// Result count is plural-aware via n() — the '{n} cases' hardcode
		// rendered "1 cases" (visual r1 wc-vis-inbox-count-broken-plural).
		const [nOne, nFive] = await page.evaluate(() => [
			window.n('whistlecheck', '%n case', '%n cases', 1),
			window.n('whistlecheck', '%n case', '%n cases', 5)])
		expect(nOne, 'n() singular must differ from plural').not.toBe(nFive)
		const dataRows = await page.locator('[data-wc-inbox] tr[data-case-id]').count()
		const expectedCount = await page.evaluate(
			(c) => window.n('whistlecheck', '%n case', '%n cases', c), dataRows)
		await expect(page.locator('[data-wc-inbox-count]')).toHaveText(expectedCount)

		// Case detail — seeded wrap is undecryptable → sealed-content surface.
		await page.goto(`/apps/whistlecheck/cases/${caseId}`, { waitUntil: 'domcontentloaded' })
		await settled(page)
		await shot(page, 'case-detail', meta)

		for (const [path, id] of [
			['/apps/whistlecheck/officers', 'officers'],
			['/apps/whistlecheck/audit', 'audit'],
			['/apps/whistlecheck/stats', 'stats'],
			['/apps/whistlecheck/verbal', 'verbal'],
			['/apps/whistlecheck/settings/channel', 'settings-channel'],
			['/apps/whistlecheck/settings/categories', 'settings-categories'],
			['/apps/whistlecheck/settings/access', 'settings-access'],
			['/apps/whistlecheck/settings/retention', 'settings-retention'],
			['/apps/whistlecheck/settings/support', 'settings-support'],
		]) {
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await settled(page)
			await shot(page, id, meta)
		}

		// PoW label is plain language — no literal-'N' placeholder jargon
		// (visual r1 wc-vis-pow-label-jargon).
		await page.goto('/apps/whistlecheck/settings/access', { waitUntil: 'domcontentloaded' })
		await settled(page)
		const powLabel = page.locator('label[for=wc-pow]')
		await expect(powLabel).not.toBeEmpty()
		await expect(powLabel).not.toContainText(/\bN\b/)

		fs.writeFileSync(path.join(outDir, '_manifest.json'), JSON.stringify(meta, null, 1) + '\n')
	})

	test('settings: escalation officer is a directory pick', async ({ page }) => {
		// Raw uid text input was the defect — the control must be a
		// directory-backed pick bound to the same config key, and a pick must
		// persist through the normal save path (visual r1
		// wc-vis-escalation-uid-raw-text).
		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto('/apps/whistlecheck/settings/channel', { waitUntil: 'domcontentloaded' })
		await settled(page)
		const esc = page.locator('select#wc-esc')
		await expect(esc).toBeVisible()
		const original = await esc.inputValue()
		const options = await esc.locator('option').evaluateAll((os) => os.map((o) => o.value))
		const pick = options.find((v) => v !== '' && v !== original)
		expect(pick, 'escalation select must offer officer options').toBeTruthy()
		await esc.selectOption(pick)
		await page.locator('[data-wc-settings-form] [type=submit]').click()
		await expect(page.locator('#wc-toasts .wc-toast-success')).toBeVisible({ timeout: 15_000 })
		// Durable postcondition: reload → the picked officer stays selected.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await settled(page)
		await expect(page.locator('select#wc-esc')).toHaveValue(pick)
		// Restore the prior value so the shared instance stays clean.
		await page.locator('select#wc-esc').selectOption(original)
		await page.locator('[data-wc-settings-form] [type=submit]').click()
		await expect(page.locator('#wc-toasts .wc-toast-success')).toBeVisible({ timeout: 15_000 })
	})
})

test.describe('atlas craft — dialogs + error states', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	let officer

	test.beforeAll(() => {
		officer = provisionOfficer()
	})
	test.afterAll(() => deprovisionOfficer(officer?.uid))

	test('confirm dialog + Escape close (regression: NC eats dialog cancel)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		fs.mkdirSync(outDir, { recursive: true })
		const metaFile = path.join(outDir, '_manifest.json')
		const meta = fs.existsSync(metaFile)
			? JSON.parse(fs.readFileSync(metaFile, 'utf8'))
			: { captured_at: new Date().toISOString(), app: 'whistlecheck', lane: 'ds_chrome', files: [] }

		await login(page, { username: officer.uid, password: officer.password })
		await page.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
		await settled(page)

		// wcfix fixture officer is active and not "me" → Revoke → wcConfirm.
		const revoke = page.locator('[data-wc-officers] .wc-btn-danger').first()
		await expect(revoke).toBeVisible({ timeout: 15_000 })
		await revoke.click()
		const dlg = page.locator('dialog.wc-dialog')
		await expect(dlg).toBeVisible({ timeout: 10_000 })
		await shot(page, 'dialog-confirm-revoke', meta)

		// Escape must close even though NC notifications preventDefaults the
		// global keydown — the dialog-level keydown handler does the work.
		await page.keyboard.press('Escape')
		await expect(dlg).toBeHidden({ timeout: 5_000 })

		// Re-open and cancel via button — the officer row must survive.
		await revoke.click()
		await expect(dlg).toBeVisible({ timeout: 10_000 })
		await page.locator('dialog.wc-dialog .wc-btn-secondary').click()
		await expect(dlg).toBeHidden({ timeout: 5_000 })

		// Inbox list load error (route abort → showLoadError retry row).
		await page.route('**/apps/whistlecheck/api/inbox*', (route) => route.abort())
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.wc-load-error')).toBeVisible({ timeout: 15_000 })
		await shot(page, 'inbox-load-error', meta)

		fs.writeFileSync(metaFile, JSON.stringify(meta, null, 1) + '\n')
	})
})

test.describe('atlas craft — public reporter surfaces (anonymous)', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(300_000) // argon2id + PoW are slow by design

	test('report form, validation error, code panel, follow-up, thread', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		fs.mkdirSync(outDir, { recursive: true })
		const metaFile = path.join(outDir, '_manifest.json')
		const meta = fs.existsSync(metaFile)
			? JSON.parse(fs.readFileSync(metaFile, 'utf8'))
			: { captured_at: new Date().toISOString(), app: 'whistlecheck', lane: 'ds_chrome', files: [] }

		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#wc-report-form')).toBeVisible({ timeout: 30_000 })
		await shot(page, 'public-report', meta)

		// Error surface: a forced 500 on submit renders the error banner and
		// the filled fields survive (form-survival-on-5xx class).
		const marker = `Atlas craft ${Date.now()} — doppelt berechnete Rechnung`
		await page.locator('#wc-title').fill('Atlas craft report')
		await page.locator('#wc-body').fill(marker)
		await page.route('**/api/public/submit', (route) =>
			route.fulfill({ status: 500, contentType: 'application/json', body: '{"success":false,"error":"INTERNAL","message":"Internal error."}' }))
		await page.locator('#wc-report-form [type=submit]').click()
		await expect(page.locator('#wc-form-error')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#wc-body')).toHaveValue(marker)
		// A 5xx must never surface wire jargon to a lay reporter — the banner
		// carries translated retry copy, not 'Internal error.' (visual r1
		// wc-vis-internal-error-jargon).
		await expect(page.locator('#wc-form-error')).not.toBeEmpty()
		await expect(page.locator('#wc-form-error')).not.toContainText('Internal error')
		await shot(page, 'public-report-errors', meta)
		await page.unroute('**/api/public/submit')

		// Real submit → code panel (Crockford code + QR + keep-safe copy).
		await page.locator('#wc-body').fill(`Atlas craft ${Date.now()} — doppelt berechnete Rechnung`)
		await page.locator('#wc-report-form [type=submit]').click()
		const codeEl = page.locator('#wc-code-panel .wc-code-value')
		await expect(codeEl).not.toBeEmpty({ timeout: 120_000 })
		await expect(page.locator('#wc-code-panel')).toBeVisible()
		const code = (await codeEl.textContent() || '').trim()
		await shot(page, 'public-code-panel', meta)

		// Follow-up entry form.
		await page.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#wc-follow-form')).toBeVisible({ timeout: 30_000 })
		await shot(page, 'public-follow', meta)

		// Bogus code → uniform error (no existence oracle).
		await page.locator('#wc-code').fill('AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-A')
		await page.locator('#wc-follow-form [type=submit]').click()
		await expect(page.locator('#wc-form-error')).toBeVisible({ timeout: 15_000 })
		await shot(page, 'public-follow-error', meta)

		// Real code → decrypted thread surface.
		await page.locator('#wc-code').fill(code)
		await page.locator('#wc-follow-form [type=submit]').click()
		await expect(page.locator('#wc-thread')).toBeVisible({ timeout: 60_000 })
		// Reporter surface: status pill + human dates — never the raw wire
		// enum or ISO-8601 text (visual r1 wc-vis-public-thread-raw-enum-iso).
		const statusEl = page.locator('#wc-thread .wc-status')
		await expect(statusEl.locator('.wc-badge__dot')).toBeVisible()
		await expect(statusEl).not.toHaveText(/^\s*(received|in_review|action_taken|closed|archived)\s*$/)
		const stamp = page.locator('#wc-thread time').first()
		const stampIso = await stamp.getAttribute('datetime')
		expect(stampIso, 'time keeps machine datetime attr').toMatch(/^\d{4}-\d{2}-\d{2}T/)
		expect(((await stamp.textContent()) || '').trim(), 'time renders a human date').not.toBe(stampIso)
		await shot(page, 'public-thread', meta)

		fs.writeFileSync(metaFile, JSON.stringify(meta, null, 1) + '\n')
	})
})

test.describe('atlas craft — themes (real user setting)', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	let officer

	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => deprovisionOfficer(officer?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test('dark theme: inbox + settings', async ({ page }) => {
		setUserTheme(officer.uid, '["dark"]')
		fs.mkdirSync(outDir, { recursive: true })
		const metaFile = path.join(outDir, '_manifest.json')
		const meta = fs.existsSync(metaFile)
			? JSON.parse(fs.readFileSync(metaFile, 'utf8'))
			: { captured_at: new Date().toISOString(), app: 'whistlecheck', lane: 'ds_chrome', files: [] }

		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await settled(page)
		await shot(page, 'inbox-dark', meta)
		await page.goto('/apps/whistlecheck/settings/channel', { waitUntil: 'domcontentloaded' })
		await settled(page)
		await shot(page, 'settings-dark', meta)
		fs.writeFileSync(metaFile, JSON.stringify(meta, null, 1) + '\n')
	})

	test('high-contrast theme: inbox', async ({ page }) => {
		setUserTheme(officer.uid, '["highcontrast"]')
		const metaFile = path.join(outDir, '_manifest.json')
		const meta = fs.existsSync(metaFile)
			? JSON.parse(fs.readFileSync(metaFile, 'utf8'))
			: { captured_at: new Date().toISOString(), app: 'whistlecheck', lane: 'ds_chrome', files: [] }

		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await settled(page)
		await shot(page, 'inbox-hc', meta)
		fs.writeFileSync(metaFile, JSON.stringify(meta, null, 1) + '\n')
	})
})

test.describe('atlas craft — denied + mobile', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	test('denied page for non-officer (403 surface)', async ({ page }) => {
		const uid = 'wccraft-outsider-' + randomBytes(4).toString('hex')
		const pass = 'WcCraft-' + randomBytes(6).toString('hex')
		occ(['user:add', '--password-from-env', uid], { OC_PASS: pass })
		try {
			await page.setViewportSize({ width: 1280, height: 800 })
			fs.mkdirSync(outDir, { recursive: true })
			const metaFile = path.join(outDir, '_manifest.json')
			const meta = fs.existsSync(metaFile)
				? JSON.parse(fs.readFileSync(metaFile, 'utf8'))
				: { captured_at: new Date().toISOString(), app: 'whistlecheck', lane: 'ds_chrome', files: [] }

			await login(page, { username: uid, password: pass }).catch(async () => {
				// Non-officers get 403 on /apps/whistlecheck/ — login() lands there;
				// recover by going to dashboard first, then the app.
				await page.goto('/index.php/apps/dashboard/', { waitUntil: 'domcontentloaded' })
			})
			const res = await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
			expect(res?.status(), 'denied page must be a real 403').toBe(403)
			// Recovery CTA: a denied surface is never a dead end — one clear
			// link out, and its target must actually resolve (visual r1
			// wc-vis-denied-dead-end).
			const cta = page.locator('#main-content a.wc-btn-primary')
			await expect(cta).toBeVisible()
			const ctaHref = await cta.getAttribute('href')
			expect(ctaHref, 'denied CTA carries a real href').toBeTruthy()
			const ctaRes = await page.request.get(new URL(ctaHref, page.url()).href)
			expect(ctaRes.status(), 'denied CTA target resolves').toBeLessThan(400)
			await shot(page, 'denied-403', meta)
			fs.writeFileSync(metaFile, JSON.stringify(meta, null, 1) + '\n')
		} finally {
			try { occ(['user:delete', uid]) } catch { /* best-effort */ }
		}
	})

	test('mobile drawer at 375px', async ({ page }) => {
		const officer = provisionOfficer()
		try {
			await page.setViewportSize({ width: 375, height: 812 })
			fs.mkdirSync(outDir, { recursive: true })
			const metaFile = path.join(outDir, '_manifest.json')
			const meta = fs.existsSync(metaFile)
				? JSON.parse(fs.readFileSync(metaFile, 'utf8'))
				: { captured_at: new Date().toISOString(), app: 'whistlecheck', lane: 'ds_chrome', files: [] }

			await login(page, { username: officer.uid, password: officer.password })
			await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
			await settled(page)
			await shot(page, 'mobile-375', meta)

			const toggle = page.locator('#wc-nav-toggle')
			await expect(toggle).toBeVisible({ timeout: 15_000 })
			await toggle.click()
			await expect(page.locator('#app-navigation.wc-nav--open')).toBeVisible({ timeout: 10_000 })
			await shot(page, 'mobile-375-nav-open', meta)
			fs.writeFileSync(metaFile, JSON.stringify(meta, null, 1) + '\n')
		} finally {
			deprovisionOfficer(officer?.uid)
		}
	})
})
