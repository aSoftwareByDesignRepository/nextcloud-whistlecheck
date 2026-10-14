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
 * UX dead-end guards — every state must leave the user a working path:
 *  - a case that cannot be decrypted is still workable (status, assign,
 *    escalate are plaintext ops and must never sit behind the key),
 *  - a keyless officer sees guidance, not just locked rows,
 *  - a closed/archived case explains itself instead of silently
 *    swallowing the reply box,
 *  - slow public submits show progress, not a frozen button.
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

test.describe('locked case stays workable (keyless officer)', () => {
	let officer
	let caseId = null
	test.beforeAll(() => {
		officer = provisionOfficer()
		caseId = makeCase(officer.uid)
	})
	test.afterAll(() => {
		cleanupCase(caseId)
		deprovisionOfficer(officer?.uid)
	})
	test.beforeEach(async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
	})

	test('metadata actions work while content is locked', async ({ page }) => {
		await page.goto(`/apps/whistlecheck/cases/${caseId}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })

		// Locked note explains the empty content area + offers the fix.
		const note = page.locator('[data-wc-locked-note]')
		await expect(note).toBeVisible()
		await expect(note).toContainText(/key/i)
		await expect(note.locator('a[href*="/officers"]')).toBeVisible()

		// Status buttons: real labels, never machine keys, and enabled.
		const statusBtn = page.locator('[data-wc-status="in_review"]')
		await expect(statusBtn).toHaveText('In review')
		await expect(statusBtn).toBeEnabled()
		await statusBtn.click()
		await expect(page.locator('[data-wc-status-badge]')).toHaveText('In review', { timeout: 15000 })

		// Assign select is enabled without the key.
		await expect(page.locator('[data-wc-assign]')).toBeEnabled()

		// Crypto-bound controls stay disabled — but explained by the note.
		await expect(page.locator('[data-wc-reply-form] [type=submit]')).toBeDisabled()
		await expect(page.locator('[data-wc-note-form] [type=submit]')).toBeDisabled()
	})

	test('keyless inbox points at key setup', async ({ page }) => {
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
		const hint = page.locator('[data-wc-inbox-hint]')
		await expect(hint).toContainText(/key/i)
		await expect(hint.locator('a[href*="/officers"]')).toBeVisible()
	})

	test('locked case page is axe-clean', async ({ page }) => {
		await page.goto(`/apps/whistlecheck/cases/${caseId}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-locked-note]')).toBeVisible()
		const axe = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(axe.violations, `axe: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
	})
})

test.describe('public flows: progress + closed states', () => {
	test('report submit shows busy feedback, not a frozen button', async ({ page }) => {
		test.setTimeout(120000)
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.locator('#wc-body').fill('ux busy-state check')
		const btn = page.locator('#wc-report-form [type=submit]')
		await btn.click()
		// Argon2id takes seconds — the button must say so.
		await expect(btn).toBeDisabled()
		await expect(btn).toHaveAttribute('aria-busy', 'true')
		await expect(btn).not.toHaveText('Send report')
		await expect(page.locator('#wc-code-panel')).toBeVisible({ timeout: 90000 })
	})

	test('archived case explains the missing reply box', async ({ page, browser }) => {
		test.setTimeout(120000)
		// Real report → real case code → archive server-side → open thread.
		const pubContext = await browser.newContext()
		const pub = await pubContext.newPage()
		await pub.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await pub.locator('#wc-body').fill('archived-flow check')
		await pub.locator('#wc-report-form [type=submit]').click()
		const codeEl = pub.locator('#wc-code-panel .wc-code-value')
		await expect(codeEl).not.toBeEmpty({ timeout: 90000 })
		const code = (await codeEl.textContent() || '').trim()

		const caseId = Number(mysql(`SELECT id FROM oc_wchk_cases ORDER BY id DESC LIMIT 1`))
		mysql(`UPDATE oc_wchk_cases SET status='archived' WHERE id=${caseId}`)
		try {
			await pub.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
			await pub.locator('#wc-code').fill(code)
			await pub.locator('#wc-follow-form [type=submit]').click()
			await expect(pub.locator('#wc-thread')).toBeVisible({ timeout: 45000 })
			await expect(pub.locator('#wc-reply-form')).toBeHidden()
			await expect(pub.locator('[data-wc-readonly-note]')).toBeVisible()
		} finally {
			cleanupCase(caseId)
			await pubContext.close()
		}
	})
})
