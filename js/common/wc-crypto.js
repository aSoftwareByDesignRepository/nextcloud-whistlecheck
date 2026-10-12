/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * WhistleCheck client crypto — mirrors lib/Crypto/*.php exactly.
 *
 * Wire format (shared with the server):
 *   secretbox : {"v":1,"alg":"xs20p1305","n":b64,"c":b64}
 *   chunked   : {"v":1,"alg":"xs20p1305-chunk","n":b64,"chunks":[b64,...]}
 *   seal      : {"v":1,"alg":"x25519-seal","c":b64}
 *
 * Derivation domains:
 *   hint      = sha256('wchk-hint-v1|' + codeBytes)
 *   verifier  = argon2id('wchk-ver-v1|' + codeBytes, saltV)
 *   K_rep     = argon2id('wchk-key-v1|' + codeBytes, saltK)
 */
(function () {
	'use strict';

	const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	const CODE_LEN = 26;
	const CHUNK = 4 * 1024 * 1024;

	let _sodium = null;
	async function sodium() {
		if (_sodium) return _sodium;
		if (!window.sodium || !window.sodium.ready) {
			throw new Error('libsodium not loaded');
		}
		await window.sodium.ready;
		_sodium = window.sodium;
		return _sodium;
	}

	const b64 = (bytes) => sodium().then((s) => s.to_base64(bytes, s.base64_variants.ORIGINAL));
	const b64Sync = (s, bytes) => s.to_base64(bytes, s.base64_variants.ORIGINAL);
	const fromB64 = (s, str) => s.from_base64(str, s.base64_variants.ORIGINAL);

	function utf8(str) { return new TextEncoder().encode(str); }

	// ── case code ────────────────────────────────────────────────────────

	function encode(bytes) {
		// 16 bytes → 26 Crockford chars (pad to 130 bits, consume 5-bit groups).
		let bits = '';
		bytes.forEach((b) => { bits += b.toString(2).padStart(8, '0'); });
		bits = bits.padStart(CODE_LEN * 5, '0');
		let out = '';
		for (let i = 0; i < CODE_LEN; i++) {
			out += CROCKFORD[parseInt(bits.slice(i * 5, i * 5 + 5), 2)];
		}
		return out;
	}

	function decode(canonical) {
		const clean = String(canonical).replace(/[^A-Za-z0-9]/g, '').toUpperCase()
			.replace(/O/g, '0').replace(/[IL]/g, '1');
		if (clean.length !== CODE_LEN) return null;
		let bits = '';
		for (const ch of clean) {
			const pos = CROCKFORD.indexOf(ch);
			if (pos < 0) return null;
			bits += pos.toString(2).padStart(5, '0');
		}
		bits = bits.slice(-128);
		const out = new Uint8Array(16);
		for (let i = 0; i < 16; i++) {
			out[i] = parseInt(bits.slice(i * 8, i * 8 + 8), 2);
		}
		return out;
	}

	function format(canonical) {
		return canonical.replace(/(.{5})(?=.)/g, '$1-');
	}

	// ── derivation ───────────────────────────────────────────────────────

	async function derive(codeBytes, saltHex, domain, outLen) {
		const s = await sodium();
		const salt = s.from_hex(saltHex);
		const input = s.from_string(domain + '|');
		const pass = new Uint8Array(input.length + codeBytes.length);
		pass.set(input); pass.set(codeBytes, input.length);
		return s.crypto_pwhash(outLen, pass, salt,
			s.crypto_pwhash_OPSLIMIT_INTERACTIVE,
			s.crypto_pwhash_MEMLIMIT_INTERACTIVE,
			s.crypto_pwhash_ALG_ARGON2ID13);
	}

	async function hint(codeBytes) {
		const s = await sodium();
		const pre = s.from_string('wchk-hint-v1|');
		const msg = new Uint8Array(pre.length + codeBytes.length);
		msg.set(pre); msg.set(codeBytes, pre.length);
		return s.to_hex(s.crypto_hash_sha256(msg));
	}

	async function verifier(codeBytes, saltHex) {
		const raw = await derive(codeBytes, saltHex, 'wchk-ver-v1', 32);
		const s = await sodium();
		return s.to_hex(raw);
	}

	async function reporterKey(codeBytes, keySaltHex) {
		return derive(codeBytes, keySaltHex, 'wchk-key-v1', 32);
	}

	// ── envelopes ────────────────────────────────────────────────────────

	async function seal(plaintext, key) {
		const s = await sodium();
		const nonce = s.randombytes_buf(s.crypto_secretbox_NONCEBYTES);
		const ct = s.crypto_secretbox_easy(utf8(plaintext), nonce, key);
		return JSON.stringify({ v: 1, alg: 'xs20p1305', n: b64Sync(s, nonce), c: b64Sync(s, ct) });
	}

	async function sealBytes(data, key) {
		const s = await sodium();
		const nonce = s.randombytes_buf(s.crypto_secretbox_NONCEBYTES);
		const ct = s.crypto_secretbox_easy(data, nonce, key);
		return JSON.stringify({ v: 1, alg: 'xs20p1305', n: b64Sync(s, nonce), c: b64Sync(s, ct) });
	}

	async function open(envelopeJson, key) {
		const s = await sodium();
		try {
			const env = JSON.parse(envelopeJson);
			if (env.alg === 'xs20p1305') {
				const pt = s.crypto_secretbox_open_easy(fromB64(s, env.c), fromB64(s, env.n), key);
				return new TextDecoder().decode(pt);
			}
			if (env.alg === 'xs20p1305-chunk') {
				const base = fromB64(s, env.n);
				const parts = env.chunks.map((b64c, i) => {
					const nonce = nonceAt(s, base, i);
					return s.crypto_secretbox_open_easy(fromB64(s, b64c), nonce, key);
				});
				const total = parts.reduce((n, p) => n + p.length, 0);
				const out = new Uint8Array(total);
				let off = 0;
				parts.forEach((p) => { out.set(p, off); off += p.length; });
				return new TextDecoder().decode(out);
			}
			return null;
		} catch (e) {
			return null;
		}
	}

	async function openBytes(envelopeJson, key) {
		const s = await sodium();
		try {
			const env = JSON.parse(envelopeJson);
			if (env.alg === 'xs20p1305') {
				return s.crypto_secretbox_open_easy(fromB64(s, env.c), fromB64(s, env.n), key);
			}
			if (env.alg === 'xs20p1305-chunk') {
				const base = fromB64(s, env.n);
				const parts = env.chunks.map((b64c, i) =>
					s.crypto_secretbox_open_easy(fromB64(s, b64c), nonceAt(s, base, i), key));
				const total = parts.reduce((n, p) => n + p.length, 0);
				const out = new Uint8Array(total);
				let off = 0;
				parts.forEach((p) => { out.set(p, off); off += p.length; });
				return out;
			}
			return null;
		} catch (e) {
			return null;
		}
	}

	async function sealChunked(data, key) {
		const s = await sodium();
		const base = s.randombytes_buf(s.crypto_secretbox_NONCEBYTES);
		const chunks = [];
		let i = 0;
		do {
			const part = data.slice(i * CHUNK, i * CHUNK + CHUNK);
			chunks.push(b64Sync(s, s.crypto_secretbox_easy(part, nonceAt(s, base, i), key)));
			i++;
		} while (i * CHUNK < data.length);
		return JSON.stringify({ v: 1, alg: 'xs20p1305-chunk', n: b64Sync(s, base), chunks });
	}

	async function sealToPubkey(data, pubkeyB64) {
		const s = await sodium();
		const pk = fromB64(s, pubkeyB64);
		const ct = s.crypto_box_seal(typeof data === 'string' ? utf8(data) : data, pk);
		return JSON.stringify({ v: 1, alg: 'x25519-seal', c: b64Sync(s, ct) });
	}

	async function openSealed(envelopeJson, pubkey, privkey) {
		const s = await sodium();
		try {
			const env = JSON.parse(envelopeJson);
			if (env.alg !== 'x25519-seal') return null;
			return s.crypto_box_seal_open(fromB64(s, env.c), pubkey, privkey);
		} catch (e) {
			return null;
		}
	}

	function nonceAt(s, base, i) {
		const n = new Uint8Array(base);
		let carry = i;
		for (let p = n.length - 1; p >= 0 && carry > 0; p--) {
			const sum = n[p] + (carry & 0xff);
			n[p] = sum & 0xff;
			carry = Math.floor(carry / 256) + (sum >> 8);
		}
		return n;
	}

	// ── officer key lifecycle ────────────────────────────────────────────

	async function officerKeypair() {
		const s = await sodium();
		const kp = s.crypto_box_keypair();
		return { pubkey: b64Sync(s, kp.publicKey), privkey: kp.privateKey, pubkeyRaw: kp.publicKey };
	}

	/** Wrap a private key under argon2id(passphrase) — stored client-side. */
	async function wrapPrivateKey(privkey, passphrase) {
		const s = await sodium();
		const salt = s.randombytes_buf(s.crypto_pwhash_SALTBYTES);
		const key = s.crypto_pwhash(32, utf8(passphrase), salt,
			s.crypto_pwhash_OPSLIMIT_INTERACTIVE,
			s.crypto_pwhash_MEMLIMIT_INTERACTIVE,
			s.crypto_pwhash_ALG_ARGON2ID13);
		const nonce = s.randombytes_buf(s.crypto_secretbox_NONCEBYTES);
		const ct = s.crypto_secretbox_easy(privkey, nonce, key);
		return JSON.stringify({ v: 1, kdf: 'argon2id', salt: s.to_hex(salt), n: b64Sync(s, nonce), c: b64Sync(s, ct) });
	}

	async function unwrapPrivateKey(blobJson, passphrase) {
		const s = await sodium();
		try {
			const blob = JSON.parse(blobJson);
			const key = s.crypto_pwhash(32, utf8(passphrase), s.from_hex(blob.salt),
				s.crypto_pwhash_OPSLIMIT_INTERACTIVE,
				s.crypto_pwhash_MEMLIMIT_INTERACTIVE,
				s.crypto_pwhash_ALG_ARGON2ID13);
			return s.crypto_secretbox_open_easy(fromB64(s, blob.c), fromB64(s, blob.n), key);
		} catch (e) {
			return null;
		}
	}

	async function fingerprint(pubkeyB64) {
		const s = await sodium();
		return s.to_hex(s.crypto_hash_sha256(fromB64(s, pubkeyB64)));
	}

	async function randomKey() {
		const s = await sodium();
		return s.crypto_secretbox_keygen();
	}

	async function randomHex(bytes) {
		const s = await sodium();
		return s.to_hex(s.randombytes_buf(bytes));
	}

	// ── PoW ──────────────────────────────────────────────────────────────

	/**
	 * Solve sha256(challenge ‖ bind ‖ nonce) leading-zero-bits ≥ difficulty.
	 * `bind` pins the proof to one request (ikey / hint / attachment digest)
	 * so a solved challenge is worthless for any other request.
	 */
	async function solvePow(challengeB64, difficulty, bindStr) {
		const s = await sodium();
		const challenge = fromB64(s, challengeB64);
		const bind = utf8(bindStr || '');
		for (let i = 0; i < 0xffffffff; i++) {
			const nonce = new Uint8Array(8);
			nonce.set(s.randombytes_buf(8));
			const msg = new Uint8Array(challenge.length + bind.length + nonce.length);
			msg.set(challenge); msg.set(bind, challenge.length); msg.set(nonce, challenge.length + bind.length);
			const digest = s.crypto_hash_sha256(msg);
			if (leadingZeros(digest) >= difficulty) {
				return b64Sync(s, nonce);
			}
			if (i % 2000 === 1999) await new Promise((r) => setTimeout(r, 0)); // yield UI
		}
		throw new Error('pow_exhausted');
	}

	/** Lower-hex sha256 of a string — used as the attachment PoW bind. */
	async function sha256Hex(str) {
		const s = await sodium();
		return s.to_hex(s.crypto_hash_sha256(utf8(str)));
	}

	function leadingZeros(bytes) {
		let bits = 0;
		for (const b of bytes) {
			if (b === 0) { bits += 8; continue; }
			for (let m = 7; m >= 0; m--) {
				if ((b >> m) & 1) return bits;
				bits++;
			}
		}
		return bits;
	}

	window.WcCrypto = {
		sodium, encode, decode, format, hint, verifier, reporterKey,
		seal, sealBytes, sealChunked, open, openBytes, sealToPubkey, openSealed,
		officerKeypair, wrapPrivateKey, unwrapPrivateKey, fingerprint,
		randomKey, randomHex, solvePow, sha256Hex,
	};
})();
