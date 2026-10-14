// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { randomBytes } from 'node:crypto'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { ensureReporterTarget } from './helpers/public-target.js'
import { queryDb } from './helpers/stack.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'

/**
 * UX flow contracts — the canonical Check-app surface rules:
 *  - the inbox ships a real filter panel (search + status + mine + actions),
 *    a live result count, stat tiles, and a canonical empty state,
 *  - the case page shows only *legal* status transitions — a 4-button wall
 *    of dead actions is a dead end,
 *  - an archived case locks its reply form and explains why,
 *  - mobile width turns the table into labelled cards,
 *  - every one of these states is axe-clean.
 */

const mysql = queryDb

test.beforeAll(() => {
	resetRateBuckets()
	ensureReporterTarget()
})

const futureDate = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10)

function makeCase(officerUid, status = 'received') {
	const hint = randomBytes(8).toString('hex')
	mysql(`INSERT INTO oc_wchk_cases
		(lookup_hint, code_hash, code_salt, key_salt, reporter_wrap, status, received_at, feedback_due)
		VALUES ('${hint}', '${randomBytes(32).toString('hex')}', '${randomBytes(8).toString('hex')}',
		'${randomBytes(8).toString('hex')}', 'e2e-wrap', '${status}', NOW(), '${futureDate}')`)
	const caseId = Number(mysql(`SELECT id FROM oc_wchk_cases WHERE lookup_hint='${hint}'`))
	mysql(`INSERT INTO oc_wchk_case_keys (case_id, officer_uid, wrapped_key, granted_at)
		VALUES (${caseId}, '${officerUid}', 'e2e-synthetic-wrap', NOW())`)
	return caseId
}

function cleanupCase(caseId) {
	try {
		mysql(`DELETE FROM oc_wchk_escalations WHERE case_id=${caseId};
			DELETE FROM oc_wchk_case_keys WHERE case_id=${caseId};
			DELETE FROM oc_wchk_cases WHERE id=${caseId};`)
	} catch { /* best-effort */ }
}

test.describe('inbox: canonical filter + count + empty states', () => {
	let officer
	const caseIds = []
	test.beforeAll(() => {
		officer = provisionOfficer()
		caseIds.push(makeCase(officer.uid, 'received'), makeCase(officer.uid, 'closed'))
	})
	test.afterAll(() => {
		for (const id of caseIds) cleanupCase(id)
		deprovisionOfficer(officer?.uid)
	})

	test('filter panel exists with all controls and applies live', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)

		const form = page.locator('[data-wc-inbox-filter]')
		await expect(form).toBeVisible()
		await expect(form).toHaveAttribute('role', 'search')
		await expect(form.locator('input[name="q"]')).toBeVisible()
		await expect(form.locator('select[name="status"]')).toBeVisible()
		await expect(form.locator('input[name="mine"]')).toBeVisible()
		await expect(form.locator('[type="submit"]')).toBeVisible()
		await expect(form.locator('[type="reset"]')).toBeVisible()

		// Wait for the list to settle before filtering.
		await expect(page.locator('[data-wc-inbox] tr').first()).toBeVisible({ timeout: 20000 })
		const total = await page.locator('[data-wc-inbox] tr').count()
		expect(total).toBeGreaterThanOrEqual(2)

		// Stat strip renders once data lands.
		const strip = page.locator('[data-wc-inbox-stats]')
		await expect(strip).toBeVisible()
		await expect(strip.locator('[data-wc-stat-open]')).not.toHaveText('')

		// Status filter narrows the list and the count announces it.
		await form.locator('select[name="status"]').selectOption('closed')
		await form.locator('[type="submit"]').click()
		await expect(page.locator('[data-wc-inbox] tr')).toHaveCount(1)
		await expect(page.locator('[data-wc-inbox-count]')).toContainText(/of \d+/)

		// Clear restores the full list.
		await form.locator('[type="reset"]').click()
		await expect(page.locator('[data-wc-inbox] tr')).toHaveCount(total)
	})

	test('impossible filter shows the canonical empty state, not a void', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-inbox] tr').first()).toBeVisible({ timeout: 20000 })

		const form = page.locator('[data-wc-inbox-filter]')
		await form.locator('input[name="q"]').fill('zzz-no-such-case-zzz')
		await form.locator('[type="submit"]').click()
		const empty = page.locator('.wc-empty-state')
		await expect(empty).toBeVisible()
		await expect(empty).toContainText(/no cases match|keine fälle/i)
	})

	test('mobile width renders labelled cards, not a squashed table', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.setViewportSize({ width: 375, height: 800 })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-inbox] tr').first()).toBeVisible({ timeout: 20000 })

		// Card transform: thead is visually clipped (stays in the a11y tree),
		// rows render as blocks, every cell carries a label.
		const thead = page.locator('.wc-table--cards thead')
		await expect(thead).toHaveCSS('position', 'absolute')
		const theadBox = await thead.boundingBox()
		expect(Math.round(theadBox?.width ?? 99)).toBeLessThanOrEqual(1)
		const rowDisplay = await page.locator('.wc-table--cards tr').first().evaluate((el) => getComputedStyle(el).display)
		expect(rowDisplay).toBe('block')
		const cells = page.locator('.wc-table--cards td[data-cell]')
		expect(await cells.count()).toBeGreaterThanOrEqual(5)
		// No horizontal overflow.
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
		expect(overflow).toBeLessThanOrEqual(1)
	})

	test('inbox is axe-clean', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-inbox] tr').first()).toBeVisible({ timeout: 20000 })
		const axe = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(axe.violations, `axe: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
	})
})

test.describe('case page: only legal transitions, archived explains itself', () => {
	let officer
	let receivedId, archivedId
	test.beforeAll(() => {
		officer = provisionOfficer()
		receivedId = makeCase(officer.uid, 'received')
		archivedId = makeCase(officer.uid, 'archived')
	})
	test.afterAll(() => {
		cleanupCase(receivedId)
		cleanupCase(archivedId)
		deprovisionOfficer(officer?.uid)
	})

	test('received case shows only its legal targets', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto(`/apps/whistlecheck/cases/${receivedId}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-status-badge]')).toHaveText('Received', { timeout: 15000 })

		await expect(page.locator('[data-wc-status="in_review"]')).toBeVisible()
		await expect(page.locator('[data-wc-status="closed"]')).toBeVisible()
		await expect(page.locator('[data-wc-status="archived"]')).toBeVisible()
		// Illegal on 'received' — hidden, not merely disabled.
		await expect(page.locator('[data-wc-status="action_taken"]')).toBeHidden()
	})

	test('archived case shows no status buttons, explains, locks reply', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto(`/apps/whistlecheck/cases/${archivedId}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-status-badge]')).toHaveText('Archived', { timeout: 15000 })

		// Terminal state — no transition buttons, an explanation instead.
		await expect(page.locator('[data-wc-status-actions]')).toBeHidden()
		await expect(page.locator('[data-wc-status-terminal]')).toBeVisible()

		// Reply is read-only server-side — the form must be locked + explained
		// before the officer wastes a click on a 409.
		await expect(page.locator('[data-wc-reply-archived]')).toBeVisible()
		await expect(page.locator('[data-wc-reply-form] [type=submit]')).toBeDisabled()
	})

	test('case hero shows received/due metadata', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto(`/apps/whistlecheck/cases/${receivedId}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-status-badge]')).toHaveText('Received', { timeout: 15000 })
		await expect(page.locator('[data-wc-meta-received]')).not.toHaveText('—')
		await expect(page.locator('[data-wc-meta-due]')).toHaveText(futureDate)
	})
})
