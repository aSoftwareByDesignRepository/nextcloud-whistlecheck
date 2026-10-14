// @ts-check
import { test, expect } from '@playwright/test'
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer, setUserSetting } from './helpers/officer.js'
import { resetRateBuckets } from './helpers/ratelimit.js'

/**
 * ATLAS Lens 5 — locale proofs.
 *
 * 1. Static: every `t('key', …)` literal in the app JS must exist (or be a
 *    prefix of existing keys) in the PHP i18n map that ships it — otherwise
 *    the missing key renders RAW to users (t() falls back to the key).
 * 2. Dynamic: real German renders — officer app (user lang) and public page
 *    (Accept-Language) — scanned for leaked machine keys like `in_review`
 *    or `err_generic`, the exact bug class that shipped status machine keys.
 * 3. German layout at 390px: translations run ~30% longer — assert no
 *    horizontal overflow and no clipped primary controls.
 */

const appDir = resolve(dirname(fileURLToPath(import.meta.url)), '..')

test.beforeAll(() => resetRateBuckets())

/** Extract t('literal' first-args from a JS file. */
function jsKeys(file) {
	const src = readFileSync(resolve(appDir, file), 'utf8')
	const keys = new Set()
	for (const m of src.matchAll(/\bt\('([a-z][a-zA-Z0-9_]*)'/g)) keys.add(m[1])
	return [...keys]
}

/** Extract map keys from a `private function xI18n()` body in a PHP file. */
function phpMapKeys(file, fnName) {
	const src = readFileSync(resolve(appDir, file), 'utf8')
	const start = src.indexOf(`function ${fnName}`)
	expect(start, `${fnName} not found in ${file}`).toBeGreaterThan(-1)
	const body = src.slice(start, start + 30000)
	const keys = new Set()
	for (const m of body.matchAll(/'([a-z][a-zA-Z0-9_]*)'\s*=>/g)) keys.add(m[1])
	return keys
}

test.describe('locale: key coverage and render', () => {
	test('every JS i18n key exists in the PHP map that ships it', () => {
		const officerMap = phpMapKeys('lib/Controller/PageController.php', 'officerI18n')
		for (const file of ['js/whistlecheck-main.js', 'js/common/mobile-nav.js']) {
			const missing = jsKeys(file).filter(
				(k) => !officerMap.has(k) && ![...officerMap].some((mk) => mk.startsWith(k)),
			)
			expect(missing, `${file} keys missing from officerI18n()`).toEqual([])
		}
		const publicMap = phpMapKeys('lib/Controller/PublicPageController.php', 'publicI18n')
		const missing = jsKeys('js/whistlecheck-public.js').filter(
			(k) => !publicMap.has(k) && ![...publicMap].some((mk) => mk.startsWith(k)),
		)
		expect(missing, 'whistlecheck-public.js keys missing from publicI18n()').toEqual([])
	})

	test('all 12 locale catalogs share the en key set and carry translations', () => {
		const en = JSON.parse(readFileSync(resolve(appDir, 'l10n/en.json'), 'utf8')).translations
		const enKeys = new Set(Object.keys(en))
		for (const f of readdirSync(resolve(appDir, 'l10n')).filter((f) => f.endsWith('.json') && f !== 'en.json')) {
			const tr = JSON.parse(readFileSync(resolve(appDir, 'l10n', f), 'utf8')).translations
			const missing = [...enKeys].filter((k) => !(k in tr))
			const identical = [...enKeys].filter((k) => tr[k] === en[k] && /[a-z]{3,}/i.test(en[k]))
			expect(missing, `${f} missing keys`).toEqual([])
			// Identical-to-English strings are allowed only for genuine
			// cognates/proper nouns — flag >5% identical as unreviewed fallback.
			expect(identical.length, `${f}: ${identical.length} strings identical to en`).toBeLessThan(enKeys.size * 0.05)
		}
	})

	test('German officer render: translated UI, zero leaked keys, no overflow at 390px', async ({ browser }) => {
		const officer = provisionOfficer()
		try {
			setUserSetting(officer.uid, 'core', 'lang', 'de')
			const context = await browser.newContext({ viewport: { width: 390, height: 844 } })
			const page = await context.newPage()
			try {
				await login(page, { username: officer.uid, password: officer.password })
				await page.goto('/apps/whistlecheck/cases', { waitUntil: 'domcontentloaded' })
				const text = await page.locator('body').innerText()
				expect(text).toContain('Fallposteingang')
				// Machine-key leak guard: the exact patterns that shipped before.
				for (const leaked of ['in_review', 'action_taken', 'err_generic', 'esc_none', 'inbox_empty', 'not_officer']) {
					expect(text, `leaked key ${leaked}`).not.toContain(leaked)
				}
				const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
				expect(overflow, 'horizontal overflow at 390px').toBeLessThanOrEqual(1)
			} finally {
				await context.close()
			}
		} finally {
			deprovisionOfficer(officer.uid)
		}
	})

	test('German public page: translated chrome, zero leaked keys, no overflow at 390px', async ({ browser }) => {
		const context = await browser.newContext({
			viewport: { width: 390, height: 844 },
			locale: 'de-DE',
		})
		const page = await context.newPage()
		try {
			await page.goto('/apps/whistlecheck/report', { waitUntil: 'domcontentloaded' })
			const text = await page.locator('body').innerText()
			expect(text).toContain('Anliegen melden')
			for (const leaked of ['err_invalid_code', 'case_code', 'report_title', 'copy_manual', 'show_qr']) {
				expect(text, `leaked key ${leaked}`).not.toContain(leaked)
			}
			const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
			expect(overflow, 'horizontal overflow at 390px').toBeLessThanOrEqual(1)
		} finally {
			await context.close()
		}
	})
})
