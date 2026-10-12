// @ts-check
import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const composeDir = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')
const GROUP = 'whistlecheck_officers'

function docker(args) {
	execFileSync('docker', ['compose', 'exec', '-T', ...args], {
		cwd: composeDir, stdio: 'pipe', timeout: 60000,
	})
}

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
	docker(['-e', `OC_PASS=${password}`, '-u', 'www-data', 'nextcloud',
		'php', 'occ', 'user:add', '--password-from-env', uid])
	docker(['-u', 'www-data', 'nextcloud',
		'php', 'occ', 'group:adduser', GROUP, uid])
	return { uid, password }
}

/**
 * Flip the fixture officer's Nextcloud theme (e.g. '["dark"]'). Themes are
 * server-rendered CSS variables — emulation cannot reach them, only the
 * user setting can.
 */
export function setUserTheme(uid, themesJson) {
	docker(['-u', 'www-data', 'nextcloud', 'php', 'occ',
		'user:setting', uid, 'theming', 'enabled-themes', themesJson])
}

/** Remove the throwaway user and its whistlecheck rows (no leftovers). */
export function deprovisionOfficer(uid) {
	try {
		docker(['-u', 'www-data', 'nextcloud', 'php', 'occ', 'user:delete', uid])
	} catch { /* user may already be gone */ }
	try {
		docker(['mariadb', 'mariadb', '-unextcloud', '-pnextcloud_password', 'nextcloud',
			'-e', `DELETE FROM oc_wchk_officers WHERE uid='${uid}'; DELETE FROM oc_wchk_case_keys WHERE officer_uid='${uid}';`])
	} catch { /* best-effort cleanup */ }
}
