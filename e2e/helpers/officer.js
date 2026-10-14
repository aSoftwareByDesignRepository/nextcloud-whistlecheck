// @ts-check
import { randomBytes } from 'node:crypto'
import { occ, queryDbSafe } from './stack.js'

const GROUP = 'whistlecheck_officers'

/**
 * Provision a throwaway officer: fresh NC user + officer-group membership.
 *
 * The journey suite must never share a mutable officer fixture — a second
 * runner (or a leftover CI job) rotating the same uid mid-test seals wraps
 * to a pubkey our localStorage blob cannot open, and the failure looks like
 * a product decryption bug. A unique uid makes each run hermetic.
 *
 * @returns {{ uid: string, password: string }}
 */
export function provisionOfficer() {
	const uid = 'wcj' + randomBytes(5).toString('hex')
	const password = 'WcJourney-' + randomBytes(8).toString('hex')
	occ(['user:add', '--password-from-env', uid], { OC_PASS: password })
	occ(['group:adduser', GROUP, uid])
	return { uid, password }
}

/**
 * Flip the fixture officer's Nextcloud theme (e.g. '["dark"]'). Themes are
 * server-rendered CSS variables — emulation cannot reach them, only the
 * user setting can.
 */
export function setUserTheme(uid, themesJson) {
	occ(['user:setting', uid, 'theming', 'enabled-themes', themesJson])
}

/** Set a core user setting (e.g. lang). */
export function setUserSetting(uid, app, key, value) {
	occ(['user:setting', uid, app, key, value])
}

/** Remove a uid from a group (no error if already absent). */
export function removeFromGroup(group, uid) {
	try {
		occ(['group:removeuser', group, uid])
	} catch { /* group/user may not exist */ }
}

/** Remove the throwaway user and its whistlecheck rows (no leftovers). */
export function deprovisionOfficer(uid) {
	try {
		occ(['user:delete', uid])
	} catch { /* user may already be gone */ }
	try {
		queryDbSafe(`DELETE FROM oc_wchk_officers WHERE uid='${uid}'; DELETE FROM oc_wchk_case_keys WHERE officer_uid='${uid}';`)
	} catch { /* best-effort cleanup */ }
}
