// @ts-check
/**
 * Atlas web contracts for WhistleCheck — rendered surface, mobile nav,
 * vertical scroll. Complements atlas-contract.spec.js (a11y-dom / XSS /
 * visual-design) with the three mechanical surface contracts the farm
 * gates on:
 *
 *  - ATLAS_RENDERED_SURFACE_CONTRACT: every page surface with forms/lists
 *    must render markers, centred select values, non-zero icons, and
 *    non-centred form controls — DOM presence alone is not proof.
 *  - ATLAS_MOBILE_NAV_CONTRACT: the in-page #wc-nav-toggle opens the
 *    #app-navigation drawer at ≤390px without horizontal page pan.
 *  - ATLAS_VERTICAL_SCROLL_CONTRACT: #app-content.wc-app carries
 *    `overflow-x: clip` — CSS Overflow L3 makes an unpaired clip compute
 *    overflow-y to clip on height-locked shells; the last control of a
 *    tall page must be reachable by scrolling.
 *
 * Officer legs provision a throwaway officer (unique uid — hermetic);
 * public legs are anonymous.
 */
import { test, expect } from '@playwright/test'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'
import { ensureReporterTarget } from './helpers/public-target.js'
import { resetRateBuckets } from './helpers/ratelimit.js'
import { assertAtlasRenderedSurface } from '../../_shared/e2e/atlas-rendered-surface-contract.js'
import { assertAtlasMobileNav } from '../../_shared/e2e/atlas-mobile-nav-contract.js'
import { assertAtlasVerticalScrollReachable } from '../../_shared/e2e/atlas-vertical-scroll-contract.js'

const CONTENT = '#app-content.wc-app'
const NAV_EXCLUDE = '#app-navigation, nav, [role="navigation"]'

test.beforeAll(() => {
	resetRateBuckets()
	ensureReporterTarget() // public report surface must bootstrap officer keys
})

test.describe('ATLAS_RENDERED_SURFACE_CONTRACT — officer pages', () => {
	let officer
	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => deprovisionOfficer(officer?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	const officerSurfaces = [
		['/apps/whistlecheck/', 'inbox'],
		['/apps/whistlecheck/officers', 'officers'],
		['/apps/whistlecheck/audit', 'audit'],
		['/apps/whistlecheck/stats', 'stats'],
		['/apps/whistlecheck/verbal', 'verbal'],
		['/apps/whistlecheck/settings/channel', 'settings'],
		['/apps/whistlecheck/settings/categories', 'settings'],
		['/apps/whistlecheck/settings/support', 'settings'],
	]

	for (const [path, name] of officerSurfaces) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name}: ${path}`, async ({ page }) => {
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)
			// JS-rendered lists start as a loading row — wait it out so markers
			// and icons are judged on the final surface.
			await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 30_000 })
			await assertAtlasRenderedSurface(page, {
				content: CONTENT,
				navExclude: NAV_EXCLUDE,
			})
		})
	}
})

test.describe('ATLAS_RENDERED_SURFACE_CONTRACT — public pages (anonymous)', () => {
	test('ATLAS_RENDERED_SURFACE_CONTRACT report form', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#wc-report-form')).toBeVisible({ timeout: 30_000 })
		await assertAtlasRenderedSurface(page, {
			content: '.wc-public',
			navExclude: NAV_EXCLUDE,
		})
	})

	test('ATLAS_RENDERED_SURFACE_CONTRACT follow-up form', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await expect(page.locator('#wc-follow-form')).toBeVisible({ timeout: 30_000 })
		await assertAtlasRenderedSurface(page, {
			content: '.wc-public',
			navExclude: NAV_EXCLUDE,
		})
	})
})

test.describe('ATLAS_MOBILE_NAV_CONTRACT', () => {
	let officer
	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => deprovisionOfficer(officer?.uid))

	test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer, no h-scroll', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 })
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await assertAtlasMobileNav(page, {
			toggle: page.locator('#wc-nav-toggle'),
			nav: page.locator('#app-navigation'),
			openClass: /wc-nav--open/,
		})
	})
})

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	let officer
	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => deprovisionOfficer(officer?.uid))

	test('ATLAS_VERTICAL_SCROLL_CONTRACT settings form scrolls to Save', async ({ page }) => {
		// Short viewport forces overflow on the settings form; the Save CTA
		// is the last control and must be reachable, never clipped.
		await page.setViewportSize({ width: 1280, height: 480 })
		await login(page, { username: officer.uid, password: officer.password })
		await page.goto('/apps/whistlecheck/settings/retention', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('[data-wc-settings-form] [type=submit]', { timeout: 30_000 })
		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '[data-wc-settings-form] [type=submit]',
			bottomSlopPx: 12,
		})
	})

	test('ATLAS_VERTICAL_SCROLL_CONTRACT public report scrolls to submit', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 560 })
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		await page.waitForSelector('#wc-report-form [type=submit]', { timeout: 30_000 })
		// Guest layout: #content.wc-public is position:fixed; the inner
		// .wc-public-main is the real scroller (measured: sh 863 > ch 560).
		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '.wc-public-main',
			target: '#wc-report-form [type=submit]',
			bottomSlopPx: 12,
		})
	})
})
