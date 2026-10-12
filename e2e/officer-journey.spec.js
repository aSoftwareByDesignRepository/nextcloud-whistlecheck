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

			// Skip link is a real element and focusable.
			const skip = page.locator('.wc-skip-link')
			await expect(skip).toHaveCount(1)

			// Shared nav on every page, current page marked.
			const nav = page.locator('.wc-nav').first()
			await expect(nav).toBeVisible()
			expect(await nav.locator('a').count()).toBeGreaterThanOrEqual(6)
			await expect(nav.locator('[aria-current="page"]')).toHaveCount(1)
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
