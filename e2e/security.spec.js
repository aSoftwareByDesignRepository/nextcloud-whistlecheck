// @ts-check
import { test, expect } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { login } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer, removeFromGroup } from './helpers/officer.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { queryDb } from './helpers/stack.js'

/**
 * ATLAS Lens 2 — executed adversarial probes against the real stack.
 *
 * Every assertion here is a wire/DB observation, not a code-reading claim:
 * uniform 404s compared byte-for-byte, a real DROP TABLE payload checked
 * against the live schema, bearer auth probed without any token at all.
 */

const mysql = queryDb

const NOT_FOUND = { success: false, error: 'NOT_FOUND', message: 'Not found.', fields: [] }

test.beforeAll(() => resetRateBuckets())

const futureDate = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10)

function makeCase(officerUid, { sentinel = 'wcsentinel' } = {}) {
	const hint = sentinel + '-' + randomBytes(16).toString('hex')
	mysql(`INSERT INTO oc_wchk_cases
		(lookup_hint, code_hash, code_salt, key_salt, reporter_wrap, status, received_at, feedback_due)
		VALUES ('${hint}', '${sentinel}-hash-${randomBytes(8).toString('hex')}', '${sentinel}-salt',
		'${randomBytes(8).toString('hex')}', '${sentinel}-rwrap', 'received', NOW(), '${futureDate}')`)
	const caseId = Number(mysql(`SELECT id FROM oc_wchk_cases WHERE lookup_hint='${hint}'`))
	mysql(`INSERT INTO oc_wchk_case_keys (case_id, officer_uid, wrapped_key, granted_at)
		VALUES (${caseId}, '${officerUid}', 'e2e-synthetic-wrap', NOW())`)
	return { caseId, hint }
}

function cleanupCase(caseId, officerUid) {
	try {
		mysql(`DELETE FROM oc_wchk_escalations WHERE case_id=${caseId};
			DELETE FROM oc_wchk_case_keys WHERE case_id=${caseId};
			DELETE FROM oc_wchk_messages WHERE case_id=${caseId};
			DELETE FROM oc_wchk_idem WHERE case_id=${caseId};
			DELETE FROM oc_wchk_audit WHERE case_id=${caseId};
			DELETE FROM oc_wchk_cases WHERE id=${caseId};
			DELETE FROM oc_helpdesk_tickets WHERE created_by='${officerUid}';`)
	} catch { /* best-effort */ }
}

async function fetchJson(page, method, url, data, headers = {}) {
	const requesttoken = await page.evaluate(() => OC.requestToken)
	const res = await page.request.fetch(url, {
		method,
		headers: { requesttoken, 'Content-Type': 'application/json', ...headers },
		data,
	})
	return { status: res.status(), body: await res.json().catch(() => null) }
}

test.describe('security: executed probes', () => {
	let officerA, officerB, outsider
	test.beforeAll(() => {
		officerA = provisionOfficer()
		officerB = provisionOfficer() // group member, but gets NO wrap on A's case
		outsider = provisionOfficer()
		// outsider must NOT be an officer — drop the membership provisioning added.
		removeFromGroup('whistlecheck_officers', outsider.uid)
	})
	test.afterAll(() => {
		deprovisionOfficer(officerA?.uid)
		deprovisionOfficer(officerB?.uid)
		deprovisionOfficer(outsider?.uid)
	})

	test('non-officer gets 403 on every officer API surface', async ({ page }) => {
		await login(page, { username: outsider.uid, password: outsider.password })
		for (const [method, url, data] of [
			['GET', '/apps/whistlecheck/api/inbox'],
			['GET', '/apps/whistlecheck/api/cases/1'],
			['PUT', '/apps/whistlecheck/api/cases/1/status', { status: 'in_review', revision: 1 }],
			['GET', '/apps/whistlecheck/api/audit'],
			['GET', '/apps/whistlecheck/api/stats'],
		]) {
			const res = await fetchJson(page, method, url, data)
			expect(res.status, `${method} ${url}`).toBe(403)
			expect(res.body.error).toBe('NOT_OFFICER')
		}
	})

	test('officer without wrap gets byte-identical canonical 404 (no oracle)', async ({ page }) => {
		const { caseId } = makeCase(officerA.uid)
		try {
			await login(page, { username: officerB.uid, password: officerB.password })
			const bogus = caseId + 900001
			for (const [method, makeUrl, data] of [
				['GET', (id) => `/apps/whistlecheck/api/cases/${id}`],
				['PUT', (id) => `/apps/whistlecheck/api/cases/${id}/status`, { status: 'in_review', revision: 1 }],
				['POST', (id) => `/apps/whistlecheck/api/cases/${id}/messages`, { cipher: 'x', revision: 1 }],
			]) {
				const real = await fetchJson(page, method, makeUrl(caseId), data)
				const miss = await fetchJson(page, method, makeUrl(bogus), data)
				expect(real.status, `${method} real`).toBe(404)
				expect(real.body, `${method} real`).toEqual(NOT_FOUND)
				expect(miss.body, `${method} miss`).toEqual(NOT_FOUND)
				// The oracle test: "exists but not yours" must be indistinguishable
				// from "does not exist" — same status AND same wire body.
				expect(JSON.stringify(real.body)).toBe(JSON.stringify(miss.body))
			}
		} finally {
			cleanupCase(caseId, officerA.uid)
		}
	})

	test('route requirements reject non-numeric case ids before the controller', async ({ page }) => {
		await login(page, { username: officerA.uid, password: officerA.password })
		for (const path of ['/apps/whistlecheck/api/cases/1abc', '/apps/whistlecheck/api/cases/1%20OR%201=1']) {
			const res = await page.request.fetch(path, { headers: { requesttoken: await page.evaluate(() => OC.requestToken) } })
			expect(res.status(), path).toBe(404)
		}
	})

	test('officer write without CSRF token is rejected', async ({ page }) => {
		const { caseId } = makeCase(officerA.uid)
		try {
			await login(page, { username: officerA.uid, password: officerA.password })
			const res = await page.request.fetch(`/apps/whistlecheck/api/cases/${caseId}/status`, {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' }, // no requesttoken
				data: { status: 'in_review', revision: 1 },
			})
			// NC security middleware answers CSRF failures with 412; anything
			// in the 4xx denial family that did NOT reach the controller is a
			// pass, but we assert the status row is untouched regardless.
			expect(res.status()).toBeGreaterThanOrEqual(400)
			expect(res.status()).toBeLessThan(500)
			expect(mysql(`SELECT status FROM oc_wchk_cases WHERE id=${caseId}`)).toBe('received')
		} finally {
			cleanupCase(caseId, officerA.uid)
		}
	})

	test('companion API rejects missing and bogus bearer tokens', async ({ page }) => {
		for (const headers of [{}, { Authorization: 'Bearer ' + randomBytes(32).toString('hex') }]) {
			const res = await page.request.fetch('/apps/whistlecheck/api/companion/profile', {
				headers: { 'X-Wc-Ts': String(Math.floor(Date.now() / 1000)), ...headers },
			})
			expect(res.status()).toBe(401)
		}
	})

	test('SQLi payloads are stored as data, never executed', async ({ page }) => {
		const { caseId } = makeCase(officerA.uid)
		const payload = `'); DROP TABLE oc_wchk_cases;--`
		try {
			await login(page, { username: officerA.uid, password: officerA.password })
			const res = await fetchJson(page, 'POST', `/apps/whistlecheck/api/cases/${caseId}/escalate`, {
				title: payload,
				description: `desc ' UNION SELECT 1,2,3-- `,
				category: 'technical',
				priority: 'normal',
				idemKey: 'sqli-' + randomBytes(8).toString('hex'),
			})
			expect([200, 201], JSON.stringify(res.body)).toContain(res.status)
			// Ground truth: payload landed verbatim as DATA in the ticket row…
			expect(mysql(`SELECT title FROM oc_helpdesk_tickets WHERE created_by='${officerA.uid}'`)).toBe(payload)
			// …and the target table of the injection is still alive and queryable.
			expect(Number(mysql(`SELECT COUNT(*) FROM oc_wchk_cases WHERE id=${caseId}`))).toBe(1)
			expect(mysql(`SELECT status FROM oc_wchk_cases WHERE id=${caseId}`)).toBe('received')
		} finally {
			cleanupCase(caseId, officerA.uid)
		}
	})

	test('case bundle leaks no verifier material to the officer', async ({ page }) => {
		const sentinel = 'wcsec' + randomBytes(6).toString('hex')
		const { caseId } = makeCase(officerA.uid, { sentinel })
		try {
			await login(page, { username: officerA.uid, password: officerA.password })
			const res = await fetchJson(page, 'GET', `/apps/whistlecheck/api/cases/${caseId}`)
			expect(res.status).toBe(200)
			const body = JSON.stringify(res.body)
			// No reporter-verification material may cross the API boundary —
			// an officer must never be able to mint a reporter session.
			for (const leaked of ['lookup_hint', 'code_hash', 'code_salt', 'key_salt', 'reporter_wrap']) {
				expect(body, `bundle leaked ${leaked}`).not.toContain(leaked)
			}
			expect(body).not.toContain(sentinel)
		} finally {
			cleanupCase(caseId, officerA.uid)
		}
	})
})
