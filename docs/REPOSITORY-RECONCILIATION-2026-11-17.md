# Repository reconciliation and release status — 2026-11-17

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Verified base:** `main` at `757281577e11a6118b7e8652f57e21fcfac7a30c`  
**Release status:** NOT production-ready. This document reconciles merged work and open drafts; it does not certify production acceptance.

## Main branch and latest verified checks

The latest main commit includes PR #72, `feat(live-class): add 300-seat LMS capacity and protected join flow`. Recent merged work also includes PR #69 (Universal Payment branch isolation), PR #67 (shared Student Tracking/Tracing storage), PR #66 (server-enforced exam duration and attempt limits), and PR #65 (repository reconciliation).

The exact main commit has four completed successful checks:
- Laravel SQLite test job
- Laravel MySQL 8 test job
- Browser Playwright E2E smoke job
- Composer lockfile validation and dependency security audit

The PR #72 head passed all four checks before merge: 250 tests and 3,319 assertions on both SQLite and MySQL 8, Browser E2E, and Composer Security Audit. The Laravel job verified the 6,417-file cPanel package. The PR #62 head had also passed its four checks before merge. The Laravel workflow includes the cPanel release-package build and package-content verification.

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
- #69 Universal Payment branch-isolation enforcement
- #72 300-seat LMS live-class capacity, protected student join, participant presence and heartbeat

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

- **LMS live-class capacity (PR #72):** live sessions now default to 300 LMS seats, support configurable capacity, require an active enrollment and branch match, serialize seat allocation, and maintain participant presence through heartbeat/leave. This is an LMS join gate, not a guarantee of 300 participants in the external Zoom/Meet/Teams room.
- **Universal Payment branch isolation (PR #69):** branch managers are restricted to their active assigned branch for payment operations; retain this regression coverage when changing payment flows.
- **Exam timing and retakes (PR #66):** each exam has a configurable maximum attempt count (1–20, default 1). Server-created attempts persist at exam entry, refresh resumes the same attempt, server time enforces the deadline, and expired attempts are finalized with zero marks.
- **Student activity storage collision (PR #67):** Student Tracking and Student Tracing now converge on a shared nullable schema without deleting history. Tracking records login, enrollment, exam submission and attendance events without required sibling dependencies.

## Confirmed remaining release blockers

| Area | Current evidence | Required next action |
|---|---|---|
| Exam timer and attempt policy | Exam controller checks published state and exam availability window, but the current submission flow does not enforce elapsed `duration_minutes` or a configured per-student attempt limit. | Implement server-authoritative attempt start/deadline and configurable attempt limits, with race-condition and expiry tests. |
| 44 plugin workflows | Dynamic lifecycle suite and mapped plugin-specific tests exist; external providers are stubbed. | Continue source/permission/branch-scope and browser acceptance review; close gaps with tests. |
| Cross-LMS portability | Current plugins use Laravel providers, routes, middleware, Eloquent, migrations and Blade. | Framework-neutral contracts and host adapters are still required before claiming arbitrary LMS portability. |
| Core update/rollback | Automated coverage and runbook exist. | Execute the complete signed update and rollback rehearsal on isolated cPanel staging; document schema rollback limitations. |
| Backup/restore | Automated backup tests and runbook exist. Backup serialization/restore is not a production-sized separate-target rehearsal. | Restore a representative anonymized large backup into a separate disposable database and verify private-file checksums. |
| Clean cPanel installation | Release ZIP build/content checks pass in CI. | Install the exact candidate ZIP on a disposable PHP 8.3+ cPanel staging site. |
| Payment integrations | Simulated adapter/contract tests exist. | Run official sandbox callbacks, replay/amount/currency/idempotency/refund scenarios for each configured provider. |
| 300+ live class | PR #72 implements a default 300-seat LMS join gate and presence tracking. CI covers authorization, capacity rejection, heartbeat and seat release. | Run the 300+ join flow against an authorized staging host and separately verify the chosen Zoom/Meet/Teams plan with 300 real test participants. External provider capacity is still unverified. |

## Safe execution policy

- Do not merge stale draft PRs just to clear the open-PR list.
- Do not declare a plugin accepted from manifest presence or a test-matrix row alone.
- Do not declare a production install, large restore, live provider, or 300+ meeting test complete without evidence from the actual external environment.
- Continue all repository-only work from a fresh branch based on current `main`; require SQLite, MySQL 8, Browser E2E and Composer security checks before merging.
- Keep the production domain and production database untouched until all external release gates pass.
