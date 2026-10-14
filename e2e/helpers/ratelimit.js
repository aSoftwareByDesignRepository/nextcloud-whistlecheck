// @ts-check
import { queryDbSafe } from './stack.js'

/**
 * Wipe all rate-limit bucket rows so a spec starts from a fresh bucket.
 *
 * Buckets are anonymous HMAC(ip‖kind‖window, rotating salt) rows — they carry
 * no personal data — so deleting them between specs is safe. This makes the
 * 60s/1h windows deterministic in tests without weakening production limits
 * or sleeping through hour-long windows.
 *
 * Works on both stacks (MariaDB dev / PostgreSQL GA-6) via stack.js.
 * Skips silently if the stack is not up so the helper is a no-op
 * in environments that provide their own isolation.
 */
export function resetRateBuckets() {
	queryDbSafe('DELETE FROM oc_wchk_rate')
}
