// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'

/**
 * Officer journey coverage — guards the class of bug that silently killed
 * the whole app (skip-link inside data-wc-i18n swallowed every data attr,
 * leaving wcPage undefined and all officer JS dead while axe stayed green).
 *
 * Every describe provisions its own throwaway officer — never a shared
 * uid. A second runner (or leftover CI job) rotating the same key mid-test
 * seals wraps to a pubkey our blob cannot open, and the failure looks like
 * a product decryption bug. Unique uids make each spec hermetic.
 */

const OFFICER_PASS = 'journey-passphrase-9x'

// Fresh rate buckets: a11y-smoke has already spent 'params' hits, and the
// journey consumes a 'submit' hit (5/h per IP) — deterministic, never
// weakened production limits.
test.beforeAll(() => resetRateBuckets())

test.describe('officer pages: boot integrity (U1 regression)', () => {
	let officer
	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => deprovisionOfficer(officer?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
	})

	const pages = [
		['/apps/whistlecheck/', 'inbox'],
		['/apps/whistlecheck/officers', 'officers'],
		['/apps/whistlecheck/audit', 'audit'],
		['/apps/whistlecheck/stats', 'stats'],
		['/apps/whistlecheck/verbal', 'verbal'],
		['/apps/whistlecheck/settings', 'settings'],
	]

	for (const [path, expected] of pages) {
		test(`${path} boots with intact data attrs + nav`, async ({ page }) => {
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)

			const root = page.locator('#app-content')
			await expect(root).toHaveAttribute('data-wc-page', expected === 'settings' ? 'settings' : expected)

			// i18n must be parseable JSON (the bug truncated it mid-attribute).
			const parsed = await root.evaluate((el) => {
				try { return JSON.parse(el.dataset.wcI18n || '{}') } catch { return null }
			})
			expect(parsed).not.toBeNull()

			// Attribute junk must never leak as visible text.
			await expect(page.locator('body')).not.toContainText('data-wc-page=')

			// Skip links (content + navigation) are real elements and focusable.
			const skip = page.locator('.wc-skip-link')
			await expect(skip).toHaveCount(2)

			// Shared sidebar nav on every page, current page marked.
			const nav = page.locator('#app-navigation.wc-nav')
			await expect(nav).toBeVisible()
			expect(await nav.locator('a').count()).toBeGreaterThanOrEqual(6)
			await expect(nav.locator('[aria-current="page"]')).toHaveCount(1)

			// Design-system chrome: brand, icon-well page header, breadcrumb,
			// scope strip — same skeleton as the other Check apps.
			await expect(nav.locator('.wc-brand__title')).toHaveText('WhistleCheck')
			await expect(page.locator('.wc-page-header__icon')).toBeVisible()
			await expect(page.locator('#wc-page-title')).toBeVisible()
			await expect(page.locator('.wc-breadcrumb__current')).toBeVisible()
			await expect(page.locator('.wc-scope-strip')).toBeVisible()
		})
	}
})

test.describe('officer journey: inbox + nav (fresh fixture)', () => {
	let officer
	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => deprovisionOfficer(officer?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
	})

	test('inbox resolves: rows or empty state, never stuck loading', async ({ page }) => {
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		// Loading must resolve either way — a stuck spinner is a dead end.
		await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
		const inbox = page.locator('[data-wc-inbox]')
		await expect(inbox).toBeVisible()
		// A fresh officer legitimately has zero cases — the empty state is a
		// first-class render, not an error.
		const rows = inbox.locator('tr[data-case-id]')
		const rowCount = await rows.count()
		if (rowCount === 0) {
			await expect(inbox).toContainText(/./) // rendered empty state, not blank
		}
	})

	test('nav links traverse every section (no dead ends)', async ({ page }) => {
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		for (const href of ['/officers', '/audit', '/stats', '/verbal', '/settings']) {
			await page.locator(`.wc-nav a[href$="${href}"]`).first().click()
			await expect(page.locator('#app-content')).toHaveAttribute('data-wc-page')
			await expect(page.locator('.wc-nav').first()).toBeVisible()
			await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		}
	})

	test('mobile drawer: Menu button opens the sidebar below 1024px', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		const toggle = page.locator('#wc-nav-toggle')
		await expect(toggle).toBeVisible()
		await toggle.click()
		const nav = page.locator('#app-navigation.wc-nav')
		await expect(nav).toHaveClass(/wc-nav--open/)
		await expect(nav.locator('.wc-nav__link').first()).toBeVisible()
		// Escape closes and returns focus to the toggle.
		await page.keyboard.press('Escape')
		await expect(nav).not.toHaveClass(/wc-nav--open/)
	})

	test('officer inbox renders as cards at 390px', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth
		)
		expect(overflow).toBeLessThanOrEqual(1)
		// Card layout active: thead hidden, labeled cells stacked
		const theadHidden = await page.locator('.wc-table-cards thead').isHidden()
		expect(theadHidden).toBe(true)
	})
})

test.describe('officer full journey (fresh fixture)', () => {
	test('key setup → public submit → unlock → read case', async ({ page }) => {
		test.setTimeout(240000)
		const officer = provisionOfficer()
		try {
		await login(page, { username: officer.uid, password: officer.password })
		await assertNotServerUpdater(page)

		// ── 1. Key setup through the inline panel (no modal prompts) ──
		await page.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-wc-keyreg]')).toBeVisible()
		await page.locator('[data-wc-newpass]').fill(OFFICER_PASS)
		await page.locator('[data-wc-newpass2]').fill(OFFICER_PASS)
		await page.locator('[data-wc-genkey]').click()
		// Belt-and-braces: should the fixture ever land in rotate-confirm
		// state, a visible confirm error gets the explicit second click.
		const keyErr = page.locator('[data-wc-keyreg-err]')
		try {
			await keyErr.waitFor({ state: 'visible', timeout: 15000 })
			await page.locator('[data-wc-genkey]').click()
		} catch {
			// Fresh registration — no confirm needed.
		}
		// Page reloads after successful registration — wait for our row's
		// status cell to read 'active', which proves the POST committed AND
		// the post-reload list fetch rendered (a bare name match resolves on
		// the pre-reload DOM and lets a report seal to a dying key).
		const officerRow = page.locator('tbody[data-wc-officers] tr', { hasText: officer.uid })
		await expect(officerRow).toContainText('active', { timeout: 45000 })

		// ── 2. Anonymous report wraps to the now-active officer ──
		// Separate anonymous context — the reporter has no session.
		const pubContext = await page.context().browser().newContext()
		const pub = await pubContext.newPage()
		const bodyText = `journey body ${Date.now()}`
		await pub.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await pub.locator('#wc-body').fill(bodyText)
		await pub.locator('#wc-report-form [type=submit]').click()
		const codeEl = pub.locator('#wc-code-panel .wc-code-value')
		await expect(codeEl).not.toBeEmpty({ timeout: 90000 })
		await pubContext.close()

		// ── 3. Inbox shows the case; open it via keyboard ──
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		const row = page.locator('[data-wc-inbox] tr[data-case-id]').first()
		await expect(row).toBeVisible({ timeout: 20000 })
		await row.locator('a.wc-case-link').focus()
		await page.keyboard.press('Enter')
		await expect(page).toHaveURL(/\/cases\/\d+/)

		// ── 4. Unlock panel: wrong pass → inline error, right pass → content ──
		await page.evaluate(() => sessionStorage.removeItem('wc_pass'))
		await page.reload({ waitUntil: 'domcontentloaded' })
		const panel = page.locator('[data-wc-unlock]')
		await expect(panel).toBeVisible({ timeout: 15000 })
		await page.locator('[data-wc-unlock-pass]').fill('wrong-passphrase')
		await page.locator('[data-wc-unlock-go]').click()
		await expect(page.locator('[data-wc-unlock-err]')).toBeVisible({ timeout: 15000 })

		await page.locator('[data-wc-unlock-pass]').fill(OFFICER_PASS)
		await page.locator('[data-wc-unlock-go]').click()
		await expect(panel).toBeHidden({ timeout: 15000 })
		await expect(page.locator('[data-wc-messages]')).toContainText(bodyText, { timeout: 30000 })

		// ── 5. Axe on the fully decrypted case page ──
		const axe = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(axe.violations, `axe on case page: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
		} finally {
			deprovisionOfficer(officer.uid)
		}
	})
})

test.describe('re-grant ceremony (fresh fixtures)', () => {
	test('orphaned officer gets re-granted and can decrypt again', async ({ browser, baseURL }) => {
		test.setTimeout(300000)
		// Two-officer flow: A decrypts, B rotates without re-wrap (fresh
		// context = no local blob → informed orphan path) and loses the wrap.
		// A's case page must list B under "Grant access"; after the grant B
		// must see and decrypt the case again — a phantom grant (success
		// toast, no stored wrap) fails this test at the last assertion.
		const officerA = provisionOfficer()
		const officerB = provisionOfficer()
		const passA = 'grant-a-pass-9x42'
		const passB1 = 'grant-b-one-9x42'
		const passB2 = 'grant-b-two-9x42'
		const ctxA = await browser.newContext({ baseURL })
		try {
			const pageA = await ctxA.newPage()
			await login(pageA, { username: officerA.uid, password: officerA.password })
			await pageA.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
			await pageA.locator('[data-wc-newpass]').fill(passA)
			await pageA.locator('[data-wc-newpass2]').fill(passA)
			await pageA.locator('[data-wc-genkey]').click()
			await expect(pageA.locator('tbody[data-wc-officers] tr', { hasText: officerA.uid }))
				.toContainText('active', { timeout: 45000 })

			const ctxB1 = await browser.newContext({ baseURL })
			const pageB = await ctxB1.newPage()
			await login(pageB, { username: officerB.uid, password: officerB.password })
			await pageB.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
			await pageB.locator('[data-wc-newpass]').fill(passB1)
			await pageB.locator('[data-wc-newpass2]').fill(passB1)
			await pageB.locator('[data-wc-genkey]').click()
			await expect(pageB.locator('tbody[data-wc-officers] tr', { hasText: officerB.uid }))
				.toContainText('active', { timeout: 45000 })

			// Anonymous report wraps to BOTH active officers.
			const pubCtx = await browser.newContext({ baseURL })
			const pub = await pubCtx.newPage()
			const bodyText = `grant journey ${Date.now()}`
			await pub.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
			await pub.locator('#wc-body').fill(bodyText)
			await pub.locator('#wc-report-form [type=submit]').click()
			await expect(pub.locator('#wc-code-panel .wc-code-value')).not.toBeEmpty({ timeout: 90000 })
			await pubCtx.close()
			await ctxB1.close()

			// B rotates in a FRESH context — no key blob on this "device" →
			// the informed orphan path (confirm click) wipes B's wraps.
			const ctxB2 = await browser.newContext({ baseURL })
			const pageB2 = await ctxB2.newPage()
			await login(pageB2, { username: officerB.uid, password: officerB.password })
			await pageB2.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
			await pageB2.locator('[data-wc-newpass]').fill(passB2)
			await pageB2.locator('[data-wc-newpass2]').fill(passB2)
			await pageB2.locator('[data-wc-genkey]').click()
			await expect(pageB2.locator('[data-wc-keyreg-err]')).toBeVisible({ timeout: 15000 })
			// Wait for the rotation commit, not the row status: B is already
			// 'active' pre-rotation, so a status assertion resolves spuriously
			// and navigating away mid-POST aborts the post-commit handler
			// (sessSet + reload) — a lost session passphrase, not a server bug.
			const rotResp = pageB2.waitForResponse(
				(r) => r.url().includes('/api/officers/key') && r.request().method() === 'POST',
				{ timeout: 30000 })
			const reloaded = pageB2.waitForEvent('load', { timeout: 30000 })
			await pageB2.locator('[data-wc-genkey]').click() // explicit orphan confirm
			expect((await rotResp).status()).toBe(201)
			// The post-commit handler runs sessSet → location.reload(); the
			// reload's load event guarantees both landed before we navigate.
			await reloaded

			// B's inbox must NOT show the case — the wrap is gone.
			await pageB2.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
			await expect(pageB2.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
			// An API error would render tr.wc-load-error (no data-case-id) —
			// count(0) alone would pass spuriously on a failed inbox load.
			await expect(pageB2.locator('[data-wc-inbox] tr.wc-load-error')).toHaveCount(0)
			await expect(pageB2.locator('[data-wc-inbox] tr[data-case-id]')).toHaveCount(0)

			// A opens the case (auto-unlock from session passphrase) and
			// re-grants B through the grant panel.
			await pageA.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
			const row = pageA.locator('[data-wc-inbox] tr[data-case-id]').first()
			await expect(row).toBeVisible({ timeout: 20000 })
			await row.locator('a.wc-case-link').click()
			await expect(pageA).toHaveURL(/\/cases\/\d+/)
			await expect(pageA.locator('[data-wc-messages]')).toContainText(bodyText, { timeout: 30000 })

			const grantSec = pageA.locator('[data-wc-grant]')
			await expect(grantSec).toBeVisible({ timeout: 15000 })
			const grantRow = pageA.locator('[data-wc-grant-list] li', { hasText: officerB.uid })
			await expect(grantRow).toBeVisible()
			await grantRow.locator('button').click()
			await expect(grantSec).toBeHidden({ timeout: 15000 })

			// The decisive check: B sees and decrypts the case with the NEW
			// passphrase — proves the wrap actually landed (no phantom).
			await pageB2.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
			const rowB = pageB2.locator('[data-wc-inbox] tr[data-case-id]').first()
			await expect(rowB).toBeVisible({ timeout: 20000 })
			await rowB.locator('a.wc-case-link').click()
			await expect(pageB2.locator('[data-wc-messages]')).toContainText(bodyText, { timeout: 30000 })
			await ctxB2.close()
		} finally {
			await ctxA.close().catch(() => {})
			deprovisionOfficer(officerA.uid)
			deprovisionOfficer(officerB.uid)
		}
	})
})

test.describe('responsive: no horizontal overflow at phone width', () => {
	const mobile = { width: 390, height: 844 }

	test('public report form fits 390px', async ({ page }) => {
		await page.setViewportSize(mobile)
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#wc-body')).toBeVisible()
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth
		)
		expect(overflow).toBeLessThanOrEqual(1)
	})
})
