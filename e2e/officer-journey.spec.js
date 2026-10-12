// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, assertNotServerUpdater } from './helpers/auth.js'

/**
 * Officer journey coverage — guards the class of bug that silently killed
 * the whole app (skip-link inside data-wc-i18n swallowed every data attr,
 * leaving wcPage undefined and all officer JS dead while axe stayed green).
 *
 * wchb is a dedicated fixture officer: pending row, fresh key, known
 * passphrase. The journey is the real full loop:
 *   set key (inline panel) → public submit wraps to it → inbox → case →
 *   unlock panel (wrong pass → inline error → right pass → content).
 */

const WCHB = { username: 'wchb', password: 'WchbJourney-8k42x' }
const WCHB_PASS = 'journey-passphrase-9x'

test.describe('officer pages: boot integrity (U1 regression)', () => {
	const pages = [
		['/apps/whistlecheck/', 'inbox'],
		['/apps/whistlecheck/officers', 'officers'],
		['/apps/whistlecheck/audit', 'audit'],
		['/apps/whistlecheck/stats', 'stats'],
		['/apps/whistlecheck/verbal', 'verbal'],
		['/apps/whistlecheck/settings', 'settings'],
	]

	for (const [path, expected] of pages) {
		test(`${path} boots with intact data attrs + nav`, async ({ page }) => {
			await page.goto(path, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)

			const root = page.locator('#app-content')
			await expect(root).toHaveAttribute('data-wc-page', expected === 'settings' ? 'settings' : expected)

			// i18n must be parseable JSON (the bug truncated it mid-attribute).
			const parsed = await root.evaluate((el) => {
				try { return JSON.parse(el.dataset.wcI18n || '{}') } catch { return null }
			})
			expect(parsed).not.toBeNull()

			// Attribute junk must never leak as visible text.
			await expect(page.locator('body')).not.toContainText('data-wc-page=')

			// Skip link is a real element and focusable.
			const skip = page.locator('.wc-skip-link')
			await expect(skip).toHaveCount(1)

			// Shared nav on every page, current page marked.
			const nav = page.locator('.wc-nav').first()
			await expect(nav).toBeVisible()
			expect(await nav.locator('a').count()).toBeGreaterThanOrEqual(6)
			await expect(nav.locator('[aria-current="page"]')).toHaveCount(1)
		})
	}
})

test.describe('officer journey: inbox + case', () => {
	test('inbox renders rows; row opens case via Enter key', async ({ page }) => {
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await assertNotServerUpdater(page)
		const rows = page.locator('[data-wc-inbox] tr')
		await expect(rows.first()).toBeVisible({ timeout: 20000 })
		// not still stuck on the loading row
		await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })

		const first = page.locator('[data-wc-inbox] tr[data-case-id]').first()
		const count = await first.count()
		if (count === 0) return // empty inbox is a valid state — rows optional
		// The title cell carries a real anchor — the keyboard path.
		await first.locator('a.wc-case-link').focus()
		await page.keyboard.press('Enter')
		await expect(page).toHaveURL(/\/cases\/\d+/)
	})

	test('nav links traverse every section (no dead ends)', async ({ page }) => {
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		for (const href of ['/officers', '/audit', '/stats', '/verbal', '/settings']) {
			await page.locator(`.wc-nav a[href$="${href}"]`).first().click()
			await expect(page.locator('#app-content')).toHaveAttribute('data-wc-page')
			await expect(page.locator('.wc-nav').first()).toBeVisible()
			await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		}
	})
})

test.describe('wchb full journey', () => {
	test('key setup → public submit → unlock → read case', async ({ browser, baseURL }) => {
		test.setTimeout(240000)
		// Fresh context — the project storageState is mcauditor's session;
		// wchb must log in as itself.
		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		await login(page, WCHB)
		await assertNotServerUpdater(page)

		// ── 1. Key setup through the inline panel (no modal prompts) ──
		await page.goto('/apps/whistlecheck/officers', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-wc-keyreg]')).toBeVisible()
		await page.locator('[data-wc-newpass]').fill(WCHB_PASS)
		await page.locator('[data-wc-newpass2]').fill(WCHB_PASS)
		await page.locator('[data-wc-genkey]').click()
		// Page reloads after successful registration; row shows active.
		await expect(page.locator('tbody[data-wc-officers]')).toContainText('wchb', { timeout: 45000 })

		// ── 2. Anonymous report wraps to the now-active wchb ──
		const pub = await page.context().newPage()
		const bodyText = `journey body ${Date.now()}`
		await pub.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await pub.locator('#wc-body').fill(bodyText)
		await pub.locator('#wc-report-form [type=submit]').click()
		const codeEl = pub.locator('#wc-code-panel .wc-code-value')
		await expect(codeEl).not.toBeEmpty({ timeout: 90000 })
		await pub.close()

		// ── 3. Inbox shows the case; open it ──
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		const row = page.locator('[data-wc-inbox] tr[data-case-id]').first()
		await expect(row).toBeVisible({ timeout: 20000 })
		await row.click()
		await expect(page).toHaveURL(/\/cases\/\d+/)

		// ── 4. Unlock panel: wrong pass → inline error, right pass → content ──
		await page.evaluate(() => sessionStorage.removeItem('wc_pass'))
		await page.reload({ waitUntil: 'domcontentloaded' })
		const panel = page.locator('[data-wc-unlock]')
		await expect(panel).toBeVisible({ timeout: 15000 })
		await page.locator('[data-wc-unlock-pass]').fill('wrong-passphrase')
		await page.locator('[data-wc-unlock-go]').click()
		await expect(page.locator('[data-wc-unlock-err]')).toBeVisible({ timeout: 15000 })

		await page.locator('[data-wc-unlock-pass]').fill(WCHB_PASS)
		await page.locator('[data-wc-unlock-go]').click()
		await expect(panel).toBeHidden({ timeout: 15000 })
		await expect(page.locator('[data-wc-messages]')).toContainText(bodyText, { timeout: 30000 })

		// ── 5. Axe on the fully decrypted case page ──
		const axe = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(axe.violations, `axe on case page: ${JSON.stringify(axe.violations.map((v) => v.id))}`).toEqual([])
		await context.close()
	})
})

test.describe('responsive: no horizontal overflow at phone width', () => {
	const mobile = { width: 390, height: 844 }

	test('public report form fits 390px', async ({ page }) => {
		await page.setViewportSize(mobile)
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#wc-body')).toBeVisible()
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth
		)
		expect(overflow).toBeLessThanOrEqual(1)
	})

	test('officer inbox renders as cards at 390px', async ({ page }) => {
		await page.setViewportSize(mobile)
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.wc-loading')).toBeHidden({ timeout: 20000 })
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth
		)
		expect(overflow).toBeLessThanOrEqual(1)
		// Card layout active: thead hidden, labeled cells stacked
		const theadHidden = await page.locator('.wc-table-cards thead').isHidden()
		expect(theadHidden).toBe(true)
	})
})

test.describe('public surface: forgiving inputs', () => {
	test('case-code input auto-formats into dash groups', async ({ page }) => {
		await page.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
		const input = page.locator('#wc-code')
		await input.fill('abcde fghij-klmno_12345')
		await expect(input).toHaveValue('ABCDE-FGHIJ-KLMNO-12345')
	})

	test('code panel exposes copy + follow link (no dead end)', async ({ page }) => {
		test.setTimeout(120000)
		await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
		await page.locator('#wc-body').fill(`ux journey ${Date.now()}`)
		await page.locator('#wc-report-form [type=submit]').click()
		const panel = page.locator('#wc-code-panel')
		await expect(panel).toBeVisible({ timeout: 90000 })
		await expect(panel.locator('[data-wc-copy-code]')).toBeVisible()
		const follow = panel.locator('[data-wc-goto-follow]')
		await expect(follow).toBeVisible()
		await follow.click()
		await expect(page.locator('#wc-follow-form')).toBeVisible()
	})
})
