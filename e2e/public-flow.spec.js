// @ts-check
import { test, expect } from '@playwright/test'
import { assertNotServerUpdater } from './helpers/auth.js'
import { resetRateBuckets } from './helpers/ratelimit.js'

/**
 * Anonymous reporter round-trip — the critical public flow, no login needed.
 * Real browser crypto: submit seals with libsodium, the case code is derived
 * client-side and the follow-up page decrypts the thread.
 */
test.beforeAll(() => resetRateBuckets()) // fresh 'submit' bucket (5/h per IP)

test.describe('public reporter flow', () => {
  test('submit → receive code → open thread → reply', async ({ page }) => {
    test.setTimeout(120000) // argon2id + possible PoW escalation is slow by design
    const bodyText = `E2E report ${Date.now()} — doppelt berechnete Rechnung`

    await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
    await assertNotServerUpdater(page)
    await expect(page.locator('#wc-report-form')).toBeVisible()

    await page.locator('#wc-title').fill('E2E Abrechnungsbetrug')
    await page.locator('#wc-body').fill(bodyText)
    await page.locator('#wc-report-form [type=submit]').click()

    // Code panel becomes visible with a formatted Crockford code.
    const codeEl = page.locator('#wc-code-panel .wc-code-value')
    await expect(codeEl).not.toBeEmpty({ timeout: 90000 })
    const code = (await codeEl.textContent() || '').trim()
    expect(code.replace(/[^0-9A-Z]/gi, '').length).toBeGreaterThanOrEqual(26)

    // Open the thread with the fresh code.
    await page.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
    await page.locator('#wc-code').fill(code)
    await page.locator('#wc-follow-form [type=submit]').click()

    await expect(page.locator('#wc-thread')).toBeVisible({ timeout: 30000 })
    // Decrypted client-side — the plaintext must appear.
    await expect(page.locator('.wc-messages')).toContainText(bodyText)

    // Reply through the same code-derived key.
    await page.locator('#wc-reply-body').fill('E2E follow-up detail')
    await page.locator('#wc-reply-form [type=submit]').click()
    await expect(page.locator('.wc-messages')).toContainText('E2E follow-up detail', { timeout: 45000 })
  })

  test('bogus code yields uniform invalid error, not an oracle', async ({ page }) => {
    await page.goto('/apps/whistlecheck/case', { waitUntil: 'domcontentloaded' })
    await page.locator('#wc-code').fill('AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-A')
    await page.locator('#wc-follow-form [type=submit]').click()
    await expect(page.locator('#wc-form-error')).toBeVisible()
    await expect(page.locator('#wc-thread')).toBeHidden()
  })
})
