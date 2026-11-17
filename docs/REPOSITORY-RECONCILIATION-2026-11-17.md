# Repository reconciliation and release status — 2026-11-17

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Verified base:** `main` at `b2b5dd6f254d798738d6c3cfa2e38c60b4119a19`  
**Release status:** NOT production-ready. This document reconciles merged work and open drafts; it does not certify production acceptance.

## Main branch and latest verified checks

The latest main commit includes the squash merge of PR #67, `fix(student-activity): unify tracking and tracing storage`. Recent merged work also includes PR #66 (server-enforced exam duration and attempt limits) and PR #65 (repository reconciliation).

The PR head for the latest functional change passed all four required checks before merge: 248 tests and 3,296 assertions on both SQLite and MySQL 8, Browser Playwright E2E, and Composer dependency security audit. The Laravel job also built and verified the cPanel package (6,413 files). Main-branch checks are re-run after the squash merge.
- Laravel SQLite test job
- Laravel MySQL 8 test job
- Browser Playwright E2E smoke job
- Composer lockfile validation and dependency security audit

The PR #62 head also passed these same four checks before merge. The Laravel workflow includes the cPanel release-package build and package-content verification.

Merged admin and site-management work includes:
- #49 Bengali resumable setup wizard
- #50 global typography and layout controls
- #51 visual page builder
- #52 public navigation builder
- #54 content block library
- #55 safe theme-token package manager
- #56 visual header/footer builder
- #58 widget area/sidebar builder
- #60 media library metadata, collections and recycle bin
- #62 draggable dashboard widget ordering
- #65 repository/release-status reconciliation
- #66 server-enforced exam timer and configurable per-student attempt limits
- #67 shared Student Tracking/Student Tracing storage and academic event capture

Important scope limitation: PR #55 is a safe JSON design-token package manager. It is not a runtime for arbitrary PHP/Blade WordPress themes.

## Plugin inventory and acceptance coverage

The current main branch contains 44 plugin manifests under `laravel/plugins/modules`. `AllPluginsIndependentAcceptanceTest` discovers plugin manifests dynamically and exercises shared lifecycle behavior for each discovered plugin: provider/manifest validation, no required sibling dependency, install/enable, checksum-verified ZIP update, disable/re-enable, uninstall/reinstall, settings reset, recycle-bin delete and restore, and verified recovery before permanent recycle deletion.

The feature acceptance matrix maps plugin-specific workflows to dedicated test suites. This is automated coverage evidence, not proof that every module is complete in a real browser or production environment. CI provider integrations are simulated/stubbed; they do not establish live merchant approval, settlement, SMS/email delivery, or Google/Meta account connectivity.

The independence guarantee currently means independent installation/lifecycle within this Laravel 12 AEC LMS runtime. It does **not** mean the same plugin package can run unchanged on arbitrary WordPress, CodeIgniter, or non-Laravel LMS platforms. The framework-neutral adapter architecture remains a separate roadmap item.

## Older open draft PRs — reconciliation required

The following older drafts remain open but are based on significantly divergent histories and are not safe to merge wholesale:
- #31 — optional sibling integrations / independent plugin installation
- #29 — lifecycle, branch security, notifications and recycle-bin hardening
- #3 — critical plugin audit remediations
- #2 — plugin lifecycle, branch security, attendance and payments

Do not merge these stale branches directly. Their changes must be checked against current main file-by-file and only genuinely missing changes should be ported onto a fresh branch from current main. Some remediations described in their summaries are already present in current main (for example attendance batch-table compatibility, public admission throttling, SMS response classification, payroll pending/approval, payment receipt review controls and branch-manager fail-closed checks). The current source and tests—not old PR descriptions—are the acceptance authority.

## Recently resolved repository-level gaps

- **Exam timing and retakes (PR #66):** each exam now has a configurable maximum attempt count (1–20, default 1). A server-created attempt is persisted at exam entry, refresh resumes that attempt, the server enforces the deadline, expired attempts are finalized with zero marks, and completed attempts cannot be replayed. SQLite and MySQL regression tests cover expiry, grading, resume and attempt exhaustion.
- **Student activity storage collision (PR #67):** Student Tracking and Student Tracing previously created incompatible schemas under the same `student_activity_events` table. Forward-only migrations now converge both plugins on a shared superset schema without deleting history. Student Tracking captures trusted server-side login, enrollment, exam-submission and attendance events while remaining installable without sibling plugins.

## Confirmed remaining release blockers

| Area | Current evidence | Required next action |
|---|---|---|
| Exam timer and attempt policy | Implemented in PR #66 and covered by SQLite/MySQL expiry, resume, grading and attempt-limit tests. | Continue regression testing when exam workflows change; external staging remains part of final release acceptance. |
| 44 plugin workflows | Dynamic lifecycle suite and mapped plugin-specific tests exist; external providers are stubbed. | Continue source/permission/branch-scope and browser acceptance review; close gaps with tests. |
| Cross-LMS portability | Current plugins use Laravel providers, routes, middleware, Eloquent, migrations and Blade. | Framework-neutral contracts and host adapters are still required before claiming arbitrary LMS portability. |
| Core update/rollback | Automated coverage and runbook exist. | Execute the complete signed update and rollback rehearsal on isolated cPanel staging; document schema rollback limitations. |
| Backup/restore | Automated backup tests and runbook exist. Backup serialization/restore is not a production-sized separate-target rehearsal. | Restore a representative anonymized large backup into a separate disposable database and verify private-file checksums. |
| Clean cPanel installation | Release ZIP build/content checks pass in CI. | Install the exact candidate ZIP on a disposable PHP 8.3+ cPanel staging site. |
| Payment integrations | Simulated adapter/contract tests exist. | Run official sandbox callbacks, replay/amount/currency/idempotency/refund scenarios for each configured provider. |
| 300+ live class | A load workflow/runbook exists; it has not been run against an authorized staging host and approved conferencing provider. | Run LMS join-load and provider meeting-capacity tests separately, with evidence. |

## Safe execution policy

- Do not merge stale draft PRs just to clear the open-PR list.
- Do not declare a plugin accepted from manifest presence or a test-matrix row alone.
- Do not declare a production install, large restore, live provider, or 300+ meeting test complete without evidence from the actual external environment.
- Continue all repository-only work from a fresh branch based on current `main`; require SQLite, MySQL 8, Browser E2E and Composer security checks before merging.
- Keep the production domain and production database untouched until all external release gates pass.
