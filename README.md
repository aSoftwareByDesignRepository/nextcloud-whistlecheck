# WhistleCheck

Anonymous, end-to-end encrypted whistleblowing channel for Nextcloud (AGPL) —
built for the German Hinweisgeberschutzgesetz (HinSchG) and EU Directive
2019/1937 internal reporting duties.

Reporters submit without a Nextcloud account. Reports are sealed **in the
browser** (libsodium XSalsa20-Poly1305, X25519 sealed boxes); the server only
ever sees ciphertext, a lookup hint, and an Argon2id verifier derived from the
reporter's case code. Case officers unlock their keys locally — passphrases
and private keys never leave the browser.

## Features

- Anonymous report form with client-side E2E encryption (optional honest
  no-JS fallback: server-side encryption, visibly flagged)
- Case-code-only reporter follow-up thread (26-char Crockford code, shown once)
- Officer inbox, case view, internal notes, assignment, legal hold
- Statutory deadlines: receipt (7 days) + feedback (3 months), reminder job
- Retention enforcement: crypto-shredding (keys → blobs → ciphertext → row →
  audit proof, one transaction)
- Hash-chained audit log — no report content is ever logged
- Abuse controls: HMAC-bucketed rate limits (no raw IPs), PoW escalation,
  single-use form tokens, idempotent submit
- Companion-app API: paired-device bearer tokens (`wcd_…`), replay window
- Two-person close rule when ≥2 officers are active
- German + English UI, WCAG 2.1 AA, strict CSP on public pages

## Dev

```bash
composer install
./vendor/bin/phpunit --testsuite unit
php tests/Mutation/run-critical-mutations.php
```

Integration tests (Docker Nextcloud service):

```bash
cd ../../  # nextcloud/
docker compose exec -u www-data nextcloud \
  php /var/www/html/custom_apps/whistlecheck/vendor/bin/phpunit \
  --configuration /var/www/html/custom_apps/whistlecheck/phpunit.xml \
  --testsuite integration
```

Enable: `occ app:enable whistlecheck`, then add case officers to the
`whistlecheck_officers` group.

## Security

Threat model, invariants, and reporting: [SECURITY.md](SECURITY.md).
