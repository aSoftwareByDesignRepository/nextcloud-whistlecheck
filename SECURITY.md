# Security Policy

## Supported versions

We support the latest published WhistleCheck release on currently supported
Nextcloud majors listed in `appinfo/info.xml`.

## Reporting a vulnerability

This app exists to protect whistleblowers — please treat findings with the
same care. Email **info@software-by-design.de** (or use GitHub Security
Advisories on this repository) with a description and steps to reproduce.
Do **not** open a public GitHub issue for security reports.

## Operator notes

- Reporter anonymity depends on client-side encryption. Keep the
  "no-JavaScript submission" option off if your threat model requires strict
  E2E — the fallback encrypts on the server and is honestly flagged to the
  reporter.
- Officer passphrases protect the private key in the browser only. Losing
  every officer's passphrase makes case content unrecoverable by design.
- Rate-limit buckets are keyed by HMAC(daily salt, IP) — no raw IPs are
  stored. The daily salt rotates and is not retained.
- The audit log is hash-chained and content-free by construction; it proves
  events, never content.
