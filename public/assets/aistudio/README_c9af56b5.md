# Alom's English Center LMS

A custom learning and administration platform for Alom's English Center.

- **Production target:** `alomsenglishcenter.com`
- **Hosting target:** cPanel with PHP 8.3+, MySQL 8 or compatible MariaDB, Apache `mod_rewrite`, and CLI access.
- **Application:** React/Vite frontend with a PHP API and versioned SQL migrations.

## Implemented foundations

- Role-aware LMS APIs and dashboards for courses, lessons, enrolments, users, exams, question banks, attendance, CMS content, admission inquiries and certificates.
- Persistent marketing/SEO settings and foundations for Meta Pixel, Google Search Console, SMS, Notice Board, email/mobile verification and course/institution/branch Boost campaigns. Real provider operation still requires private credentials and live-host verification.
- One-time CLI installer, first Admin creation, private configuration, permanent installation lock and checksum-verified migrations.
- Unified `Admin` role; no separate Super Admin role.
- Signed, data-only plugin package updater with staged installation and rollback. This is not an arbitrary PHP/JavaScript plugin runtime or a whole-LMS code updater.
- Admin AI Copilot with allowlisted marketing, notice and Boost actions. It requires a server-side AI key, displays a before/after preview, requires explicit `APPLY`, stores a before-image backup, audits the change and supports guarded `ROLLBACK`. It cannot run arbitrary SQL, shell, PHP or filesystem actions.
- Redacted CLI support diagnostics.
- CLI-only GPG AES-256 encrypted database and private-storage backup/restore. Restore is restricted to a pre-created empty database and a new private-storage directory.
- cPanel deployment and backup/restore runbooks.

## Release status — 2026-11-15

**Not production-ready.**

The engineering foundations and CI checks are substantially improved, but the production LMS has **not** been installed on the live cPanel account. Do not delete the existing demo files or import production data until a separate backup exists and the deployment runbook has been followed.

### Open release blockers

| Issue | Remaining work |
|---|---|
| [#9](https://github.com/aec-lms/LMS/issues/9) | Live rehearsal on the actual cPanel account, verify host tools/permissions/Apache/HTTPS and complete a controlled deployment smoke test. Encrypted backup/restore code and CI rehearsal are merged. |
| [#10](https://github.com/aec-lms/LMS/issues/10) | Enforce SaaS tenant data-plane isolation before enabling tenant workloads. |
| [#12](https://github.com/aec-lms/LMS/issues/12) | Verified provider callbacks, signature validation, idempotency, refunds/reversals and financial reconciliation. Never mark a payment paid from an unverified browser response. |
| [#14](https://github.com/aec-lms/LMS/issues/14) | Finish persistent library, hostel, transport, batch, marksheet and finance workflows. |
| [#15](https://github.com/aec-lms/LMS/issues/15) | Complete private course media upload, authorization and protected delivery. |
| [#16](https://github.com/aec-lms/LMS/issues/16) | Complete production email/SMS notification delivery, retry, logging and preferences. |

SaaS tenant activation is disabled until tenant-specific data-plane isolation is implemented; see [docs/SAAS-TENANT-ISOLATION.md](docs/SAAS-TENANT-ISOLATION.md).\n\n### External configuration still required

- Keep `AEC_AI_API_KEY`, `AEC_BACKUP_PASSPHRASE`, `AEC_PLUGIN_SIGNING_KEY`, database credentials and provider credentials only in the private configuration outside the document root.
- Export the backup passphrase to an approved off-host password manager before relying on encrypted backups.
- Meta, Google Search Console and SMS providers must be configured privately and verified on the live domain.
- Email deliverability, OTP delivery, payment callbacks, HTTPS and Apache rewrite/access rules require a real cPanel smoke test.

## Validation

GitHub Actions runs:
- PHP 8.3 syntax and migration validation.
- MySQL 8.4 migration/schema and PHP API integration tests.
- Encrypted database/private-storage backup and restore rehearsal, including data/file verification and refusal of non-empty restore targets.
- Frontend type-check and production build.
- Chromium browser E2E journeys.
- Production cPanel ZIP build and package verification.

A green CI run does not prove the actual hosting account is configured correctly or that production provider credentials work.

## Deployment

Read [cPanel deployment](docs/CPANEL-DEPLOYMENT.md) and [encrypted backup/restore](docs/CPANEL-BACKUP-RESTORE.md) before touching the production domain.

## Development

```bash
bun install --frozen-lockfile
bun run lint
bun run build
```

Run backend validation only against a disposable test database. Never point destructive integration tests at production data.
