// @ts-check
import { existsSync, mkdirSync, readFileSync, statSync } from 'fs'
import { dirname } from 'path'
import { test as setup } from '@playwright/test'
import { assertNotServerUpdater, loginWithFallback, plannerCredsCandidates } from './helpers/auth.js'

const authFile = 'e2e/.auth/officer.json'

setup('authenticate officer', async ({ page }) => {
	const candidates = plannerCredsCandidates()
	setup.skip(candidates.length === 0, 'Requires E2E_* or NC_ADMIN_* or NC_OFFICER_* credentials')
	mkdirSync(dirname(authFile), { recursive: true })

	if (existsSync(authFile) && (Date.now() - statSync(authFile).mtimeMs) < 6 * 60 * 60 * 1000) {
		try {
			const state = JSON.parse(readFileSync(authFile, 'utf8'))
			if (Array.isArray(state.cookies) && state.cookies.length > 0) {
				await page.context().addCookies(state.cookies)
			}
		} catch {
			// fall through to fresh login
		}
		await page.goto('/apps/whistlecheck/', { waitUntil: 'domcontentloaded' })
		if (await page.locator('#app-content .wc-app-wrap').isVisible().catch(() => false)) {
			await assertNotServerUpdater(page)
			await page.context().storageState({ path: authFile })
			return
		}
	}

	await loginWithFallback(page, candidates)
	await page.context().storageState({ path: authFile })
})
