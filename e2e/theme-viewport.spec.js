// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer, setUserTheme } from './helpers/officer.js'
import { resetRateBuckets } from './helpers/ratelimit.js'

/**
 * Theme & viewport matrix — the Nextcloud UI-master contract:
 *  - no horizontal overflow at 320 / 375 / 414 / 768 / 1024 / 1280
 *  - dark theme via the real user setting (server-rendered CSS vars —
 *    prefers-color-scheme emulation cannot reach them)
 *  - forced-colors + prefers-contrast emulation for the hardening blocks
 *
 * Load once per page, then resize — reloading would burn 'params'
 * rate-limit hits (10/min) for zero extra coverage.
 */

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
const VIEWPORTS = [320, 375, 414, 768, 1024, 1280]

test.beforeAll(() => resetRateBuckets())

/**
 * @param {import('@playwright/test').Page} page
 * @param {number} width
 */
async function assertNoOverflow(page, width) {
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth - document.documentElement.clientWidth
	)
	expect(overflow, `horizontal overflow at ${width}px`).toBeLessThanOrEqual(1)
}

test.describe('viewport matrix', () => {
	test('public report page: no overflow 320→1280', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 844 })
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		for (const w of VIEWPORTS) {
			await page.setViewportSize({ width: w, height: 844 })
			await assertNoOverflow(page, w)
		}
		// Primary controls stay reachable at the smallest width.
		await page.setViewportSize({ width: 320, height: 844 })
		await expect(page.locator('#wc-body')).toBeVisible()
		const box = await page.locator('#wc-report-form [type=submit]').boundingBox()
		expect(box?.height ?? 0).toBeGreaterThanOrEqual(44) // touch target
	})

	test('public case page: no overflow 320→1280', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 844 })
		await page.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		for (const w of VIEWPORTS) {
			await page.setViewportSize({ width: w, height: 844 })
			await assertNoOverflow(page, w)
		}
	})

	test.describe('officer pages (fresh fixture)', () => {
		let officer
		test.beforeAll(() => { officer = provisionOfficer() })
		test.afterAll(() => deprovisionOfficer(officer?.uid))
		test.beforeEach(async ({ page }) => {
			await login(page, { username: officer.uid, password: officer.password })
		})

		test('officer inbox: no overflow 320→1280', async ({ page }) => {
			await page.setViewportSize({ width: 320, height: 844 })
			await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
			await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
			for (const w of VIEWPORTS) {
				await page.setViewportSize({ width: w, height: 844 })
				await assertNoOverflow(page, w)
			}
		})

		test('officers page: no overflow 320→1280', async ({ page }) => {
			await page.setViewportSize({ width: 320, height: 844 })
			await page.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
			for (const w of VIEWPORTS) {
				await page.setViewportSize({ width: w, height: 844 })
				await assertNoOverflow(page, w)
			}
		})
	})
})

test.describe('dark theme (fresh fixture, real user setting)', () => {
	let officer
	test.beforeAll(() => {
		officer = provisionOfficer()
		setUserTheme(officer.uid, '["dark"]')
	})
	test.afterAll(() => deprovisionOfficer(officer?.uid))
	test.beforeEach(async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
	})

	test('inbox renders dark vars + passes axe AA', async ({ page }) => {
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
		// The dark theme must actually reach the surface — main background
		// resolves dark, not a stale light value.
		const bg = await page.evaluate(() => {
			const v = getComputedStyle(document.getElementById('content') || document.body).backgroundColor
			return v.match(/\d+/g)?.map(Number) || [255]
		})
		expect(Math.max(...bg.slice(0, 3)), `main background not dark: rgb(${bg})`).toBeLessThan(80)
		const axe = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze()
		expect(axe.violations, `dark axe: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
	})

	test('officers page passes axe AA under dark theme', async ({ page }) => {
		await page.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
		const axe = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze()
		expect(axe.violations, `dark axe: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
	})
})

test.describe('NC high-contrast themes (real user setting)', () => {
	// The auditor toggles these in theming settings — they are real theme
	// ids shipped by the theming app, not media emulation.
	for (const themeId of ['highcontrast', 'dark-highcontrast']) {
		test.describe(`theme: ${themeId}`, () => {
			let officer
			test.beforeAll(() => {
				officer = provisionOfficer()
				setUserTheme(officer.uid, `["${themeId}"]`)
			})
			test.afterAll(() => deprovisionOfficer(officer?.uid))

			test(`officer inbox passes axe AA under ${themeId}`, async ({ page }) => {
				await login(page, { username: officer.uid, password: officer.password })
				await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
				await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
				const axe = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze()
				expect(axe.violations, `${themeId} axe: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
			})
		})
	}
})

test.describe('RTL direction', () => {
	let officer
	test.beforeAll(() => { officer = provisionOfficer() })
	test.afterAll(() => deprovisionOfficer(officer?.uid))

	test('drawer hides off-canvas to the right under dir=rtl', async ({ page }) => {
		await login(page, { username: officer.uid, password: officer.password })
		await page.setViewportSize({ width: 390, height: 844 })
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
		// NC carries direction on <body dir=…>, not <html> — flip the same
		// element the server template does for RTL locales.
		await page.evaluate(() => { document.body.dir = 'rtl' })
		await page.waitForTimeout(250) // let mobile-nav re-evaluate
		// Closed drawer must sit fully off the right edge, not slide into view.
		const rect = await page.locator('#app-navigation').boundingBox()
		expect(rect, 'nav must be measurable').toBeTruthy()
		expect(rect.x, 'closed RTL drawer must start beyond the right edge')
			.toBeGreaterThanOrEqual(390 - 2)
		// Toggle still opens it into view.
		await page.click('#wc-nav-toggle')
		await page.waitForTimeout(300)
		const open = await page.locator('#app-navigation').boundingBox()
		expect(open.x + open.width, 'open RTL drawer must be in view').toBeLessThanOrEqual(390)
	})
})

test.describe('contrast modes (emulated media)', () => {
	test('forced-colors: panels regain borders, controls operable, axe clean', async ({ page }) => {
		await page.emulateMedia({ forcedColors: 'active' })
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		expect(await page.evaluate(() => matchMedia('(forced-colors: active)').matches)).toBe(true)

		// The hardening block restores real borders where backgrounds were
		// stripped — a borderless panel here means the block never engaged.
		// (.wc-honesty matches a hidden post-submit panel first — the form's
		// field input is the always-visible target.)
		const fieldBorder = await page.locator('.wc-field input').first()
			.evaluate((el) => getComputedStyle(el).borderTopWidth)
		expect(parseFloat(fieldBorder), 'forced-colors border on input').toBeGreaterThan(0)
		await expect(page.locator('.wc-btn-primary').first()).toBeVisible()

		// The OS owns the palette under forced-colors — color-contrast is
		// not axe-checkable there; every other rule still applies.
		const axe = await new AxeBuilder({ page })
			.withTags(AXE_TAGS).disableRules(['color-contrast']).analyze()
		expect(axe.violations, `FC axe: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
	})

	test('prefers-contrast more: axe clean on report + limited pages', async ({ page }) => {
		await page.emulateMedia({ contrast: 'more' })
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		expect(await page.evaluate(() => matchMedia('(prefers-contrast: more)').matches)).toBe(true)
		const axe = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze()
		expect(axe.violations, `contrast-more axe: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
	})
})
