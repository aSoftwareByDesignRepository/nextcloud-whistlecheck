// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { assertNotServerUpdater } from './helpers/auth.js'

/**
 * WCAG 2.1 A/AA smoke — public pages need no auth; officer pages run only
 * when the setup project produced an authenticated storage state.
 */
const publicRoutes = [
  '/apps/whistlecheck/report',
  '/apps/whistlecheck/case',
]

const officerRoutes = [
  '/apps/whistlecheck/',
  '/apps/whistlecheck/settings',
  '/apps/whistlecheck/settings/access',
  '/apps/whistlecheck/officers',
  '/apps/whistlecheck/audit',
  '/apps/whistlecheck/stats',
  '/apps/whistlecheck/verbal',
]

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
async function axeCheck(page, path) {
  await page.goto(path, { waitUntil: 'domcontentloaded' })
  await assertNotServerUpdater(page)
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze()
  expect(results.violations, `axe violations on ${path}: ${JSON.stringify(results.violations.map(v => v.id))}`).toEqual([])
}

for (const route of publicRoutes) {
  test(`public a11y ${route}`, async ({ page }) => {
    await axeCheck(page, route)
  })
}

for (const route of officerRoutes) {
  test(`officer a11y ${route}`, async ({ page }) => {
    await page.goto(route, { waitUntil: 'domcontentloaded' })
    if (page.url().includes('/login')) {
      test.skip(true, 'requires officer storage state (auth.setup)')
      return
    }
    await axeCheck(page, route)
  })
}

test('public pages set strict CSP and no-store', async ({ page }) => {
  const res = await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
  expect(res).not.toBeNull()
  const headers = res.headers()
  expect(headers['cache-control'] || '').toContain('no-store')
  const csp = headers['content-security-policy'] || ''
  expect(csp).toBeTruthy()
  // No script execution beyond nonce'd self + the WASM grant libsodium needs.
  expect(csp).not.toContain("script-src 'unsafe-inline'")
  expect(csp).not.toContain("'unsafe-eval'")
  expect(csp).toContain("'wasm-unsafe-eval'")
  expect(csp).toContain("connect-src 'self'")
  expect(csp).toContain("default-src 'none'")
})
