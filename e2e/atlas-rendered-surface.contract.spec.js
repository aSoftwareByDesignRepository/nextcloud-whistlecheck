// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — rendered truth of each WhistleCheck
 * surface: list markers not reset by shell CSS, selects vertically centred,
 * icons non-zero, controls not center-aligned by inherited shell styles.
 * Officer pages are multi-route (not a hash SPA) — each route gets a pass;
 * public guest surfaces (/report, /case) render without a session.
 */
import { test } from '@playwright/test'
import shared from '../../_shared/e2e/atlas-rendered-surface-contract.js'
import { login, assertNotServerUpdater } from './helpers/auth.js'
import { provisionOfficer, deprovisionOfficer } from './helpers/officer.js'

const { assertAtlasRenderedSurface } = shared

/**
 * Intentionally marker-less lists: breadcrumb row, chat/message streams and
 * grant/escalation chip rows ship explicit list-style:none row chrome.
 * Content lists are NOT allow-listed.
 */
const LIST_ALLOW = [
	'.wc-breadcrumb',
	'.wc-messages',
	'.wc-notes',
	'.wc-grant-list',
	'.wc-esc-list',
	'[data-wc-attachments]',
].join(', ')

const OFFICER_SURFACES = [
	['/', 'inbox'],
	['/officers', 'officers'],
	['/audit', 'audit'],
	['/stats', 'stats'],
	['/verbal', 'verbal'],
	['/settings', 'settings index'],
	['/settings/access', 'settings access'],
	['/settings/channel', 'settings channel'],
	['/settings/categories', 'settings categories'],
	['/settings/attachments', 'settings attachments'],
	['/settings/retention', 'settings retention'],
	['/settings/verbal', 'settings verbal'],
	['/settings/audit', 'settings audit'],
	['/settings/support', 'settings support'],
]

const PUBLIC_SURFACES = [
	['/report', 'report form'],
	['/case', 'case follow-up'],
]

let officer
test.beforeAll(() => {
	officer = provisionOfficer()
})
test.afterAll(() => {
	deprovisionOfficer(officer?.uid)
})

test.describe('Rendered-surface contract (officer)', () => {
	for (const [path, name] of OFFICER_SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${path})`, async ({ page }) => {
			await login(page, { username: officer.uid, password: officer.password })
			await page.setViewportSize({ width: 1280, height: 800 })
			await page.goto(`/apps/whistlecheck${path}`, { waitUntil: 'domcontentloaded' })
			await assertNotServerUpdater(page)
			await assertAtlasRenderedSurface(page, {
				content: '#app-content.wc-app',
				navExclude: '#app-navigation, nav',
				listAllow: LIST_ALLOW,
			})
		})
	}
})

test.describe('Rendered-surface contract (public)', () => {
	for (const [path, name] of PUBLIC_SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT public ${name} (${path})`, async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 800 })
			await page.goto(`/apps/whistlecheck${path}`, { waitUntil: 'domcontentloaded' })
			await assertAtlasRenderedSurface(page, {
				content: '#content.wc-public',
				navExclude: 'nav',
				listAllow: LIST_ALLOW,
			})
		})
	}
})
