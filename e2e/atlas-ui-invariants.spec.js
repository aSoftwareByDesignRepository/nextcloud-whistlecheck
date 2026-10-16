// @ts-check
/**
 * Atlas UI invariants — write-surface coverage for WhistleCheck.
 * Complements atlas-contract.spec.js (a11y-dom / XSS / console / i18n per page).
 *
 * Covered here (shared harness: apps/_shared/e2e/atlas-ui-invariants.js):
 *  - mutation → surface freshness (category created server-side renders in list)
 *  - double-submit guard on the settings CAS form (in-flight disable)
 *  - form survival on server 5xx (settings values retained + error toast)
 *  - O(1) list requests on the inbox (no per-row N+1)
 *  - date boundary: overdue badge under a frozen clock (strict <)
 */
import { test, expect } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import shared from '../../_shared/e2e/atlas-ui-invariants.js'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { queryDb, cleanupCase } from './helpers/stack.js'

const {
	assertSurfaceFresh,
	assertNoDuplicateSubmit,
	assertFormSurvivesFailure,
	countApiRequests,
	freezeClock,
} = shared

const APP = '/apps/whistlecheck'
const RUN = Date.now().toString(36) + randomBytes(3).toString('hex')
const CAT_MARK = `ATLAS-FRESH-${RUN}`

async function api(page, method, path, body) {
	return page.request.fetch(`${APP}${path}`, {
		method,
		headers: {
			requesttoken: await page.evaluate(() => OC.requestToken),
			'Content-Type': 'application/json',
		},
		data: body,
	})
}

/** Synthetic case row wrapped for the fixture officer (meta visible, content locked). */
function makeCase(officerUid, { status = 'received', due = null } = {}) {
	const hint = randomBytes(8).toString('hex')
	const dueSql = due ? `'${due}'` : 'NULL'
	queryDb(`INSERT INTO oc_wchk_cases
		(lookup_hint, code_hash, code_salt, key_salt, reporter_wrap, status, received_at, feedback_due)
		VALUES ('${hint}', '${randomBytes(32).toString('hex')}', '${randomBytes(8).toString('hex')}',
		'${randomBytes(8).toString('hex')}', 'e2e-wrap', '${status}', NOW(), ${dueSql})`)
	const caseId = Number(queryDb(`SELECT id FROM oc_wchk_cases WHERE lookup_hint='${hint}'`))
	queryDb(`INSERT INTO oc_wchk_case_keys (case_id, officer_uid, wrapped_key, granted_at)
		VALUES (${caseId}, '${officerUid}', 'e2e-synthetic-wrap', NOW())`)
	return caseId
}

test.describe('ATLAS_UI_INVARIANTS write surfaces', () => {
	let officer
	const caseIds = []
	test.beforeAll(() => {
		resetRateBuckets()
		officer = provisionOfficer()
	})
	test.afterAll(() => {
		for (const id of caseIds) cleanupCase(id)
		try { queryDb(`DELETE FROM oc_wchk_categories WHERE label_json LIKE '%${CAT_MARK}%'`) } catch { /* best-effort */ }
		deprovisionOfficer(officer?.uid)
	})

	test('mutation → surface fresh: category created server-side renders in list', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto(`${APP}/settings/categories`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)

		await assertSurfaceFresh(page, {
			content: '[data-wc-cats]',
			mutate: async () => {
				const res = await api(page, 'POST', '/api/categories',
					{ labels: { de: `${CAT_MARK} DE`, en: `${CAT_MARK} EN` } })
				expect(res.status()).toBe(201)
			},
			visit: () => page.reload({ waitUntil: 'domcontentloaded' }),
			expect: { present: CAT_MARK },
		})
	})

	test('double-submit: settings save fires at most one PUT', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto(`${APP}/settings/channel`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		const btn = page.locator('[data-wc-settings-form] [type=submit]')
		await expect(btn).toBeVisible()

		await assertNoDuplicateSubmit(page, {
			mutatingUrl: '/apps/whistlecheck/api/settings',
			method: 'PUT',
			// force: the guard under test is the in-flight disable — a real
			// user can machine-gun clicks regardless of disabled state.
			submit: () => btn.click({ force: true, timeout: 5000 }).catch(() => {}),
		})
	})

	test('form survives a 5xx: settings values retained + error toast', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto(`${APP}/settings/channel`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		const org = `ATLAS-ORG-${RUN}`

		await assertFormSurvivesFailure(page, {
			failUrl: '/apps/whistlecheck/api/settings',
			fields: { '#wc-org': org },
			submit: () => page.locator('[data-wc-settings-form] [type=submit]').click(),
			errorSel: '#wc-toasts .wc-toast-error',
		})
	})

	test('inbox render is O(1): no per-row API calls', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		const hits = await countApiRequests(page, async () => {
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)
			await page.waitForSelector('[data-wc-inbox][aria-busy="false"], [data-wc-inbox]:not([aria-busy])',
				{ timeout: 20000 }).catch(() => {})
		}, /\/apps\/whistlecheck\/api\//)

		const perRow = hits.filter((h) => /\/api\/cases\/\d/.test(h.url))
		expect(perRow, `per-row calls leaked: ${JSON.stringify(perRow)}`).toHaveLength(0)
		expect(hits.filter((h) => h.url.includes('/api/inbox')).length).toBeLessThanOrEqual(1)
	})

	test('date boundary: overdue badge honors the frozen clock', async ({ page }) => {
		const due = '2099-01-01'
		const id = makeCase(officer.uid, { status: 'received', due })
		caseIds.push(id)
		await login(page, { username: officer.uid, password: officer.password })

		// Due date itself is NOT overdue (strict <) …
		await freezeClock(page, `${due}T12:00:00Z`)
		await page.goto(`${APP}/cases/${id}`, { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('[data-wc-case-body]:not([aria-busy="true"]), [data-wc-overdue-badge]')
			.first()).toBeAttached({ timeout: 20000 })
		await expect(page.locator('[data-wc-overdue-badge]')).toBeHidden()

		// … the day after, it is.
		await page.clock.setFixedTime(new Date('2099-01-02T00:00:01Z'))
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-wc-overdue-badge]')).toBeVisible({ timeout: 20000 })
	})
})
