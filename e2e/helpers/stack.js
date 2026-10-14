// @ts-check
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const composeDir = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')

/**
 * Stack-aware execution: the same specs prove the app on MariaDB (default
 * dev stack, :8081) and PostgreSQL (GA-6 stack, :8083 — WC_STACK=pg).
 *
 * - mariadb: `docker compose exec -T <svc>` from nextcloud/ + mariadb CLI
 * - pg:      `docker exec` on the named containers + psql
 */
const PG = process.env.WC_STACK === 'pg'
const PG_NC = 'whistlecheck-app-pg'
const PG_DB = 'nextcloud-whistlecheck-postgres'

/**
 * Run `php occ <args>` as www-data on the active stack.
 * @param {string[]} args occ arguments
 * @param {Record<string,string>} [env] extra env vars for the process
 */
export function occ(args, env = {}) {
	if (PG) {
		const envFlags = Object.entries(env).flatMap(([k, v]) => ['-e', `${k}=${v}`])
		return execFileSync('docker', ['exec', ...envFlags, '-u', 'www-data', PG_NC, 'php', 'occ', ...args], {
			stdio: 'pipe', timeout: 60000, encoding: 'utf8',
		})
	}
	const envFlags = Object.entries(env).flatMap(([k, v]) => ['-e', `${k}=${v}`])
	return execFileSync('docker', ['compose', 'exec', '-T', ...envFlags, '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args], {
		cwd: composeDir, stdio: 'pipe', timeout: 60000, encoding: 'utf8',
	})
}

/**
 * Run `php -r <code>` inside the Nextcloud container (for sodium fixtures).
 * Returns trimmed stdout.
 */
export function ncPhp(code) {
	if (PG) {
		return execFileSync('docker', ['exec', '-u', 'www-data', PG_NC, 'php', '-r', code], {
			stdio: 'pipe', timeout: 60000, encoding: 'utf8',
		}).trim()
	}
	return execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', '-r', code], {
		cwd: composeDir, stdio: 'pipe', timeout: 60000, encoding: 'utf8',
	}).trim()
}

/** Run SQL against the active stack's database. Returns trimmed stdout. */
export function queryDb(sql) {
	if (PG) {
		return execFileSync('docker', ['exec', PG_DB,
			'psql', '-U', 'nextcloud', '-d', 'nextcloud', '-t', '-A', '-c', sql], {
			stdio: 'pipe', timeout: 30000, encoding: 'utf8',
		}).trim()
	}
	return execFileSync('docker', ['compose', 'exec', '-T', 'mariadb',
		'mariadb', '-N', '-unextcloud', '-pnextcloud_password', 'nextcloud', '-e', sql], {
		cwd: composeDir, stdio: 'pipe', timeout: 30000, encoding: 'utf8',
	}).trim()
}

/** Best-effort queryDb that swallows a missing/unreachable stack. */
export function queryDbSafe(sql) {
	try {
		return queryDb(sql)
	} catch {
		return ''
	}
}
