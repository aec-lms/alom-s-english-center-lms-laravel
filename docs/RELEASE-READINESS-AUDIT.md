# Release Readiness Audit

**Audit date:** 2026-11-15  
**Repository:** `aec-lms/LMS`  
**Production target:** `alomsenglishcenter.com`  
**Hosting target:** cPanel  
**Status:** **Not production-ready — live-host validation and six tracked release issues remain open.**

This audit separates merged engineering work from the remaining real-host and product gaps. A passing CI suite is not proof that the production cPanel account, provider credentials or data are ready.

## Completed and merged foundations

- One-time CLI installer, safe first Admin creation, private configuration outside the document root, permanent installation lock and checksum-verified SQL migrations.
- Unified `Admin` role with former Super Admin privileges migrated to Admin.
- Redacted CLI support diagnostics.
- Signed, data-only plugin package updater with staging, snapshots, rollback and audit. It is not an arbitrary PHP/JavaScript plugin runtime or a full application code updater.
- Admin AI Copilot with typed, allowlisted actions for marketing/SEO settings, Notice Board and Boost campaigns. Each action requires server validation, a before/after preview, a fresh explicit `APPLY`, a database before-image backup, audit logging and guarded `ROLLBACK`. Arbitrary SQL, shell, PHP, filesystem, payment, financial and user-role/password actions are prohibited.
- Marketing/SEO, Meta Pixel, Search Console, SMS, Notice Board, phone/email verification and course/institution/branch Boost foundations. Provider credentials and live-domain validation remain external requirements.
- CLI-only encrypted MySQL/MariaDB and private-storage backup/restore. GPG AES-256 encryption, SHA-256 checksums, encrypted manifest, restore to empty targets only, and table/migration/file verification are implemented.
- cPanel deployment and backup/restore runbooks, preflight checks, support diagnostics and release ZIP verification.

## CI validation

The required CI suite includes:
- PHP 8.3 syntax and migration validation.
- MySQL 8.4 migration/schema tests and PHP API integration.
- Encrypted backup/restore rehearsal: verifies a database row, schema/migration history and private-file content; refuses a non-empty restore target.
- Frontend type-check and production build.
- Chromium browser E2E journeys.
- cPanel release ZIP build and package verification.

The merged backup/restore rehearsal is a CI environment, not a live cPanel hosting account.

## Open release blockers

| Issue | Priority | Remaining acceptance work |
|---|---:|---|
| [#9 — cPanel install, backup and restore](https://github.com/aec-lms/LMS/issues/9) | P0 | Encrypted backup/restore CI rehearsal and Apache protected-path HTTP tests are merged. Still perform the fresh install, protected-path checks and separate-target restore rehearsal on the actual cPanel account, then complete controlled deployment smoke tests. |
| [#10 — SaaS tenant isolation](https://github.com/aec-lms/LMS/issues/10) | P1 | Dedicated-database isolation threat model is documented; custom-domain challenge/DNS verification is rate-limited and every activation/reactivation is now blocked. Full tenant data-plane routing and cross-tenant negative tests remain unimplemented. |
| [#12 — payment callbacks and reconciliation](https://github.com/aec-lms/LMS/issues/12) | P1 | Provider-specific signature verification, idempotent callbacks, verified settlement, refunds/reversals, receipts/invoices and ledger reconciliation. Do not trust browser success responses. |
| [#14 — remaining LMS modules](https://github.com/aec-lms/LMS/issues/14) | P2 | Complete persistent library, hostel, transport, batch, marksheet and finance workflows; remove demo/local-only state. |
| [#15 — private course media](https://github.com/aec-lms/LMS/issues/15) | P1 | Complete private media upload, ownership/role checks, signed or authenticated delivery, range support where needed, and direct-access denial tests. |
| [#16 — email and notifications](https://github.com/aec-lms/LMS/issues/16) | P2 | Complete production email/SMS delivery, queue/retry, templates, preferences, delivery logs and live provider smoke tests. |

See [SAAS-TENANT-ISOLATION.md](SAAS-TENANT-ISOLATION.md) for the chosen dedicated-database model, trusted host-resolution rules and current fail-closed activation controls. Do not activate tenants or market the registry as a functioning multi-tenant SaaS platform.\n\nThe email outbox worker must be configured in cPanel Cron after deployment; its transport status means local mail handoff only, not inbox/bounce confirmation. See the deployment guide for the exact command and key-retention rule.\n\n## External configuration and host validation

These items cannot be declared complete from repository CI alone:

- Set `AEC_AI_API_KEY`, `AEC_PLUGIN_SIGNING_KEY`, `AEC_BACKUP_PASSPHRASE`, database credentials and provider secrets only in the private configuration outside the document root.
- Export the backup passphrase to an approved off-host password manager. A lost passphrase makes encrypted backups unrecoverable.
- Configure and verify Meta Pixel, Google Search Console, SMS and email providers on the production domain.
- Confirm PHP 8.3+, `pdo_mysql`, `mbstring`, `zip`, `zlib`, `proc_open`, GPG, MySQL/MariaDB client tools, tar and gzip on the actual cPanel account.
- Verify HTTPS, Apache rewrite/AllowOverride, file permissions, cron/CLI execution and protected-path HTTP responses on the real host.
- Perform a restore rehearsal into a newly created empty database and new private-storage path before any production cutover.

## Controlled cPanel deployment checklist

1. Back up the current demo `public_html` files separately before replacing anything.
2. Create a new empty MySQL database and grant the intended database user all privileges.
3. Confirm PHP 8.3+ and every required extension/CLI tool using the cPanel deployment guide.
4. Upload the verified release ZIP; keep private config, backup files and private storage outside the document root.
5. Run the one-time installer from cPanel Terminal. Do not expose a browser-based installer.
6. Run `php scripts/preflight.php` and `php scripts/diagnose.php`; resolve every failure.
7. Create an encrypted backup with `php scripts/backup.php` and copy it off-host.
8. Verify Apache/HTTPS rewrites, API access controls, login, registration, Admin, Student, Teacher and Guardian workflows.
9. Verify email/SMS, Meta/Google and payment-provider operations with real test credentials and provider sandbox callbacks.
10. Only after all smoke tests pass should the domain be pointed at the new LMS and the old demo files be retired.

## Recovery policy

- Take an encrypted backup before production upgrades.
- Migrations are forward-only by default. Do not delete the installation lock, manually reverse a migration or rerun the installer over an existing database.
- If an upgrade fails, preserve logs, diagnose the failed migration and prepare a corrected forward migration. If data restoration is required, restore to a separate empty database and new private-storage directory, validate the result, then perform a deliberate cutover.
- Never use the restore script against a non-empty production database. It intentionally refuses non-empty targets and does not drop existing tables.
