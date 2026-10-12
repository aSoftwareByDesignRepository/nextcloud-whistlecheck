// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { resetRateBuckets } from './helpers/ratelimit.js'

/**
 * Rate-limit UX gate — MUST run last in the suite: it deliberately trips the
 * 'params' bucket (10 req/min per IP, shared across public pages).
 *
 * Buckets are wiped in beforeAll so the trip is deterministic (fresh bucket:
 * the 11th hit within the window must be limited). A limited reporter must
 * see a friendly localized page with a real 429 status and Retry-After —
 * never the generic 500 "Interner Serverfehler" dead-end.
 */
test.describe('rate-limited public page', () => {
	test.beforeAll(() => resetRateBuckets())

	test('renders friendly 429 with Retry-After, not a 500', async ({ page }) => {
		let res = null
		for (let i = 0; i < 14; i++) {
			res = await page.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
			if (res && res.status() === 429) {
				break
			}
		}
		expect(res).not.toBeNull()
		expect(res.status(), 'params bucket never limited after 14 rapid hits').toBe(429)

		// HTTP contract: Retry-After must reach the client.
		const retryAfter = res.headers()['retry-after']
		expect(Number(retryAfter), 'missing Retry-After header').toBeGreaterThan(0)
		expect(res.headers()['cache-control']).toContain('no-store')

		// Friendly page, not generic server-error chrome. Scope to the app's
		// heading — the guest layout ships its own hidden-visually <h1>.
		await expect(page.locator('.wc-public-header h1')).toBeVisible()
		await expect(page.locator('.wc-honesty')).toBeVisible()
		await expect(page.locator('.wc-honesty')).toContainText(/try again|erneut versuchen/i)
		await expect(page.locator('body')).not.toContainText('Interner Serverfehler')
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		// The limited page itself must stay WCAG clean.
		const results = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(results.violations, `axe limited page: ${JSON.stringify(results.violations.map((v) => v.id))}`).toEqual([])
	})
})
