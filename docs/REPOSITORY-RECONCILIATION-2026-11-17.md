# Repository reconciliation and release status — 2026-11-17

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Verified base:** `main` at `757281577e11a6118b7e8652f57e21fcfac7a30c`  
**Release status:** NOT production-ready. This document separates merged repository work from external release acceptance; it does not certify a production deployment.

## Latest merged work

The latest main commit is PR #72, `feat(live-class): add 300-seat LMS capacity and protected join flow`.

Recent merged work includes:
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
- #66 server-enforced exam duration and configurable per-student attempt limits
- #67 shared Student Tracking/Student Tracing storage and academic event capture
- #69 Universal Payment branch-isolation enforcement
- #71 atomic manual-payment review and protection of global gateway settings
- #72 300-seat LMS join capacity, protected enrollment/branch-aware join flow, participant heartbeat/leave and Student Tracking join events

PR #62, #71 and #72 each passed the four PR checks: Laravel SQLite, Laravel MySQL 8, Browser E2E and Composer lockfile/security audit. PR #72's CI validates the LMS application join/capacity workflow; it is not a real 300-concurrent-user load test or proof of the conferencing provider's own meeting capacity. The latest main-branch workflow run after #72 merge must also finish successfully before treating that exact main commit as fully CI-verified.

## Admin and CMS scope

The merged admin/site-management features include the visual page builder, navigation builder, content blocks, header/footer builder, sidebar/widget builder, global typography/layout settings, media library recycle bin and dashboard widget ordering.

Important limitation: PR #55 installs validated JSON design-token packages. It is not an unrestricted runtime for arbitrary PHP/Blade WordPress themes. The LMS provides WordPress-like admin controls for supported features, not a claim that every WordPress plugin/theme can run unchanged.

## Plugin inventory and acceptance coverage

The current main branch contains 44 plugin manifests under `laravel/plugins/modules`. `AllPluginsIndependentAcceptanceTest` discovers plugin manifests dynamically and exercises shared lifecycle behavior: provider/manifest validation, no required sibling dependency, install/enable, checksum-verified ZIP update, disable/re-enable, uninstall/reinstall, settings reset, recycle-bin delete and restore, and verified recovery before permanent recycle deletion.

The feature acceptance matrix maps plugin-specific workflows to dedicated test suites. This is automated coverage evidence, not proof that every feature is complete in a real browser or production environment. CI provider integrations are simulated/stubbed; they do not establish live merchant approval, settlement, SMS/email delivery, or Google/Meta account connectivity. Continue source-level review of all 44 modules, their permissions, branch scopes, failure paths and optional integrations.

The independence guarantee currently means independent installation/lifecycle within this Laravel 12 AEC LMS runtime. It does **not** mean the same plugin package can run unchanged on arbitrary WordPress, CodeIgniter, or non-Laravel LMS platforms. Framework-neutral contracts and host adapters remain a separate roadmap item.

## Older open drafts — reconciliation required

The following old drafts are based on significantly divergent histories and must not be merged wholesale:
- #31 — optional sibling integrations / independent plugin installation
- #29 — lifecycle, branch security, notifications and recycle-bin hardening
- #3 — critical plugin audit remediations
- #2 — plugin lifecycle, branch security, attendance and payments

Their changes must be checked against current main file-by-file; only missing changes should be ported onto a fresh branch. Several previously described remediations are already present in current main, including Attendance batch-table compatibility, public admission throttling, SMS response classification, payroll pending/approval, Payment Management branch fail-closed checks, Universal Payment branch isolation, manual review locking, and exam timer/attempt enforcement. Current source and tests—not old PR descriptions—are the acceptance authority.

## Remaining release blockers

| Area | Current evidence | Required next action |
|---|---|---|
| 44 plugin workflows | Dynamic lifecycle suite and mapped plugin-specific tests exist; external providers are stubbed. | Continue full source/permission/branch-scope and browser acceptance review; close any remaining gaps with regression tests. |
| Cross-LMS portability | Current plugins use Laravel providers, routes, middleware, Eloquent, migrations and Blade. | Build framework-neutral contracts and host adapters before claiming arbitrary LMS portability. |
| Core update/rollback | Automated coverage and runbook exist. | Execute signed update and rollback rehearsal on isolated cPanel staging; document schema rollback limitations. |
| Backup/restore | Automated backup tests and runbook exist. | Restore a representative large backup into a separate disposable database and verify private-file checksums. |
| Clean cPanel installation | Release ZIP build/content checks pass in CI. | Install the exact candidate ZIP on disposable PHP 8.3+ cPanel staging. |
| Payment integrations | Simulated adapter/contract tests exist; manual review now uses locking. | Run official sandbox callbacks, replay/amount/currency/idempotency/refund scenarios for each configured provider. |
| 300+ live class | LMS join admission supports a configurable 300-seat default and participant presence. | Run a controlled 300+ virtual-user load test against authorized staging; separately verify the selected conferencing provider's real meeting capacity and stability. |
| Email/SMS and marketing | Provider contracts and tests exist. | Verify real SMTP/SMS credentials, delivery responses, Meta/Google account connections and consent behavior in staging. |
| Security/privacy | Automated security and role/branch regression coverage exists. | Complete a full permission matrix, privacy/retention review and staging penetration/regression pass. |

## Safe execution policy

- Do not merge stale draft PRs just to clear the open-PR list.
- Do not declare a plugin accepted from manifest presence or a test-matrix row alone.
- Do not declare a production install, large restore, live provider, or 300+ meeting test complete without evidence from the actual external environment.
- Continue repository-only work from a fresh branch based on current `main`; require SQLite, MySQL 8, Browser E2E and Composer security checks before merging.
- Keep the production domain and production database untouched until all external release gates pass.
