// @ts-check
/**
 * Atlas shared contracts for WhistleCheck officer surfaces.
 *
 * Covered here:
 *  - a11y-dom: icon-only names, <24px hit targets, aria-hidden focusables
 *    (checkboxes inside 44px label rows are allow-listed — the label is
 *    the target),
 *  - stored-XSS sentinel + raw-i18n-key leaks + console errors per page,
 *  - visual-design: the inbox must ship the canonical filter recipe
 *    (recipe A panel with Apply + Clear, every field labelled), a stat
 *    strip, and card-transform tables with data-cell labels.
 *
 * Suite-level journey/dead-end coverage lives in ux-flows.spec.js and
 * ux-deadends.spec.js.
 */
import { test, expect } from '@playwright/test'
import shared from '../../_shared/e2e/atlas-ui-invariants.js'
import { assertVisualDesign } from '../../_shared/e2e/atlas-visual-design-contract.js'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'

const {
	assertA11yDom,
	assertNoInjection,
	assertNoConsoleErrors,
	assertNoRawI18nKeys,
	trackConsoleErrors,
} = shared

const CONTENT = '#app-content.wc-app'
// Checkbox inputs sit inside 44px label rows — the label is the target.
const SIZE_ALLOW = 'input[type="checkbox"], input[type="radio"]'

const SURFACES = [
	['/', 'inbox'],
	['/officers', 'officers'],
	['/audit', 'audit'],
	['/stats', 'stats'],
	['/settings', 'settings'],
	['/verbal', 'verbal'],
]

let officer
test.beforeAll(() => {
	officer = provisionOfficer()
})
test.afterAll(() => {
	deprovisionOfficer(officer?.uid)
})

test.describe('ATLAS_UI_INVARIANTS', () => {
	for (const [path, name] of SURFACES) {
		test(`a11y-dom + console + raw-i18n: ${name}`, async ({ page }) => {
			const consoleErrs = trackConsoleErrors(page)
			await login(page, { username: officer.uid, password: officer.password })
			await page.setViewportSize({ width: 1280, height: 800 })
			await page.goto(`/apps/whistlecheck${path}`, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)
			// Content arrives async — give the first data region time.
			await page.waitForSelector(`${CONTENT} [aria-busy="false"], ${CONTENT} [aria-busy]:not([aria-busy="true"])`, { timeout: 30000 }).catch(() => {})

			const findings = await assertA11yDom(page, {
				content: CONTENT,
				minTarget: 24,
				sizeAllow: SIZE_ALLOW,
			})
			expect(findings, `a11y-dom findings on ${name}`).toEqual([])
			await assertNoInjection(page)
			await assertNoRawI18nKeys(page, { content: CONTENT })
			assertNoConsoleErrors(consoleErrs)
		})
	}
})

test.describe('ATLAS_VISUAL_DESIGN', () => {
	test('inbox: filter recipe + stat strip + card tables', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		// Stat strip is hidden until data lands — wait for it.
		await expect(page.locator('[data-wc-inbox-stats]')).toBeVisible({ timeout: 20000 })

		await assertVisualDesign(page, {
			prefix: 'wc',
			content: CONTENT,
			filterSurfaces: ['.wc-app--inbox'],
			statSurface: '.wc-app--inbox',
			cardTables: ['.wc-table--cards'],
		})
	})

	test('list pages: every table keeps card labels', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		for (const path of ['/officers', '/audit', '/stats', '/settings/categories']) {
			await page.goto(`/apps/whistlecheck${path}`, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)
			await assertVisualDesign(page, {
				prefix: 'wc',
				content: CONTENT,
				cardTables: ['.wc-table--cards'],
			})
		}
	})
})
