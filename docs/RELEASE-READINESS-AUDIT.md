# AEC LMS — Laravel release-readiness audit

**Audit date:** 2026-11-15  
**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Production target:** cPanel / main domain  
**Status:** **NOT PRODUCTION-READY — do not deploy or merge as a finished LMS.**

The intended production application is Laravel 12 under `/laravel`. The root Google AI Studio/React prototype and earlier PHP API are retained for reference only. The production document root must be `laravel/public`.

## Current branch / PR state

- Working branch: `fix/laravel-ci-php83`
- Pull request: [PR #1](https://github.com/aec-lms/alom-s-english-center-lms-laravel/pull/1)
- PR is open and unmerged; `main` has not been changed by this work.
- `laravel/composer.lock` has now been generated and committed by a dedicated GitHub Actions workflow.
- The most recent fully verified Laravel test run before the latest package-builder/documentation changes passed **26 tests / 132 assertions** on commit `ab53792`.
- CI now also builds and inspects the cPanel package; the latest run for the newest commits must finish successfully before its result can be recorded.

A green CI run is evidence only for the automated checks that exist. It is not proof of production deployment readiness.

## Implemented foundations on the feature branch

- PHP 8.3 / Laravel 12 CI from the correct `laravel/` application directory.
- Fresh database installer that rejects non-empty databases and does not drop, truncate or import legacy operational data.
- Fresh APP_KEY, plugin-signing key and backup-encryption key generation with private `.env` persistence.
- Installation lock creation and installer route lockout.
- Core plugin provider resolution and explicit failure when plugin migrations fail.
- Plugin manifest catalog, lifecycle controls, dependency checks, encrypted plugin settings and data-preserving uninstall foundations.
- Student branch isolation checks on create/update.
- Encrypted backup archive creation, SHA-256/HMAC validation, private backup path validation, private-file staging/restore and foreign-key-aware database restore.
- Signed update manifest validation, semantic-version checks, target allowlisting, ZIP path/symlink/duplicate/size checks.
- Reproducible Composer dependency lock and a Laravel-only cPanel package builder with private runtime exclusions.
- Updated deployment and backup/restore documentation.

## Plugin catalog — 39 entries

The repository currently contains 39 plugin manifests:

Branch, Student, Teacher, Guardian, Admission, Course, Class, Batch, Exam, Attendance, Certificate, Library, HR/Staff, Fees, Payment Management, Visa/Mastercard, SSLCOMMERZ, Stripe, PayPal, Universal Payment, SMS, Email Notifications, Notice Board, Notification Center, Reports/Analytics, SEO, Marketing, Gmail Marketing, Meta Pixel, Google Search Console, Admin Boosting, Visitor Popup Ads, Website CMS, Page Builder, Slider Builder, Carousel Builder, Storefront Catalog, Mobile App API and Role-based AI Assistant.

**A manifest/catalog entry is not proof of a complete module.** The modules need individual acceptance criteria, complete CRUD/workflows, authorization/branch-scope checks, UI validation, integration tests and end-to-end tests. Page Builder, Slider Builder, Carousel Builder and each payment gateway are separate plugins by design.

## Remaining release blockers

| Area | Required acceptance work |
|---|---|
| Full LMS modules | Complete all academic, student, guardian, teacher, batch, class, exam, attendance, certificate, library, HR, fees, finance, CMS, builder, reports and notification workflows; replace any demo/local-only behavior |
| Branch isolation | Verify every branch-scoped query, create/update/delete, report, export, file download and API across every relevant plugin; add cross-branch negative tests |
| Payments | Real sandbox checkout and provider API validation for SSLCOMMERZ, Visa/Mastercard processor, Stripe and PayPal; idempotent signed callbacks, amount/currency/order matching, settlement, refund/reversal and ledger reconciliation |
| Backup/restore | Test large MySQL/MariaDB snapshots, PHP memory/time limits, file/database consistency, key recovery and restore into a disposable separate database; current Admin restore replaces rows in the configured database and merges private files |
| Installer | Run the fresh installer on a clean cPanel account, verify no existing data can be overwritten, verify generated keys/permissions and test installer lockout |
| cPanel packaging | Build exact release ZIP from a clean checkout; verify Composer dependencies, no secrets/private files, correct public document root and protected HTTP paths |
| Browser/security | Complete browser E2E, CSRF/session/RBAC tests, upload/download authorization, ZIP abuse tests, dependency audit, static analysis and security review |
| Operations | Verify SMTP, queues/cron, SMS, provider credentials, backups off-host, logs, restore procedure and real-host monitoring |

## Backup/restore safety notes

The Laravel backup service currently serializes database tables to JSON in memory and encrypts a ZIP archive. Private files can be included and restored through a validated staging directory. The current restore path:
- Requires matching Core version and valid checksum/HMAC.
- Replaces rows in matching tables (except `migrations` and `backups`) inside a DB transaction.
- Restores private files by merging them into the private storage directory; files absent from the archive are not removed.
- Does not make database and filesystem restore one atomic transaction.
- Does not switch to a separate restore database.

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

Do not point the main domain at this branch, do not import old/demo records, and do not delete the old application until a verified release and recovery plan exist.
