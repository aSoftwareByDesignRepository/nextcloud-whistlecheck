// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — the tall public report form must scroll
 * to its Send control; the officer settings form must reach its Save
 * control. Guards unpaired overflow-x:clip / overflow hidden truncation.
 */
import { test } from '@playwright/test'
import shared from '../../_shared/e2e/atlas-vertical-scroll-contract.js'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'

const { assertAtlasVerticalScrollReachable } = shared

let officer
test.beforeAll(() => {
	officer = provisionOfficer()
})
test.afterAll(() => {
	deprovisionOfficer(officer?.uid)
})

test('ATLAS_VERTICAL_SCROLL_CONTRACT public report scrolls to Send', async ({ page }) => {
	await page.setViewportSize({ width: 900, height: 560 })
	await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#wc-report-form button[type="submit"]', { timeout: 30_000 })

	// Guest layout: #content.wc-public is position:fixed; the inner
	// .wc-public-main is the real scroller (measured: scrollMax=331).
	await assertAtlasVerticalScrollReachable(page, {
		scrollport: '.wc-public-main',
		target: '#wc-report-form button[type="submit"]',
	})
})

test('ATLAS_VERTICAL_SCROLL_CONTRACT settings channel scrolls to Save', async ({ page }) => {
	await page.setViewportSize({ width: 1280, height: 560 })
	await login(page, { username: officer.uid, password: officer.password })
	await page.goto('/apps/whistlecheck/settings/channel', { waitUntil: 'domcontentloaded' })
	await assertNotServerUpdater(page)
	await page.waitForSelector('[data-wc-settings-form] [type="submit"]', { timeout: 30_000 })

	await assertAtlasVerticalScrollReachable(page, {
		scrollport: '#app-content',
		target: '[data-wc-settings-form] [type="submit"]',
	})
})
