// @ts-check
/**
 * App-store screenshot capture (opt-in — not part of the default suite).
 *
 * Provisions a throwaway officer, runs the real browser key ceremony,
 * seeds demo cases through the real submit path, then captures the
 * surfaces that ship in screenshots/appstore/.
 *
 *   WC_APPSTORE_SHOTS=1 npx playwright test e2e/appstore-shots.spec.js
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'

const OFFICER_PASS = 'demo-passphrase-9x7'
const shotsDir = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'screenshots', 'appstore')
const composeDir = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')

function seedDemo(uid, cleanup = false) {
	const args = [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud',
		'php', '/var/www/html/custom_apps/whistlecheck/scripts/seed-demo.php',
		`--uid=${uid}`,
	]
	if (cleanup) args.push('--cleanup')
	const out = execFileSync('docker', args, { cwd: composeDir, stdio: 'pipe', timeout: 60000 }).toString()
	const m = out.match(/:\s*([\d,]+)\s*$/)
	return m ? m[1].split(',').map(Number) : []
}

test.describe('appstore screenshots', () => {
	test.skip(process.env.WC_APPSTORE_SHOTS !== '1', 'opt-in: set WC_APPSTORE_SHOTS=1')

	test.use({ viewport: { width: 1440, height: 900 } })

	let officer
	let caseIds = []
	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => {
		try { seedDemo(officer.uid, true) } catch { /* best effort */ }
		deprovisionOfficer(officer?.uid)
	})

	test('01 public report form', async ({ page }) => {
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#app-content, body')).toBeVisible()
		await page.waitForTimeout(1500) // let libsodium/boot settle
		await page.screenshot({ path: `${shotsDir}/01.png`, fullPage: false })
	})

	test('02-06 officer surfaces', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })

		// Real key ceremony so seeded wraps decrypt in this browser.
		await page.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-wc-keyreg]')).toBeVisible({ timeout: 15000 })
		await page.locator('[data-wc-newpass]').fill(OFFICER_PASS)
		await page.locator('[data-wc-newpass2]').fill(OFFICER_PASS)
		await page.locator('[data-wc-genkey]').click()
		const officerRow = page.locator('tbody[data-wc-officers] tr', { hasText: officer.uid })
		await expect(officerRow).toContainText('active', { timeout: 15000 })

		caseIds = seedDemo(officer.uid)
		expect(caseIds.length).toBeGreaterThan(0)

		// Inbox
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(1500)
		await page.screenshot({ path: `${shotsDir}/02.png`, fullPage: false })

		// Case detail — passphrase cached in sessionStorage from the ceremony.
		await page.goto(`/apps/whistlecheck/cases/${caseIds[0]}`, { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(1500)
		await page.screenshot({ path: `${shotsDir}/03.png`, fullPage: false })

		await page.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(800)
		await page.screenshot({ path: `${shotsDir}/04.png`, fullPage: false })

		await page.goto('/apps/whistlecheck/stats', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(800)
		await page.screenshot({ path: `${shotsDir}/05.png`, fullPage: false })

		await page.goto('/apps/whistlecheck/settings', { waitUntil: 'domcontentloaded' })
		await page.waitForTimeout(800)
		await page.screenshot({ path: `${shotsDir}/06.png`, fullPage: false })
	})
})
