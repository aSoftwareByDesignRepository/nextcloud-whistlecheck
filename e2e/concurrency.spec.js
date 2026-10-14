// @ts-check
import { test, expect } from '@playwright/test'
import { randomBytes, createHash } from 'node:crypto'
import { login } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { queryDb } from './helpers/stack.js'

/**
 * ATLAS Lens 1 — concurrency proofs against the REAL stack.
 *
 * HTTP responses lie under races (a loser may legitimately 429/503 while
 * the winner commits). Ground truth is always the database state after
 * the dust settles: row counts, revision, chain integrity.
 */

const mysql = queryDb

test.beforeAll(() => resetRateBuckets())

const futureDate = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10)

function makeCase(officerUid, { codeHash = null, hint = null } = {}) {
	const lookupHint = hint || randomBytes(32).toString('hex')
	mysql(`INSERT INTO oc_wchk_cases
		(lookup_hint, code_hash, code_salt, key_salt, reporter_wrap, status, received_at, feedback_due)
		VALUES ('${lookupHint}', '${codeHash || randomBytes(32).toString('hex')}', '${randomBytes(8).toString('hex')}',
		'${randomBytes(8).toString('hex')}', 'e2e-wrap', 'received', NOW(), '${futureDate}')`)
	const caseId = Number(mysql(`SELECT id FROM oc_wchk_cases WHERE lookup_hint='${lookupHint}'`))
	mysql(`INSERT INTO oc_wchk_case_keys (case_id, officer_uid, wrapped_key, granted_at)
		VALUES (${caseId}, '${officerUid}', 'e2e-synthetic-wrap', NOW())`)
	return { caseId, lookupHint }
}

function cleanupCase(caseId, officerUid) {
	try {
		mysql(`DELETE FROM oc_wchk_escalations WHERE case_id=${caseId};
			DELETE FROM oc_wchk_case_keys WHERE case_id=${caseId};
			DELETE FROM oc_wchk_messages WHERE case_id=${caseId};
			DELETE FROM oc_wchk_idem WHERE case_id=${caseId};
			DELETE FROM oc_wchk_cases WHERE id=${caseId};
			DELETE FROM oc_helpdesk_tickets WHERE created_by='${officerUid}';`)
	} catch { /* best-effort */ }
}

/** Fire N identical requests truly in parallel inside the officer session. */
async function parallel(page, n, method, url, data) {
	const requesttoken = await page.evaluate(() => OC.requestToken)
	return Promise.all(Array.from({ length: n }, () =>
		page.request.fetch(url, {
			method,
			headers: { requesttoken, 'Content-Type': 'application/json' },
			data,
		}).then(async (res) => ({ status: res.status(), body: await res.json().catch(() => ({})) }))
	))
}

test.describe('concurrency: ground truth is the DB', () => {
	let officer
	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => deprovisionOfficer(officer?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
	})

	test('escalation idempotency race → exactly one ticket + one reference row', async ({ page }) => {
		const { caseId } = makeCase(officer.uid)
		try {
			const idem = 'race-' + randomBytes(8).toString('hex')
			const results = await parallel(page, 4, 'POST',
				`/apps/whistlecheck/api/cases/${caseId}/escalate`,
				{ title: 'Raced export', description: 'Four parallel calls, one ticket.', idemKey: idem })

			// Every caller gets a coherent answer — 200/201 win or replay, never
			// a naked 500 from the unique-violation race window.
			for (const r of results) {
				expect([200, 201], `unexpected status ${r.status}: ${JSON.stringify(r.body)}`).toContain(r.status)
			}
			const escRows = Number(mysql(
				`SELECT COUNT(*) FROM oc_wchk_escalations WHERE case_id=${caseId} AND idem_key='${idem}'`))
			expect(escRows, 'escalation ref rows').toBe(1)
			const tickets = Number(mysql(
				`SELECT COUNT(*) FROM oc_helpdesk_tickets WHERE created_by='${officer.uid}'`))
			expect(tickets, 'minted tickets').toBe(1)
		} finally { cleanupCase(caseId, officer.uid) }
	})

	test('status CAS race → one winner, losers get 409, revision advances once', async ({ page }) => {
		const { caseId } = makeCase(officer.uid)
		try {
			// Fresh case starts at revision 0 — three writers race on the same base.
			const results = await parallel(page, 3, 'PUT',
				`/apps/whistlecheck/api/cases/${caseId}/status`,
				{ status: 'in_review', revision: 0 })

			const winners = results.filter((r) => r.status === 200)
			const conflicts = results.filter((r) => r.status === 409)
			expect(winners.length, `winners: ${JSON.stringify(results.map((r) => r.status))}`).toBe(1)
			expect(conflicts.length, 'losers must get 409').toBe(2)

			const rev = Number(mysql(`SELECT revision FROM oc_wchk_cases WHERE id=${caseId}`))
			expect(rev, 'revision advanced exactly once').toBe(1)
			const status = mysql(`SELECT status FROM oc_wchk_cases WHERE id=${caseId}`)
			expect(status).toBe('in_review')
			const statusEvents = Number(mysql(
				`SELECT COUNT(*) FROM oc_wchk_audit WHERE case_id=${caseId} AND event_type LIKE '%status%'`))
			expect(statusEvents, 'exactly one status audit event').toBe(1)
		} finally { cleanupCase(caseId, officer.uid) }
	})

	test('reporter reply race → single message row, losers converge', async ({ page }) => {
		const verifier = randomBytes(32).toString('hex')
		const { caseId, lookupHint } = makeCase(officer.uid, {
			hint: randomBytes(32).toString('hex'),
			codeHash: createHash('sha256').update(verifier).digest('hex'),
		})
		try {
			const ikey = 'reply-race-' + randomBytes(6).toString('hex')
			// Public endpoint — no session needed; the browser context just
			// carries the requests. ikey dedupes the racing writers.
			const results = await Promise.all(Array.from({ length: 4 }, () =>
				page.request.fetch('/apps/whistlecheck/api/public/case/reply', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					data: { hint: lookupHint, verifier, cipher: 'raced-cipher', ikey },
				}).then(async (res) => ({ status: res.status(), body: await res.json().catch(() => ({})) }))
			))

			// Winners create; losers either replay (same 2xx) or lose the
			// bucket advisory lock (429 — that IS the throttle working).
			// What must never happen: a 500 from the unique-violation window.
			for (const r of results) {
				expect([200, 201, 429], `unexpected status ${r.status}: ${JSON.stringify(r.body)}`).toContain(r.status)
			}
			const msgs = Number(mysql(
				`SELECT COUNT(*) FROM oc_wchk_messages WHERE case_id=${caseId} AND direction='reporter'`))
			expect(msgs, 'reporter message rows after 4 racing replies').toBe(1)
			const ikeys = Number(mysql(
				`SELECT COUNT(*) FROM oc_wchk_idem WHERE ikey='reply-${ikey}'`))
			expect(ikeys, 'idempotency markers').toBe(1)
		} finally { cleanupCase(caseId, officer.uid) }
	})

	test('audit chain stays intact under the raced appends above', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		const res = await page.request.fetch('/apps/whistlecheck/api/audit', {
			headers: { requesttoken: await page.evaluate(() => OC.requestToken) },
		})
		expect(res.status()).toBe(200)
		const body = await res.json()
		expect(body.data?.chain_ok ?? body.chain_ok, 'chain_ok').toBe(true)
		// No duplicate seq anywhere — the two-layer serialization held.
		const dupes = mysql(`SELECT COUNT(*) FROM (SELECT seq FROM oc_wchk_audit GROUP BY seq HAVING COUNT(*)>1) d`)
		expect(Number(dupes), 'duplicate audit seqs').toBe(0)
	})
})
