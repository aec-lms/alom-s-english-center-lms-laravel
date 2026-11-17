# Aloms English Center LMS — Repository Reconciliation and Release Status

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Verified main commit at this document update:** `7efb8f6dba61a0fbb9c3cf4f49e78ff35e9ef685`  
**Release status:** Not yet production-ready. Repository CI and code-level acceptance are not substitutes for real cPanel staging, external provider, backup-restore, and load-test evidence.

## Recently merged work

Recent PRs merged after the initial 300-seat LMS join work include:

- **#77 — Live-class authorization:** join events are recorded only after a seat is granted; heartbeat requires active enrollment and branch eligibility.
- **#78 — Fees branch isolation:** unassigned or inactive branch managers cannot view or create fee invoices.
- **#79 — Exam branch isolation:** branch-manager exam workflows fail closed without an active assigned branch.
- **#82 — Load-test scope documentation:** explicitly distinguishes public-page k6 testing from authenticated live-class stress testing.
- **#83 — Enrollment branch isolation:** unassigned branch managers cannot view or change enrollments.
- **#84 — Course branch isolation:** branch-manager access is checked for course/live-class workflows; global course categories are restricted to central admins; unassigned branch managers cannot delete global live classes.
- **#86 — Live-class stale-heartbeat capacity:** a participant whose presence lease expired cannot reclaim a seat after another student has taken it.
- **#87 — Attendance/Tracking/Tracing branch security:** attendance QR, Student Tracking and Student Tracing fail closed if Branch Management is absent or the assigned branch is invalid.
- **#88 — Branch selectors:** student and teacher forms show branch managers only their own assigned branch.
- **#89 — Admin plugin recycle-bin acceptance:** admin delete, restore and permanent-delete flows receive explicit feature-test coverage.
- **#90 — Flash Sale and Learn & Earn branch security:** branch managers require an active branch; orphaned flash sales are hidden/immutable when Course Management is disabled; shared Learn & Earn question-bank changes are central-admin-only.
- **#91 — Course media access:** branch-scoped course media access requires the Branch Management integration to be enabled.
- **#92 — Authenticated 300+ live-class load workflow:** adds a staging-only k6 workflow that logs in as distinct enrolled students, joins a live class and sends participant heartbeats.
- **#93 — Admin permanent-delete recovery:** the admin recycle-bin permanent-delete action now creates a private 0700 recovery copy and verifies SHA-256 checksums before removing the archived package.
- **#94 — Marketing global-data security:** global marketing campaigns and coupons are central-admin-only.

Earlier merged CMS/admin work includes Bengali setup wizard, global typography/layout controls, visual page builder, navigation builder, content blocks, header/footer builder, widget/sidebar builder, safe theme-token packages, media library/recycle bin, dashboard widget ordering, role/permission editor, menu editor, site identity and branding.

## CI and plugin acceptance

The repository contains **44 plugin manifests** under `laravel/plugins/modules`.

`AllPluginsIndependentAcceptanceTest` discovers all manifests dynamically and exercises each plugin's provider/manifest validity, no required sibling dependencies, install/enable, checksum-verified ZIP update, disable/re-enable, uninstall/reinstall, settings reset to declared defaults, recycle-bin delete/restore, and permanent-delete recovery snapshot. Dedicated feature suites cover plugin-specific workflows. The admin recycle-bin acceptance tests separately exercise the actual admin routes.

The required CI checks are:
1. Laravel SQLite tests
2. Laravel MySQL 8 tests
3. Browser E2E
4. Composer lockfile/security audit

Each of the recently merged PR heads listed above passed all four checks before merge. The latest main commit recorded at the top of this document has a new main-branch CI run in progress; wait for all four checks on that exact commit before treating it as fully CI-verified.

**Scope limitation:** plugin independence means independently installable/manageable modules inside this Laravel 12 AEC LMS runtime. It does not mean a Laravel plugin can run unchanged on WordPress, CodeIgniter, or an arbitrary non-Laravel LMS. Framework-neutral contracts and host adapters remain separate work.

## WordPress-like admin scope

Supported CMS/admin features include visual page building, reusable content blocks, public navigation/menu management, header/footer layout, widget/sidebar areas, global typography and layout settings, site identity/branding, media library, dashboard widget ordering, role/permission management and plugin lifecycle controls.

The theme package manager accepts validated JSON design-token packages; it is **not** an unrestricted PHP/Blade theme runtime. This project should not be described as full WordPress feature/plugin/theme parity.

## 300+ live-class test status

The LMS now has an authenticated student join/presence workflow with:
- Active enrollment and branch checks
- Configurable LMS seat capacity (default 300)
- Serialized seat allocation
- Join, heartbeat and leave handling
- Two-minute presence expiry
- Capacity re-check when an expired participant attempts to resume via heartbeat

PR #92 adds an authenticated k6 workflow for **300–1,000 distinct pre-provisioned student accounts**. It requires an authorized HTTPS staging LMS, a live class, active enrollments, a unique email pattern, and the GitHub Actions secret `AEC_LIVE_CLASS_TEST_PASSWORD`. It requires configured capacity to be at least the virtual-user count.

**The workflow exists but has not been run against the user's staging site.** A separate provider-side test is still required to prove that the chosen Zoom/Google Meet/Teams licence supports 300 real media participants. The public-page k6 workflow only measures course-page performance and is not evidence of authenticated join capacity.

## Remaining release blockers

| Area | Current evidence | Required before production |
|---|---|---|
| 44 plugin workflows | Dynamic lifecycle suite plus plugin-specific tests; provider APIs are stubbed/simulated in CI. | Finish source/permission/branch-scope review and verify configured real provider sandbox flows. |
| Clean cPanel install | CI builds and verifies a cPanel release package. | Install the exact candidate ZIP on a disposable PHP 8.3+ cPanel staging site using File Manager; verify storage, permissions, cron and queues. |
| Core update/rollback | Automated coverage and runbook exist. | Execute update and rollback rehearsal on isolated cPanel staging, including database/schema compatibility. |
| Backup/restore | Automated backup tests and runbook exist; plugin permanent-delete backup is checksum-verified. | Restore a representative large backup into a separate disposable database and verify private-file checksums and permissions. |
| Authenticated 300+ live class | Workflow and script exist; no staging execution evidence yet. | Run the workflow with at least 300 distinct test students; record join/heartbeat success, p95, seat counts, DB locks and host metrics. |
| External meeting provider | LMS seat admission is enforced. | Separately verify 300 real test participants with the selected provider's plan/licence. |
| Payment providers | Adapter/contract tests exist; live credentials/merchant approvals are not present in CI. | Run official sandbox callback, replay, amount/currency, idempotency and refund tests for each configured provider. |
| SMS/email/marketing | Provider contracts and security checks exist; live account delivery is not verified by CI. | Verify actual SMTP/SMS delivery responses, Meta/Google account connections, consent and privacy settings. |
| Security/privacy | Automated permission, branch-scope and lifecycle regression coverage exists. | Complete staging penetration/regression checks, data retention/privacy review and role-by-role acceptance. |
| Cross-LMS portability | Laravel-native modules and contracts exist. | Build framework-neutral plugin contracts/adapters before claiming compatibility with arbitrary LMS platforms. |

## Safe release policy

- Do not merge stale, highly diverged drafts (#31, #29, #3, #2) wholesale. Reconcile their changes against current main and port only missing fixes onto a fresh branch.
- Do not call a plugin accepted from manifest presence alone; require its feature and security tests.
- Do not claim a production cPanel install, large restore, live payment/SMS/email provider, or 300+ meeting test without evidence from the actual external environment.
- Do not touch the production domain or production database until all external release gates pass.
