# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 — 2026-10-16

### Added

- Shared Check-app design system: sidebar navigation shell with icon catalog,
  breadcrumbs, page headers, settings sub-navigation and mobile drawer —
  consistent look and feel with the sibling Check apps.
- Reporter code screen: QR code plus print stylesheet for safe offline backup of
  the recovery code.
- TicketCheck escalation export with idempotent re-export.
- Retention-failure alerting: after two consecutive failed sweeps officers and
  admins receive a content-free notification.
- Batch key-wrap resolution for inbox surfaces (`findForCasesAndOfficer`) —
  no N+1 at 500 cases per officer.
- `scripts/benchmark-perf.php`: runnable performance evidence harness
  (inbox scale, submission burst, public-form TTFB).
- Officer surfaces now use the shared Check-app view components: inbox
  filter panel (search + status + assignment) with live result count, stat
  tiles, labeled card rows on narrow screens, canonical badges, empty
  states and a visually-verified audit-chain callout.
- Case detail: status actions now offer only legal transitions, archived
  cases explain themselves and lock the reply form instead of failing on
  submit, and case metadata (received, feedback due, assignee) sits in a
  summary header.

### Fixed

- Audit chain append race: concurrent records could compute the same sequence
  number and die on the unique index; the tail read is now `FOR UPDATE`.
- `auditList` existence oracle: audit events are now filtered to cases the
  requesting officer actually holds a key wrap for.
- `receipt_cipher` is envelope-validated before storage.
- Officer key revocation is atomic — status update and wrap deletion share one
  transaction with rollback.
- Reporter reply gained an idempotency key bound to the client draft; retries
  can no longer double-post.
- Every audited mutation now commits atomically with its audit-chain event —
  status, assignment, hold, notes, categories, settings, key registration,
  revocation, wrap grants, retention warnings and deadline reminders can no
  longer land without their provenance record.
- Officer mutations re-check the case-key wrap FOR UPDATE inside the write
  transaction — a concurrent revocation can no longer interleave between
  access check and write.
- Key rotation re-reads the officer row under FOR UPDATE inside its
  transaction, so a concurrent revocation can never be resurrected with
  fresh wraps, and self-wraps are validated against the commit-time held set.
- Officer inbox applies the wrap scope in SQL before the row limit — wraps on
  older cases can no longer be truncated away by newer unreadable cases —
  and overdue cases sort first (spec US-003 AC-3).
- The denied officer page now answers HTTP 403 instead of rendering under 200.
- Report submission keeps one idempotency key + case code per unchanged
  draft — a retry after an ambiguous network failure dedupes server-side
  instead of creating a second unreachable case.
- PostgreSQL compatibility: upgrade-backup restore no longer requires the
  superuser-only `session_replication_role`; per-table trigger suppression runs
  inside the restore transaction.
- WCAG 2.1 AA contrast fixes under dark theme (tinted badges/active states) and
  underlined links inside prose blocks.

### Security

- All ID-bearing routes keep the canonical uniform-404 contract; idempotency,
  CAS revisions and wrap gates verified across the full suite on MariaDB and
  PostgreSQL.

## 1.0.0 — 2026-10-12

Initial release.

## Feedback welcome

We improve this app with feedback from real deployments. Bug reports, rough
edges and ideas help — open a GitHub issue
(https://github.com/aSoftwareByDesignRepository/nextcloud-whistlecheck/issues)
or write to info@software-by-design.de. Your feedback directly shapes what we
build next.
