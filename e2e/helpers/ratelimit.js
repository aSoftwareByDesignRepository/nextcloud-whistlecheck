// @ts-check
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const composeDir = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')

/**
 * Wipe all rate-limit bucket rows so a spec starts from a fresh bucket.
 *
 * Buckets are anonymous HMAC(ip‖kind‖window, rotating salt) rows — they carry
 * no personal data — so deleting them between specs is safe. This makes the
 * 60s/1h windows deterministic in tests without weakening production limits
 * or sleeping through hour-long windows.
 *
 * Requires the dev stack from nextcloud/docker-compose.yml (service
 * `mariadb`). Skips silently if the stack is not up so the helper is a no-op
 * in environments that provide their own isolation.
 */
export function resetRateBuckets() {
	try {
		execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb',
			'mariadb', '-unextcloud', '-pnextcloud_password', 'nextcloud',
			'-e', 'DELETE FROM oc_wchk_rate',
		], { cwd: composeDir, stdio: 'pipe', timeout: 30000 })
	} catch (e) {
		// Stack not reachable — leave buckets alone; specs depending on a
		// fresh bucket will fail loudly rather than silently misfire.
	}
}
