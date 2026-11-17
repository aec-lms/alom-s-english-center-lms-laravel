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
- The passing workflow proves only the checks currently implemented in CI; it does not establish complete acceptance of all 40 plugins or production readiness.
- This audit refresh is being prepared on `chore/release-audit-and-plugin-matrix`; it does not change `main` until reviewed and merged.
- [PR #2](https://github.com/aec-lms/alom-s-english-center-lms-laravel/pull/2) remains **open and unmerged** on `chore/release-audit-and-plugin-matrix`; no production deployment has occurred.
- Latest branch CI [44017802867](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/44017802867) completed successfully on commit `d61714cd4953c27582fac4b1fe89e0aeac504137`: **205 tests (3,027 assertions)** passed on SQLite and MySQL 8.0.46, including dynamic discovery of all plugin manifests. Browser smoke E2E [44017802839](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/44017802839) and Composer Security Audit [44017802833](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/44017802833) also passed. These runs do not prove production-sized restore, full browser workflows, provider sandbox acceptance or cPanel installation.

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
- All 40 plugin manifests have empty required-sibling dependency lists; a PHP namespace scan checks direct sibling references against optional integration declarations, and standalone regression tests cover Course Management without optional finance/student modules.

## Plugin catalog — 40 entries (feature branch)

The feature branch currently contains 40 plugin manifests: Branch, Student, Student Tracking, Teacher, Guardian, Admission, Course, Class, Batch, Exam, Attendance, Certificate, Library, HR/Staff, Fees, Payment Management, Visa/Mastercard, SSLCOMMERZ, Stripe, PayPal, Universal Payment, SMS, Email Notifications, Notice Board, Notification Center, Reports/Analytics, SEO, Marketing, Gmail Marketing, Meta Pixel, Google Search Console, Admin Boosting, Visitor Popup Ads, Website CMS, Page Builder, Slider Builder, Carousel Builder, Storefront Catalog, Mobile App API and Role-based AI Assistant.

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
| Browser/security | A Chromium Playwright smoke suite now covers public auth pages/password visibility, Admin sign-in/dashboard/Recycle Bin/Plugin Center and guest denial. This is initial smoke coverage, not complete browser E2E. Expand real CRUD/restore flows and retain CSRF/session/RBAC, upload/download authorization, ZIP abuse, static analysis and security review gates |
| Operations | Verify SMTP, queues/cron, SMS, provider credentials, backups off-host, logs, restore procedure and real-host monitoring |
| Admin Recycle Bin | Implemented for 17 record types across Student, Teacher, Course, Course Category, Live Class, Exam, Batch, Academic Class, Branch, Boost Campaign, Carousel Item, CMS Category/Post, Page Builder Page, Hero Slider, SMS Campaign and Visitor Popup. Admin-only restore is transactional/row-locked with audit events. Still require broader browser coverage, branch-scope review where applicable, retention/private-file tests and explicit permanent-purge policy acceptance |
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

- The `main` tree at the audit baseline contains 39 plugin directories; the current feature branch contains 40 plugin directories under `laravel/plugins/modules/`.
- Static inventory confirms each directory has a `plugin.json`, controller, routes and Blade view. Most also have a model and migration; gateway and integration-style plugins differ by design.
- This is a file-presence inventory only. It is **not** a pass/fail functional test and must not be represented as module completion.
- See [PLUGIN-ACCEPTANCE-MATRIX.md](PLUGIN-ACCEPTANCE-MATRIX.md) for the per-plugin inventory and required acceptance evidence.
- A complete implementation audit must inspect controller/service/model/view behavior, route middleware, policy/permission checks, branch constraints, validation, failure handling and automated positive/negative tests for every module.


## Recycle Bin implementation status (feature branch)

The current feature branch now contains a permission-gated Admin Recycle Bin with paginated lists and restore handlers for 17 record types: Students, Teachers, Courses, Course Categories, Live Classes, Exams, Study Batches, Academic Classes, Branches, Boost Campaigns, Carousel Items, CMS Categories, CMS Posts, Page Builder Pages, Hero Sliders, SMS Campaigns and Visitor Pop-ups. Supported delete actions use soft deletion; restore operations use database transactions and row locks and emit audit events. Student/Teacher restoration also returns linked account activation to its pre-deletion state. This is not a claim that every plugin has a complete recycle-bin workflow: financial records are deliberately not exposed to destructive deletion, and permanent purge, private-file retention, branch-scoped edge cases and broader browser coverage remain acceptance work.

## Browser E2E status

A dedicated Playwright/Chromium GitHub Actions workflow runs against an isolated SQLite database and test-only Admin fixture. The current smoke suite checks public home/login/register/forgot-password rendering, password visibility, Admin authentication and navigation to the dashboard, Recycle Bin and Plugin Center, plus guest denial of the Admin Recycle Bin. Three browser tests are present; this is a useful smoke baseline, not comprehensive end-to-end acceptance of the LMS.


## Latest CI verification (2026-11-17)

- Dynamic manifest discovery test and the full Laravel suite passed: 205 tests, 3,027 assertions on SQLite.
- MySQL 8.0.46 integration suite passed: 205 tests, 3,027 assertions.
- Browser Playwright smoke workflow passed.
- Composer dependency security audit passed.
- The feature branch remains PR #2, open and draft. The main branch and production domain were not changed.
- The PR branch is substantially divergent from the recorded main baseline and must receive a careful base/history review before merge; a green CI run alone is not merge authorization.
- Remaining external release gates include a production-sized SQL restore into a disposable database, a real clean cPanel staging install, complete Core upgrade/rollback rehearsal on staging, provider sandbox callbacks/reconciliation, and a validated 300+ live-class provider/load test. These cannot be truthfully marked complete from CI-only tests.


## External staging rehearsal tracking (2026-11-17)

- Runbook: [EXTERNAL-STAGING-REHEARSAL.md](EXTERNAL-STAGING-REHEARSAL.md) defines safe, isolated procedures and pass criteria for production-sized database restore, clean cPanel install, Core update/rollback, payment provider sandbox acceptance, and separate LMS-vs-meeting 300+ capacity tests.
- Tracking issue: [#14 — External staging release gates](https://github.com/aec-lms/alom-s-english-center-lms-laravel/issues/14).
- These external gates remain **OPEN / NOT RUN** until the required anonymized dump, disposable cPanel staging environment, official provider sandbox access, and authorized conferencing load-test facility are available. No production resources were used.
- Documentation commit `59087fa97ceda3fb4837ec76b3c4509a15aec860` has Composer Security Audit passing; Laravel MySQL/SQLite and Browser E2E workflows are running.
