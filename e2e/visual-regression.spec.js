// @ts-check
/**
 * Visual regression baselines — catches CSS breakage, layout collapse,
 * spacing regressions and hidden-attribute leaks the structural contracts
 * cannot see. Scoped elements only; run-varying content (stat values,
 * case rows, dates, ciphertext titles, toasts) is masked so baselines
 * stay stable across runs and fixtures.
 *
 * Update goldens deliberately:  npx playwright test visual-regression --update-snapshots
 */
import { test, expect } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { ensureReporterTarget } from './helpers/public-target.js'
import { queryDb, occ } from './helpers/stack.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'

const mysql = queryDb
const TOLERANCE = { maxDiffPixelRatio: 0.02 }

// Goldens are English. Guest pages follow the instance `default_language`
// (Accept-Language is not consulted), which sibling lanes mutate on the
// shared dev instance — pin it to en for the run, restore afterwards.
let prevLanguage = ''
test.beforeAll(() => {
	resetRateBuckets()
	ensureReporterTarget()
	try {
		prevLanguage = occ(['config:system:get', 'default_language']).trim()
	} catch { /* unset */ }
	occ(['config:system:set', 'default_language', '--value', 'en'])
})
test.afterAll(() => {
	try {
		if (prevLanguage === '') {
			occ(['config:system:delete', 'default_language'])
		} else {
			occ(['config:system:set', 'default_language', '--value', prevLanguage])
		}
	} catch { /* best-effort restore */ }
})

test.describe('public pages', () => {
	test('report form: desktop layout', async ({ page }) => {
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#wc-report-form')).toBeVisible()
		// The E2E badge flips from "Checking…" to "End-to-end encrypted" once
		// libsodium confirms — wait for the settled state, then shoot.
		await expect(page.locator('[data-wc-e2e-badge]')).not.toContainText('Checking')
		await expect(page.locator('#wc-report-form')).toHaveScreenshot('report-form-desktop.png', {
			mask: [page.locator('#wc-category')], // category options are config data
			...TOLERANCE,
		})
	})

	test('report form: mobile layout', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 900 })
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#wc-report-form')).toBeVisible()
		await expect(page.locator('[data-wc-e2e-badge]')).not.toContainText('Checking')
		await expect(page.locator('#wc-report-form')).toHaveScreenshot('report-form-mobile.png', {
			mask: [page.locator('#wc-category')],
			...TOLERANCE,
		})
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
		expect(overflow).toBeLessThanOrEqual(1)
	})

	test('follow form: mobile layout', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 800 })
		await page.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#wc-follow-form')).toHaveScreenshot('follow-form-mobile.png', TOLERANCE)
	})

	test('report form: tablet boundary (768px)', async ({ page }) => {
		// 768 is exactly the card-transform/breakpoint-md edge — layout
		// regressions that only fire at the boundary live here.
		await page.setViewportSize({ width: 768, height: 900 })
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#wc-report-form')).toBeVisible()
		await expect(page.locator('[data-wc-e2e-badge]')).not.toContainText('Checking')
		await expect(page.locator('#wc-report-form')).toHaveScreenshot('report-form-tablet.png', {
			mask: [page.locator('#wc-category')],
			...TOLERANCE,
		})
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
		expect(overflow).toBeLessThanOrEqual(1)
	})
})

test.describe('officer surfaces', () => {
	let officer
	let caseId
	test.beforeAll(() => {
		officer = provisionOfficer()
		const hint = randomBytes(8).toString('hex')
		const due = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10)
		mysql(`INSERT INTO oc_wchk_cases
			(lookup_hint, code_hash, code_salt, key_salt, reporter_wrap, status, received_at, feedback_due)
			VALUES ('${hint}', '${randomBytes(32).toString('hex')}', '${randomBytes(8).toString('hex')}',
			'${randomBytes(8).toString('hex')}', 'e2e-wrap', 'received', NOW(), '${due}')`)
		caseId = Number(mysql(`SELECT id FROM oc_wchk_cases WHERE lookup_hint='${hint}'`))
		mysql(`INSERT INTO oc_wchk_case_keys (case_id, officer_uid, wrapped_key, granted_at)
			VALUES (${caseId}, '${officer.uid}', 'e2e-synthetic-wrap', NOW())`)
	})
	test.afterAll(() => {
		try {
			mysql(`DELETE FROM oc_wchk_case_keys WHERE case_id=${caseId}; DELETE FROM oc_wchk_cases WHERE id=${caseId};`)
		} catch { /* best-effort */ }
		deprovisionOfficer(officer?.uid)
	})

	test('inbox: filter panel + stat strip keep layout', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-inbox] tr').first()).toBeVisible({ timeout: 20000 })
		// Scope: controls only — case rows grow/shrink with sibling fixtures.
		await expect(page.locator('[data-wc-inbox-filter]')).toHaveScreenshot('inbox-filter.png', TOLERANCE)
		await expect(page.locator('[data-wc-inbox-stats]')).toHaveScreenshot('inbox-stats.png', {
			mask: [page.locator('.wc-stat__value')],
			...TOLERANCE,
		})
	})

	test('inbox filter panel: mobile layout', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 900 })
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-inbox] tr').first()).toBeVisible({ timeout: 20000 })
		await expect(page.locator('[data-wc-inbox-filter]')).toHaveScreenshot('inbox-filter-mobile.png', TOLERANCE)
	})

	test('inbox table: card-transform boundary (768px)', async ({ page }) => {
		// `max-width: 768px` is inclusive — at exactly 768 the table must
		// already render as cards (tourcheck shipped this exact boundary bug).
		await page.setViewportSize({ width: 768, height: 900 })
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-inbox] tr').first()).toBeVisible({ timeout: 20000 })
		// data-wc-inbox is the tbody — the wrap div is its ancestor.
		await expect(page.locator('.wc-table-wrap')).toHaveScreenshot('inbox-table-boundary.png', {
			mask: [page.locator('[data-wc-inbox]')], // case rows are fixtures — shape, not content
			...TOLERANCE,
		})
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
		expect(overflow).toBeLessThanOrEqual(1)
	})

	test('case hero: status badge + legal actions keep layout', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto(`/apps/whistlecheck/cases/${caseId}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-status-badge]')).toHaveText('Received', { timeout: 15000 })
		await expect(page.locator('.wc-case-hero')).toHaveScreenshot('case-hero.png', {
			// Dates + assignee options are fixture/environment data.
			mask: [page.locator('[data-wc-meta-received], [data-wc-meta-due], [data-wc-assign]')],
			...TOLERANCE,
		})
	})

	test('case hero: mobile layout (320px)', async ({ page }) => {
		// Hero meta grid + legal-action buttons must wrap/stack without
		// clipping on the smallest target width.
		await page.setViewportSize({ width: 320, height: 900 })
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto(`/apps/whistlecheck/cases/${caseId}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-status-badge]')).toHaveText('Received', { timeout: 15000 })
		await expect(page.locator('.wc-case-hero')).toHaveScreenshot('case-hero-mobile.png', {
			mask: [page.locator('[data-wc-meta-received], [data-wc-meta-due], [data-wc-assign]')],
			...TOLERANCE,
		})
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
		expect(overflow).toBeLessThanOrEqual(1)
	})
})
