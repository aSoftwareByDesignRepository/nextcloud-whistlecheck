// @ts-check
import { occ, queryDb, queryDbSafe, ncPhp } from './stack.js'

const GROUP = 'whistlecheck_officers'
const UID = 'wcfix'

/**
 * Ensure the stack has at least one ACTIVE officer with a real X25519
 * pubkey — the reporter target of `bootstrap`'s `officers` list.
 *
 * On a fresh stack (GA-6 PG proof) no officer has ever run the key
 * ceremony, so `activePubkeys()` is empty and the public submit pipeline
 * has nobody to encrypt to — the send button stays enabled forever.
 * This seeds a synthetic-but-real keypair exactly like makeCase seeds
 * synthetic wraps: fixture realism, not a product code path.
 */
export function ensureReporterTarget() {
	const count = Number(queryDbSafe(`SELECT COUNT(*) FROM oc_wchk_officers WHERE status='active' AND pubkey <> ''`) || '0')
	if (count > 0) {
		return
	}
	try {
		occ(['user:add', '--password-from-env', UID], { OC_PASS: 'WcFix-' + Date.now() })
	} catch { /* user may already exist */ }
	occ(['group:adduser', GROUP, UID])
	const [pubkey, fingerprint] = ncPhp(
		'$kp = sodium_crypto_box_keypair(); $pk = sodium_crypto_box_publickey($kp); echo base64_encode($pk), "\\n", hash("sha256", $pk);',
	).split('\n')
	try {
		queryDb(`INSERT INTO oc_wchk_officers (uid, pubkey, fingerprint, status, key_version, created_at)
			VALUES ('${UID}', '${pubkey}', '${fingerprint}', 'active', 1, NOW())`)
	} catch { /* uid row may already exist from a partial prior seed */ }
}

/** Remove the synthetic reporter-target fixture. */
export function dropReporterTarget() {
	queryDbSafe(`DELETE FROM oc_wchk_officers WHERE uid='${UID}'`)
	try {
		occ(['user:delete', UID])
	} catch { /* best-effort */ }
}
