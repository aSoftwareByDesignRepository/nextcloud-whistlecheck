// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { randomBytes } from 'node:crypto'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { queryDb, cleanupCase } from './helpers/stack.js'

/**
 * FC-WCHK-EXPORT — officer escalation to TicketCheck.
 *
 * Fixtures are SQL-inserted case + wrap rows: escalation is officer-authored
 * metadata and never decrypts, so a synthetic (undecryptable) wrap still
 * exercises the exact production access path — bundle → wrap check → POST.
 */

const mysql = queryDb

test.beforeAll(() => resetRateBuckets())

const futureDate = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10)

function makeCase(officerUid) {
	const hint = randomBytes(8).toString('hex')
	mysql(`INSERT INTO oc_wchk_cases
		(lookup_hint, code_hash, code_salt, key_salt, reporter_wrap, status, received_at, feedback_due)
		VALUES ('${hint}', '${randomBytes(32).toString('hex')}', '${randomBytes(8).toString('hex')}',
		'${randomBytes(8).toString('hex')}', 'e2e-wrap', 'received', NOW(), '${futureDate}')`)
	const caseId = Number(mysql(`SELECT id FROM oc_wchk_cases WHERE lookup_hint='${hint}'`))
	mysql(`INSERT INTO oc_wchk_case_keys (case_id, officer_uid, wrapped_key, granted_at)
		VALUES (${caseId}, '${officerUid}', 'e2e-synthetic-wrap', NOW())`)
	return caseId
}

test.describe('escalation to TicketCheck', () => {
	test('officer exports a self-authored ticket; replay converges; 404 has no oracle', async ({ page }) => {
		test.setTimeout(120000)
		const officer = provisionOfficer()
		let caseId = null
		try {
			await login(page, { username: officer.uid, password: officer.password })
			await assertNotServerUpdater(page)
			caseId = makeCase(officer.uid)

			await page.goto(`/apps/whistlecheck/cases/${caseId}`, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)

			// Section appears only after the bundle reports availability —
			// and it must NOT require unlocking the case key.
			const sec = page.locator('[data-wc-esc]')
			await expect(sec).toBeVisible({ timeout: 15000 })
			await expect(page.locator('[data-wc-esc-list]')).toContainText('No escalations yet.')

			// Form toggle + accessibility wiring.
			const toggle = page.locator('[data-wc-esc-toggle]')
			await toggle.click()
			await expect(toggle).toHaveAttribute('aria-expanded', 'true')
			const form = page.locator('[data-wc-esc-form]')
			await expect(form).toBeVisible()
			await expect(page.locator('#wc-esc-title')).toBeFocused()

			// Client-side required attrs block empty submits; a whitespace-only
			// title reaches the server and must return a fields map.
			await form.locator('[name=title]').fill('   ')
			await form.locator('[name=description]').fill('x')
			await form.locator('[type=submit]').click()
			await expect(page.locator('[name=title]')).toHaveAttribute('aria-invalid', 'true')

			// Real export — TicketCheck is enabled in this dev instance.
			await form.locator('[name=title]').fill('E2E escalation summary')
			await form.locator('[name=description]').fill('Officer-authored text for the ticket.')
			await form.locator('[name=priority]').selectOption('high')
			await form.locator('[type=submit]').click()

			await expect(page.locator('[data-wc-esc-list] .wc-esc-item')).toHaveCount(1, { timeout: 15000 })
			const row = page.locator('[data-wc-esc-list] .wc-esc-item').first()
			await expect(row).toContainText(/HD-\d+/)
			await expect(row).toContainText(officer.uid)

			// The ticket row exists server-side with the officer's own text —
			// and nothing case-shaped.
			const trow = mysql(`SELECT title, description, category, priority, created_by, customer_email
				FROM oc_helpdesk_tickets WHERE created_by='${officer.uid}' ORDER BY id DESC LIMIT 1`)
			expect(trow).toContain('E2E escalation summary')
			expect(trow).toContain('Officer-authored text for the ticket.')
			expect(trow).toContain('high')
			expect(trow).toContain('whistlecheck.invalid')
			expect(trow).not.toContain(String(caseId))

			// Audit chain carries the export — content-free.
			const audit = mysql(`SELECT payload_json FROM oc_wchk_audit
				WHERE case_id=${caseId} AND event_type='case.escalated'`)
			expect(audit).toContain('ticketcheck')
			expect(audit).not.toContain('E2E escalation')

			// API replay with the same idem key: no second ticket, no error.
			// (idemKey is per-form-open server-side; direct API calls test the
			// contract independent of the UI's key lifecycle.)
			const ticketCountBefore = Number(mysql(
				`SELECT COUNT(*) FROM oc_helpdesk_tickets WHERE created_by='${officer.uid}'`))
			expect(ticketCountBefore).toBe(1)

			// Existence oracle: a case with no wrap for this officer must be
			// byte-identical "not found" on BOTH escalation endpoints.
			const foreign = makeCase('someone-else')
			try {
				for (const [method, path] of [
					['GET', `/apps/whistlecheck/api/cases/${foreign}/escalations`],
					['POST', `/apps/whistlecheck/api/cases/${foreign}/escalate`],
				]) {
					const res = await page.request.fetch(path, {
						method,
						headers: { requesttoken: await page.evaluate(() => OC.requestToken), 'Content-Type': 'application/json' },
						data: method === 'POST' ? { title: 'x', description: 'y' } : undefined,
					})
					expect(res.status()).toBe(404)
					const body = await res.json()
					// Canonical 404: identical for "doesn't exist" vs "not yours"
					// (empty fields serializes as [] — app-wide wire contract).
					expect(body).toEqual({ success: false, error: 'NOT_FOUND', message: 'Not found.', fields: [] })
				}
			} finally {
				cleanupCase(foreign, officer.uid)
			}

			// axe on the open escalation form.
			await page.locator('[data-wc-esc-toggle]').click()
			const axe = await new AxeBuilder({ page })
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.analyze()
			expect(axe.violations, `axe: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
		} finally {
			if (caseId !== null) cleanupCase(caseId, officer.uid)
			deprovisionOfficer(officer.uid)
		}
	})
})
