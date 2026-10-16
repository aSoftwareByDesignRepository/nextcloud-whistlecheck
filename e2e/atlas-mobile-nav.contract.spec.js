// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open drawer + no h-scroll */
import { test } from '@playwright/test'
import shared from '../../_shared/e2e/atlas-mobile-nav-contract.js'
import { login } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'

const { assertAtlasMobileNav } = shared

let officer
test.beforeAll(() => {
	officer = provisionOfficer()
})
test.afterAll(() => {
	deprovisionOfficer(officer?.uid)
})

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 })
	await login(page, { username: officer.uid, password: officer.password })
	await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#wc-nav-toggle', { timeout: 30000 })
	await assertAtlasMobileNav(page, {
		toggle: page.locator('#wc-nav-toggle'),
		nav: page.locator('#app-navigation'),
		openClass: /wc-nav--open/,
	})
})
