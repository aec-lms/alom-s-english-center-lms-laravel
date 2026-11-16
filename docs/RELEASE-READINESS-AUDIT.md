# ALOMS ENGLISH CENTER — Laravel release-readiness audit

**Audit date:** 2026-11-16  
**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Production target:** cPanel / main domain  
**Status:** **NOT PRODUCTION-READY — do not deploy or merge as a finished LMS.**

The sole production application is Laravel 12 under `/laravel`. The production document root must be `laravel/public`.

## Current branch / PR state

- Current base: `main`, latest verified commit `2471a9a929d1b36684aa82f2f9889d4c75ecfb01`.
- [PR #1](https://github.com/aec-lms/alom-s-english-center-lms-laravel/pull/1) is **merged** (squash merge); it is no longer open.
- The merged PR's final CI run [43817535568](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/43817535568) completed successfully: PHP 8.3 setup, locked Composer install, Composer security audit, Laravel test command, cPanel package build, package-content verification and artifact upload all passed.
- `laravel/composer.lock` is committed and CI uses it for reproducible dependency installation.
- The passing workflow proves only the checks currently implemented in CI; it does not establish complete acceptance of all 39 plugins or production readiness.
- This audit refresh is being prepared on `chore/release-audit-and-plugin-matrix`; it does not change `main` until reviewed and merged.

A green CI run is evidence only for the automated checks that exist. It is not proof of production deployment readiness.

## Implemented foundations on the feature branch

- PHP 8.3 / Laravel 12 CI from the `laravel/` application directory.
- Fresh database installer that rejects non-empty databases, validates Admin details, refuses public-root private-storage paths and does not drop or truncate operational data.
- Fresh APP_KEY, plugin-signing key and backup-encryption key generation with private `.env` persistence.
- Installation lock creation and installer route lockout.
- Core plugin provider resolution and explicit failure when plugin migrations fail.
- Plugin manifest catalog, lifecycle controls, dependency checks, encrypted plugin settings and data-preserving uninstall foundations.
- Student branch isolation checks on create/update.
- Encrypted backup archive creation, SHA-256/HMAC validation, private backup path validation, private-file staging/restore and foreign-key-aware database restore.
- Signed update manifest validation, semantic-version checks, target allowlisting, ZIP path/symlink/duplicate/size checks.
- Reproducible Composer dependency lock, Composer vulnerability audit and a Laravel-only cPanel package builder with private runtime exclusions.
- Manual Universal Payment receipt evidence accepts validated PDF/JPG/PNG files on private local storage; proof downloads require finance permission and enforce branch ownership, and receipt review uses a row lock.
- Public Mobile API ebook/product catalog payloads omit private download URLs; paid lesson endpoints require Sanctum authentication and active enrollment.
- All 39 plugin manifests have empty required-sibling dependency lists; a PHP namespace scan checks direct sibling references against optional integration declarations, and standalone regression tests cover Course Management without optional finance/student modules.

## Plugin catalog — 39 entries

The repository currently contains 39 plugin manifests: Branch, Student, Teacher, Guardian, Admission, Course, Class, Batch, Exam, Attendance, Certificate, Library, HR/Staff, Fees, Payment Management, Visa/Mastercard, SSLCOMMERZ, Stripe, PayPal, Universal Payment, SMS, Email Notifications, Notice Board, Notification Center, Reports/Analytics, SEO, Marketing, Gmail Marketing, Meta Pixel, Google Search Console, Admin Boosting, Visitor Popup Ads, Website CMS, Page Builder, Slider Builder, Carousel Builder, Storefront Catalog, Mobile App API and Role-based AI Assistant.

**A manifest/catalog entry is not proof of a complete module.** The modules need individual acceptance criteria, complete CRUD/workflows, authorization/branch-scope checks, UI validation, integration tests and end-to-end tests. Page Builder, Slider Builder, Carousel Builder and each payment gateway are separate plugins by design.

## Remaining release blockers

| Area | Required acceptance work |
|---|---|
| Full LMS modules | Complete all academic, student, guardian, teacher, batch, class, exam, attendance, certificate, library, HR, fees, finance, CMS, builder, reports and notification workflows |
| Branch isolation | Verify every branch-scoped query, create/update/delete, report, export, file download and API across every relevant plugin; add cross-branch negative tests |
| Payments | Real sandbox checkout and provider API validation for SSLCOMMERZ, Visa/Mastercard processor, Stripe and PayPal; idempotent signed callbacks, amount/currency/order matching, settlement, refund/reversal and ledger reconciliation |
| Backup/restore | Test large MySQL/MariaDB snapshots, PHP memory/time limits, file/database consistency, key recovery and restore into a disposable separate database |
| Installer | Run the fresh installer on a clean cPanel account, verify no existing data can be overwritten, verify generated keys/permissions and test installer lockout |
| cPanel packaging | Build exact release ZIP from a clean checkout; verify Composer dependencies, no secrets/private files, correct public document root and protected HTTP paths |
| Browser/security | Complete browser E2E, CSRF/session/RBAC tests, upload/download authorization, ZIP abuse tests, dependency audit, static analysis and security review |
| Operations | Verify SMTP, queues/cron, SMS, provider credentials, backups off-host, logs, restore procedure and real-host monitoring |
| Admin Recycle Bin | No verified cross-module recycle-bin/restore workflow is present in the current source tree; implement per-module archive/restore/permanent-purge contracts, permission and branch scoping, and private-file retention tests before acceptance |
| Live-class capacity | A basic LiveClass model stores meeting URLs, but 300+ participant capacity is not enforced or provider-verified; require a selected provider adapter, sandbox/API validation and load test before promising 300+ concurrent attendees |

## Backup/restore safety notes

The Laravel backup service currently serializes database tables to JSON in memory and encrypts a ZIP archive. Private files can be included and restored through a validated staging directory. The current restore path replaces rows in matching tables and merges included private files; it does not make database and filesystem restore one atomic transaction or switch to a separate restore database.

This is automated-test-covered but not yet proven on a production-sized MySQL/cPanel host. Use only disposable staging data until a safe separate-target restore workflow and live-host rehearsal are complete.

## Required pre-production release gates

1. Complete the module acceptance matrix and automated tests.
2. Complete provider sandbox and callback/reconciliation tests.
3. Complete cross-branch authorization tests for every module.
4. Run PHP syntax/static analysis and dependency/security scans.
5. Build the exact release ZIP from a clean checkout and inspect its contents/checksum.
6. Install on a clean cPanel staging account using a new empty database and document root `laravel/public`.
7. Test private uploads, HTTP path denial, HTTPS, SMTP, queue cron and Admin/Student/Teacher/Guardian workflows.
8. Create an encrypted backup, restore it into a separate disposable database/private directory and verify the restored LMS.
9. Review all failures and only then approve PR merge and controlled production deployment.

Do not point the main domain at an unverified build or import operational data before a verified release and recovery plan exist.


## Repository inventory review (2026-11-16)

- The current `main` tree contains 39 plugin directories under `laravel/plugins/modules/`.
- Static inventory confirms each directory has a `plugin.json`, controller, routes and Blade view. Most also have a model and migration; gateway and integration-style plugins differ by design.
- This is a file-presence inventory only. It is **not** a pass/fail functional test and must not be represented as module completion.
- See [PLUGIN-ACCEPTANCE-MATRIX.md](PLUGIN-ACCEPTANCE-MATRIX.md) for the per-plugin inventory and required acceptance evidence.
- A complete implementation audit must inspect controller/service/model/view behavior, route middleware, policy/permission checks, branch constraints, validation, failure handling and automated positive/negative tests for every module.
