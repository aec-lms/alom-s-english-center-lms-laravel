# Central Work Ownership & Status Register

> **Single source of truth for active repository work.** Check this register and the linked issue/PR before starting or changing any work. Do not independently reimplement a claimed scope.

Governance: [Issue #285 — Official Team Instructions](https://github.com/aec-lms/alom-s-english-center-lms-laravel/issues/285)

**Last reconciled:** 2026-11-20  
**Default branch:** `main`  
**Register maintainer:** Repository maintainers (one accountable owner must be named per work item).

## Rules

1. Every implementation, audit, bug fix, refactor, migration, and documentation task must have a GitHub Issue or PR before work begins.
2. Every active item has exactly one **primary owner**. Reviewers and supporting contributors are listed separately; they do not create competing implementations.
3. Search this register, open issues, PRs, and branches before claiming work. If scope overlaps, stop and coordinate with the existing owner.
4. Record scope, exclusions, dependencies, acceptance criteria, branch/PR, status, latest update, and validation evidence.
5. Update this register in the same PR whenever ownership, scope, dependencies, branch, blocker, status, or validation changes.
6. Ownership handoff requires the current owner/maintainer to record the new owner and handoff date here. Never silently take over stale work.
7. A PR is not merge-ready merely because some checks pass. Required CI, independent review, conflict checks, and acceptance criteria must all pass.
8. Do not close, replace, or overwrite another contributor's work as part of deduplication. Reconcile with the owner first.

## Group workflow and overlap arbitration

All contributors and automated work sessions operate from one shared workflow queue; work is coordinated as a group, not as independent parallel task lists.

1. **One task, one implementation owner:** when two or more contributors' work overlaps in outcome, module, files, migration, API, or tests, select exactly one primary implementer. Other contributors must pause overlapping edits and must not create a second implementation.
2. **Coordinator resolves overlap first:** compare the registered scope, active branches, changed files, dependencies, and current progress. Keep the most complete safe implementation as the canonical line of work unless there is a documented technical reason to replace it. Record the decision and owner in this register and the linked issue/PR.
3. **Others help without duplicating:** additional contributors may review, test, document, or take a separately bounded subtask only after the primary owner agrees and the boundary is recorded. Shared-file edits must be sequenced and coordinated.
4. **Claim and sync before every task:** check the shared register and GitHub issues/PRs/branches, announce the intended scope, confirm the owner, then work on the assigned branch. Update status and blockers as the work progresses.
5. **Handoffs are explicit:** if an owner is unavailable or work must transfer, record the current state, commits, remaining tasks, and receiving owner before another contributor continues. No silent takeover or parallel fork.
6. **Conflict response:** stop overlapping changes, do not merge competing PRs, compare implementation and tests, choose one canonical path with the owner/maintainer, then close or narrow redundant work only after coordination.
7. **Quality gates apply to the group:** no merge or production deployment while required CI, independent review, security/regression checks, or acceptance criteria are incomplete.
8. **Finish, hand off, then help another owner:** when a contributor completes their assigned task, they should proactively support the highest-priority open task (especially a failed check, blocker, review, regression test, documentation, or bounded integration). First record their own completion and handoff; then coordinate with the next task's primary owner before helping. Do not silently take over or edit canonical shared files without explicit agreement. Record the helper, bounded scope, and resulting commit/check evidence in the register or linked PR. After that support task is complete, move to the next highest-priority unblocked task.

## Status definitions

| Status | Meaning |
|---|---|
| Proposed | Tracked, not yet claimed |
| Available | Scoped and ready for an owner |
| Claimed | Owner assigned; implementation not yet underway |
| In Progress | Implementation underway |
| Blocked | Explicit blocker recorded |
| In Review | Awaiting independent review |
| CI Failed | One or more required checks failed |
| CI Pending | Required checks have not completed or have not started |
| Ready to Merge | Acceptance criteria, review, and required checks all pass |
| Merged | Merged to the target branch |
| Closed | Closed without merge; reason recorded |

## Active work register

The rows below were refreshed against the repository's open pull requests and exact recorded head checks on 2026-11-19. PR #296 is tracked as the Student Management premium audit workstream. PR author is recorded as the initial primary owner; this is an ownership record, not an assertion that the PR has passed review.

| Task / PR | Workstream and scope | Primary owner | Supporting / reviewer | Dependencies / overlap boundary | Status at reconciliation | Branch | Validation / next action |
|---|---|---|---|---|---|---|---|
| [#318] | Require the current persisted Student role and active User account on all Course Assessment web/API endpoints; serialize answer/start/submit against role/account revocation | `Alomsenglishcenter` | `md-lalchand` and `aec-lms` requested for fresh independent review | Academic & Learning / Course Assessments; only `StudentAssessmentController.php` and its focused authorization test file changed; attempt ownership checks remain after role checks | In Review / SQLite + Browser + Security green; MySQL 8 concurrency proof pending | `security/course-assessment-student-role` via [#318](../pull/318) | Current head `4f7e2605fdf6837b39e8fc3af87c9eabb827e627`. SQLite: **732 passed, 5 skipped, 7,358 assertions**; the 3 new MySQL-only concurrency tests are intentionally skipped on SQLite. Browser E2E and Composer Security Audit passed. Three MySQL 8 interleaving regressions prove (1) mutation locks User + role pivot before concurrent revocation/deactivation, (2) role revocation commits before a waiting answer mutation, and (3) account deactivation commits before a waiting answer mutation. MySQL 8 full run including these tests is still running. Previous formal CHANGES_REQUESTED reviews are on older heads; fresh approval on this SHA is pending. Do not merge until MySQL 8 passes and independent reviewer approval is recorded |
| [#294] | Legacy Flash Sale campaign branch; bounded module implementation transferred to clean-mainline PR #334 on 2026-11-20 | `aec-lms` (Flash Sale module-specific implementation only) | `mdlalchandakand-cell` and `md-lalchand` requested for fresh review; `Alomsenglishcenter` is single owner of shared Browser E2E workflow across #311/#334; #275 owner retains shared commerce integration | Finance & Commerce; PR #334 is the clean-mainline Flash Sale candidate. #275 remains sole editor/owner of CommerceCatalog, CourseCommerceController, EnrollmentController and course/commerce manifests until #335 handoff is accepted; #334 does not touch those shared files. #294's stale branch is not the merge target | Draft / SQLite + Browser + Security green; MySQL and fresh review pending | `feat/flash-sale-premium-campaign-mainline` via [#334](../pull/334); legacy `feat/flash-sale-premium-campaign-engine` retained for reconciliation only | 2026-11-20: #334 current head `5d524e2228b714bfbcced0994b616f84d967ea10`. Exact-head SQLite: **740 passed, 2 skipped, 7,387 assertions**. PHP lint, Composer metadata, Blade compilation, Composer Security Audit and backup checks passed. Browser E2E: **6/6**, including Flash Sale public/Admin specs; 8 screenshot/report files uploaded (`14619627773`, 1,969,884 bytes). cPanel release package verified (**6,764 files**). MySQL 8 remains in progress; no MySQL result yet. Reviewer commit `5d524e2` narrowed the campaign-name locator to its table row. Admin edit uses a labelled native `<details>/<summary>` disclosure with no false dialog/aria-modal role; Playwright verifies keyboard open/close and focus return. Human NVDA/Firefox or NVDA/Chrome smoke test is still required. Public storefront warns that displayed price is only an offer preview because direct EnrollmentController still uses its legacy latest-ID lookup, not the canonical resolver; this parity risk is tracked under #335. No claim of discounted checkout/cart integration is made. Prior formal CHANGES_REQUESTED reviews are anchored to older SHAs; fresh formal approval on current head remains pending. Do not merge until current-head MySQL passes, fresh independent approval is recorded and human screen-reader sign-off is complete |
| [#335] | Flash Sale shared pricing integration: direct enrollment, legacy course cart and Unified Commerce | `Alomsenglishcenter` | `aec-lms` provides quote contract / bounded review; `mdlalchandakand-cell` coordinates independent review | Depends on #334; canonical ownership of `CommerceCatalog.php`, `CourseCommerceController.php`, `EnrollmentController.php` integration and commerce/course-management manifests must be confirmed by the owner; no duplicate edits in #334 | Claimed / awaiting owner implementation | Not yet created | #335 created 2026-11-20 and assigned to canonical shared-commerce owner. Acceptance: optional dependency declarations; direct enrollment resolver; unified catalog/cart snapshots and checkout revalidation; account/profile branch mismatch fail-closed; branch/global precedence and stale-price tests; SQLite + MySQL 8, Browser E2E, Security Audit and independent review. No implementation started and no shared files changed |
| [#293](../pull/293) | Teacher provisioning hardening and teacher directory | `mdlalchandakand-cell` | Maintainer review pending | Student & People Lifecycle; preserve existing teacher/referral/permission ownership; no parallel teacher implementation | Draft; no current-head CI checks found | `feat/teacher-management-premium-hardening` | Current head `7f90927c10e8ddfe5d68627138c8c4c58b25f78e` contains the dual-record omitted-status guard, admin-only explicit account reactivation, actor-attributed audit event and regression tests; the previously reported doubled namespace separator is absent from the current test file. Live compare: 44 ahead / 568 behind `main` (diverged). Requires clean-mainline integration, fresh exact-head CI and independent review; do not merge this branch as-is. |
| [#292](../pull/292) | Private Phonetics speaking recordings and teacher review | `aec-lms` | #288 owner for shared practice view/test integration | Academic & Learning; #288 is merged and owns the dedicated-course practice baseline; #292 owns recording migration/model/controller/routes/admin UI and recording-specific security tests; retain only the agreed recording UI integration in the shared practice view; no #288 test file is in the current #292 diff | CI In Progress; Draft; Not merge-ready | `feature/phonetics-speaking-recordings-review` | Live head `2db83e4cf04bfe06177526dd4959575bab27d0a8`. Live compare against `main`: 66 commits ahead / 461 behind (diverged; merge base `db493c6525f110cd4815024f7a8965c1da8baa22`), contradicting the stale 59 ahead / 0 behind PR description. Browser E2E and Composer Security Audit pass; Laravel SQLite passes; MySQL 8 rerun is in progress (run `44711467925`, job `136230043091`). No independent approval recorded. Keep Draft; do not merge until safe main sync, exact-head full CI (including MySQL 8 and lint/build/package), fresh ownership/diff audit, responsive/accessibility evidence, and independent maintainer approval. |
| [#288](../pull/288) | Dedicated Phonetics course access | `aec-lms` | #292 owner provides recording-specific patch for shared view/test integration | Academic & Learning; canonical owner of `phonetics/Resources/views/learning/practice.blade.php` and `tests/Feature/AlomBrandingLoaderPhoneticsPluginTest.php` for dedicated-course access baseline; #292 owns distinct recording backend/admin files | CI Passed; Open, not Draft | `fix/phonetics-dedicated-course-access` | Latest head `db2799b4fc6cadbed586ee1cdc1291a6dfdc7f65`: Laravel, Security Audit, Browser E2E passed; independent review remains; sequence any recording UI/test integration with #292 owner |
| [#275](../pull/275) | Course gifts and gift vouchers in Unified Commerce Cart | `Alomsenglishcenter` | #294 supplies Flash Sale pricing patch; #283 owns Admin Boosting and shared platform/storefront integration | Finance & Commerce; #275 is canonical owner of `commerce-cart/Services/CommerceCatalog.php`, `course-management/Http/Controllers/CourseCommerceController.php`, `course-management/plugin.json`, gift/cart/payment settlement. #294 owns Flash Sale-specific module files and supplies bounded integration. #275 must remove duplicated Admin Boosting/homepage and already-merged taxonomy changes retained by #283/main | CI Passed; Draft | `feature/unified-gift-commerce-safety` | Latest successful exact-head rerun on `2ea755f4e1985c692f90d9fb032e7ab192fa9bbf`: Laravel SQLite + MySQL 8.0, Browser E2E and Composer audit passed; an earlier MySQL run was cancelled. Keep gift ledger/refund/redemption idempotency tests; scope cleanup/owner confirmation remains before merge |
| [#283](../pull/283) | CMS multi-author contributor and editorial access management | `mdlalchandakand-cell` | #275 owner retains Unified Commerce files | Content & Website CMS; canonical owner of Admin Boosting and its homepage/course-listing promotion integration, Plugin Center UI grouping, PublicHomeController and shared CMS/storefront views. PluginCategoryRegistry and architecture taxonomy are already on `main` and must not be reintroduced by either PR; #275 retains commerce/gift/settlement files | CI Passed; Draft | `feat/cms-contributor-management-v2` | Latest head `0a19c5153df6a246e2061a6c5c379a3be00a4778`: Security Audit, Browser E2E, Laravel passed; independent review and CMS approval-bypass/self-approval tests remain; rebase and remove already-merged taxonomy diff; #275 must remove duplicate Admin Boosting/homepage code |
| [#276](../pull/276) | Advanced responsive campaign slider builder | `aec-lms` | #283 owner integrates shared homepage files | Content & Website CMS; #276 owns slider-specific migration/model/controller/routes/admin UI/manifest/tests; #283 owns final `PublicHomeController.php` and `public/home.blade.php` integration, with slider behavior supplied as a bounded patch | CI Passed; Open, not Draft | `feat/advanced-slider-builder` | Latest recorded head `35a5e9191cd7971d873a2f6a51167b4920d00026`: Laravel SQLite + MySQL, Browser E2E and Composer audit passed; independent review remains; coordinate shared homepage integration with #283 before merge |

| [#295](../pull/295) | Governance: central ownership and status register | `mdlalchandakand-cell` | Independent maintainer review required | Repository-wide coordination; canonical register and group workflow arbitration; no competing ownership tracker | CI Running; Ready for Review | `chore/central-work-ownership-register` | Latest recorded head `5698a953345d9428cc6939dbc57780037da8f6c6` before this update: dependency audit queued and Browser E2E in progress; SQLite/MySQL checks not yet visible. This update triggers fresh exact-head checks; no independent review submitted yet |
| [#297](../pull/297) | Student Management admin edit controls: branch assignment and password reset | `md-lalchand` | Maintainer review pending | Student & People Lifecycle; bounded to student edit view/tests; no Teacher Management, enrollment, commerce, or shared lifecycle controller changes | CI Running; Draft | `fix/student-management-admin-edit-controls` | Latest head `62a3555ee6d8a4c9cfe76343401b2a939a1e2fea` adds a blank-password-preserves-existing-hash regression; previous head had SQLite, Browser E2E and Composer audit green with MySQL running; rerun all exact-head checks and retain independent review gate |

| [#298](../pull/298) | Student lifecycle audit trail and provisioning security | `aec-lms` | #297 owner coordinates UI-only boundaries; maintainer security review | Student & People Lifecycle; #298 sole owner of StudentController and lifecycle audit/provisioning security tests; #297 owns admin edit UI and UI tests | Blocked / CI incomplete; Draft | `fix/student-management-lifecycle-audit` | Head `93d58b3268b2f1d6752dfe312355fe56e55e41f8`: Browser E2E + Composer Security Audit passed; SQLite retry blocked at Chromium installation (Ubuntu mirror HTTP 503); MySQL attempt cancelled. Resolve actor/branch revalidation and locking in store/update/destroy, linked-user fail-closed behavior, audit-table fail-closed behavior, and transaction-bound audit writes; rerun full exact-head SQLite + MySQL. |

## Confirmed shared-file ownership decisions

The following boundaries were confirmed from exact changed-file lists and patch comparisons, then posted to affected PR conversations on 2026-11-19. A PR author must coordinate a handoff before changing another owner's canonical shared files.

| Shared area / files | One canonical owner | Other PR / contributor boundary |
|---|---|---|
| Phonetics course access baseline: `phonetics/Resources/views/learning/practice.blade.php`, `tests/Feature/AlomBrandingLoaderPhoneticsPluginTest.php` | #288 | #292 owns recording backend/admin-specific files; recording UI/test changes to the shared files are proposed to #288 owner for sequenced integration |
| Unified commerce integration: `commerce-cart/Services/CommerceCatalog.php`, `course-management/Http/Controllers/CourseCommerceController.php`, `course-management/plugin.json` | #275 | #294 owns Flash Sale module-specific files and supplies a bounded pricing integration patch for #275 to apply |
| CMS/marketing shared files: `PublicHomeController.php`, `public/home.blade.php`, Admin Boosting module files, Plugin Center UI grouping, shared homepage/storefront views | #283 | #283 is the sole final integrator for shared homepage/controller and promotion UI; #276 supplies bounded slider integration patch; #275 removes its identical Admin Boosting/homepage changes. PluginCategoryRegistry and architecture taxonomy are already on `main` and neither PR should reintroduce them |

## Work not yet represented by an open PR

New work must be created as an issue and added here **before** implementation. Do not treat a broad roadmap item as permission for multiple people to build overlapping modules.

| Task / Issue | Workstream | Primary owner | Status | Scope / acceptance criteria | Dependencies / overlap |
|---|---|---|---|---|---|
| [#296](../issues/296) | Student Management premium audit and upgrade | `md-lalchand` | Maintainer review pending | Audit existing student profile, admission, enrollment, dashboard, analytics, attendance, guardian, finance, referral, certificates, communication, lifecycle, recycle-bin, security, and import/export capabilities; extend existing modules rather than duplicating them | Coordinate with Teacher Management #293, enrollment, unified commerce #275, referral/ledger, guardian, and recycle-bin owners; no edits to #293-owned files without handoff |
| To be created | Ownership register automation / CI guard | Unassigned | Proposed | Validate required register fields, unique task IDs, exactly one primary owner, valid statuses, and PR linkage; report unregistered PR work without silently blocking unrelated CI | This register is the canonical source; do not create another ownership registry |

## Update template

Copy this row format when adding work:

| Task / PR | Workstream and scope | Primary owner | Supporting / reviewer | Dependencies / overlap boundary | Status | Branch | Validation / next action |
|---|---|---|---|---|---|---|---|
| [#NNN](URL) | Clear, bounded scope | `github-login` | Names / roles | Related task IDs and boundaries | Claimed | `feature/branch` | Tests run, result, remaining work |

## Reconciliation log

- **2026-11-19:** Group workflow and overlap-arbitration rules added. Patch-level comparison confirmed #275/#283 contain identical Admin Boosting and homepage-promotion edits; #283 retains those files while #275 removes its duplicate. A further file-level overlap was found between #276 and #283 in `PublicHomeController.php` and `public/home.blade.php`; #283 is the single shared-file integrator and #276 supplies slider-specific changes as a bounded patch. PluginCategoryRegistry/architecture taxonomy are already on `main` and must be removed from both PR diffs. #275 owns shared Unified Commerce files; #294 provides Flash Sale integration patch. #288 owns Phonetics practice view/test baseline; #292 owns distinct recording backend/admin work and is stacked behind #288. Exact-head CI: #294/#288/#283 passed; #293 Laravel failed from PHP syntax error at TeacherProfileValidationTest.php:86; #275 Laravel cancelled; #292 SQLite/MySQL checks in progress; #297 Laravel in progress; #295 checks rerun after each register update. No merge before scope cleanup, review and all required checks. Refresh before assigning new work.


### 2026-11-19 live reconciliation addendum (supersedes stale rows above)

A fresh GitHub changed-file audit found **15 identical changed paths between #275 and #283**. The canonical owner remains #283 for taxonomy/Plugin Center/Admin Boosting/homepage/shared storefront files; #275 must remove those duplicate paths and retain only Unified Commerce/gift/payment-settlement scope. This includes `PluginCategoryRegistry.php`, `PluginCenterController.php`, `PublicHomeController.php`, Admin Boosting module files, shared `admin/plugins`, `public/courses`, `public/home` views, and associated tests/docs. The taxonomy registry is already on `main`; do not reintroduce it.

#294's bounded `CommerceCatalog::course()` integration with `FlashSalePricingService::quote()` belongs in #275. #294 must retain Flash Sale module-specific ownership and remove its duplicate `CommerceCatalog.php` change after an explicit handoff. #294 also has a confirmed migration rollback data-loss risk: `down()` must not drop pre-existing columns/data such as `branch_id`; add a pre-existing-column + sentinel-data rollback regression and track only schema objects created by `up()`.

#292's live changed-file list was rechecked on 2026-11-20: it contains 10 recording-related files and does not include #288's `AlomBrandingLoaderPhoneticsPluginTest.php`. #288 is merged and remains the baseline owner; #292 retains only the agreed recording UI integration in the shared practice view plus recording-specific backend/admin files and security tests. The #292 branch is materially diverged from `main` (66 ahead / 461 behind), so safe synchronization and a fresh diff audit remain mandatory; do not describe it as merely stacked behind #288.

#293's earlier PHP syntax error has been corrected; latest reported Laravel job failed at Chromium dependency installation (Ubuntu package mirror HTTP 503), while MySQL was incomplete. Do not report the old syntax failure as current. #297 and #283 have no submitted independent APPROVED review in the current review list; passing CI alone does not make either merge-ready. #295's own SQLite passed, MySQL remained in progress at last recorded check. #298 is separately registered above and must not overlap #297's UI scope.


---

## Mandatory premium product-quality standard — applies to every workstream

The project owner has explicitly directed that **a bug fix or technically functioning feature alone is not acceptable**. Every user-facing feature and screen must be delivered at a premium, polished, attractive and cohesive product standard.

1. **Visual design:** strong hierarchy, refined typography, spacing, alignment, color contrast, consistent components, polished responsive layouts and purposeful visual states. No placeholder-looking, generic, unfinished or inconsistent screens.
2. **Interaction design:** intuitive navigation and primary actions; complete loading, empty, success, error and validation states; no dead buttons or misleading status; accessible keyboard/focus behavior and touch-friendly controls.
3. **Responsive acceptance:** inspect and verify desktop, tablet and mobile layouts, including realistic content lengths and edge cases.
4. **Accessibility and usability:** semantic controls, labels, readable contrast, focus visibility, keyboard operation, reduced-motion support where animation is used, and clear feedback.
5. **Design-system consistency:** reuse the LMS's established design language and shared components. Obtain the canonical owner's agreement before editing shared UI/components.
6. **Evidence:** PR description must state the user journeys tested, visual checks performed at desktop/tablet/mobile, accessibility checks, and attach screenshots or browser evidence where practical.
7. **Technical quality remains mandatory:** security, authorization, data integrity, performance, migrations/rollback safety, automated tests and exact-head CI are required. Green CI does not replace visual review; attractive UI does not replace correctness.
8. **Backend-only tasks:** where no user-facing surface exists, apply the premium standard to API contracts, operational clarity, documentation, observability and developer experience.
9. **Completion gate:** include a checklist for functionality, visual polish (where applicable), responsive behavior, accessibility, edge cases, security and exact-head CI. Keep the PR Draft until all applicable checks and independent review pass.

This standard applies to every current and future PR, issue, plugin, admin panel, student/teacher/guardian workflow, CMS, commerce flow, builder, public page and API. One primary owner per task remains mandatory; helpers must agree and record a bounded handoff before contributing.

### 2026-11-19 addendum — new Exam Management workstreams

- [#310](../pull/310) — immutable Exam Management attempt snapshots; primary owner `mdlalchandakand-cell`. Treat this as the canonical implementation candidate only after comparing the overlapping #311 diff; resolve ownership before parallel changes.
- [#311](../pull/311) — immutable Exam Management attempt snapshots; primary owner `Alomsenglishcenter`. This materially overlaps #310 in the same module/outcome. Both owners must pause overlapping edits and agree one canonical implementation with a recorded bounded handoff; do not merge both implementations.
- Both #310 and #311 must satisfy the premium product-quality standard above, including polished student exam-taking and admin configuration experiences where those surfaces are in scope, responsive and accessible states, and evidence of visual review. Assessment integrity, immutable scoring correctness and migration safety remain non-negotiable.


### 2026-11-19 live CI and overlap review (latest observed heads)

- **#298** head `46c437bf7b1d8c86047a9d326985b586ceb9d3d1`: Laravel SQLite job passed, including PHP lint, Blade compilation, feature tests and cPanel package checks; MySQL 8.0 job is still running. Browser E2E and Composer Security Audit passed. No independent review recorded. Keep Draft until MySQL and review finish.
- **#310** head `4705ed8a87a326bbc7b3ef2cb0e99b7e85dcdafa`: Laravel SQLite job passed; MySQL 8.0 is still running; Browser E2E and Composer Security Audit passed. A previous P1 mobile API mapper type error was corrected and verified by review comment. No independent review recorded.
- **#311** head `e896a4d8705b5205a382d281e9f2aa43c723c1ba`: Browser E2E and Composer Security Audit passed; Laravel SQLite failed **1 test / 667 passed / 6,808 assertions** in `ExamAttemptSnapshotTest::test_legacy_pending_attempt_without_snapshot_is_not_silently_regraded` at line 142. HTTP 409 is returned, but the expected explanatory message is absent from the response body. Owner asked to fix the user-visible error contract and preserve the fail-closed state; MySQL job was still running at observation. Keep Draft.
- **Exam Management overlap arbitration:** #310 and #311 both change the same four files: ExamController, ExamAttempt, attempt-snapshot migration and ExamAttemptSnapshotTest. They are competing implementations of one task and must not both merge. Technical review recommends #311's fail-closed policy for legacy pending attempts over #310's migration-time backfill from current mutable question/exam state, because the latter cannot prove the historical questions a student originally saw. Proposed canonical path: #311, conditional on the failing UX/test fix, full exact-head CI and independent assessment-integrity review. #310 owner should pause overlapping edits and coordinate an explicit bounded review/handoff with #311 owner. No silent takeover; record owner agreement before closing/narrowing either PR.
- **#275, #283, #292, #293, #297, #294:** observed current recorded heads each have Laravel, Browser E2E and Composer Security Audit workflows completed successfully. This does not waive their outstanding independent-review, scope-cleanup, migration-safety, financial or other PR-specific release gates. #294 still requires safe rollback evidence; #275 still has refund/wallet/staging blockers; #292 still requires shared-view ownership cleanup and reviewer sign-off; #293 and #297 require independent review.
- **Historical 2026-11-19 status note:** #275, #283, #292, #293, #297 and #294 had recorded CI results at that observation point; those results are not current-head evidence. For #292, the 2026-11-20 live audit supersedes the earlier shared-view cleanup note: the current diff excludes #288's test file, but safe branch synchronization, exact-head CI, responsive/accessibility evidence and independent reviewer approval remain outstanding. Other PR-specific release gates remain as recorded in their canonical PRs and must be refreshed before merge.
- **#295** current register head is `36da65c641f3d1cd6813c83772b28e8e257e6625`; Browser E2E and Composer Security Audit passed; Laravel job remains in progress. No independent review recorded.

All statuses above are point-in-time observations and must be refreshed against the exact head before merge.


### 2026-11-19 — merge authorization and owner notification rule

The project owner has directed: **when a task is genuinely complete, notify the project owner and recommend merge only after every repository rule and acceptance gate is satisfied.** This is not permission to merge early or bypass review.

Before recommending merge, the primary owner must verify and record all of the following against the exact current PR head:

1. Scope and ownership are clean: no unresolved duplicate implementation, shared-file conflict, unapproved handoff, or overlapping PR; dependent work is integrated by the canonical owner.
2. Functionality and regression coverage are complete, including edge cases, authorization/branch isolation, data integrity, migration rollback and upgrade safety where applicable.
3. Premium product-quality review is complete for user-facing work: polished design, complete interactions, responsive desktop/tablet/mobile behavior, accessibility and visual evidence/screenshots where practical.
4. All required CI checks are green on the exact current head (Laravel SQLite, MySQL 8, Browser E2E, Composer Security Audit, lint/build/package checks where applicable). Cancelled, skipped, pending, stale-head or unrelated-run results are not passes.
5. Independent review by a different maintainer is recorded and all blocking review comments are resolved. Author self-review or a COMMENTED review is not an approval.
6. PR body, tests, docs and this register accurately reflect the final scope, owner, dependencies and verification evidence.

**Notification flow:** when all gates pass, the primary owner posts a concise completion note on the PR with exact head SHA, CI links/results, independent reviewer, screenshots/evidence and any accepted non-blocking follow-ups; then notifies the project owner that the PR is ready to merge. The project owner/authorized maintainer performs or explicitly authorizes the merge using repository policy (required approvals, branch protection and merge queue if enabled). If any gate is unmet, keep the PR Draft/open and report the specific blocker—never claim complete or recommend merge.


---

## 2026-11-20 current-mainline pull-request reconciliation

**Checkpoint main:** `0f3aad9cc469d1abff7fcb7efcd6610ba3fbfc30` (merged commit for PR #331).  
**Purpose:** supersede stale PR/head observations above. All statuses below are point-in-time observations and must be refreshed against the exact SHA before merge.

### Canonical PRs and supersession

| Workstream | Canonical candidate | Legacy / duplicate PR | Current exact-head status and remaining gate |
|---|---|---|---|
| Course Assessment student role/account authorization | [#346](../pull/346), owner `Alomsenglishcenter`; branch `security/course-assessment-student-role-mainline` | [#318](../pull/318), legacy candidate | #346 exact head `11dd0dcbb9e5f217c8d3f3f5b20511c4631f3a26`; SQLite/Laravel, Browser E2E and Composer Security Audit passed; MySQL 8 is still running. Three MySQL-only two-session tests cover mutation-first, role-revocation-first and account-deactivation-first. Fresh independent review and MySQL completion required. Do not merge legacy #318 and #346 together. |
| Flash Sale premium campaign engine | [#347](../pull/347), owner `Alomsenglishcenter`; branch `feat/flash-sale-premium-campaign-mainline-v2` | [#334](../pull/334), legacy candidate | #347 exact head `63a79b6fe9f8606c255c105589fc0756484b75f1`; SQLite/Laravel, Browser E2E and Composer Security Audit passed; MySQL 8 is still running. Fresh independent review, human NVDA/Firefox or NVDA/Chrome smoke test, and authorized ruleset enablement remain required. Shared direct-enrollment/Unified Commerce pricing remains separately owned under #335/#275. Do not merge #334 and #347 together. |
| Red-Team open-issue inventory | [#349](../pull/349), owner `Alomsenglishcenter`; branch `docs/red-team-open-issues-mainline` | [#315](../pull/315), legacy candidate | #349 exact head `7acd8b1f00c0217152d2cf1b8f9c1b63c2d36f2d`; Laravel including MySQL 8.0, Browser E2E and Composer Security Audit all passed. This inventory does not implement or close findings. Fresh independent review and authorized ruleset enablement required. Do not merge #315 and #349 together. |
| 60-plugin acceptance matrix | [#350](../pull/350), owner `Alomsenglishcenter`; branch `docs/plugin-acceptance-matrix-60-mainline` | [#320](../pull/320), legacy candidate | #350 exact head `bd39d771d886845fa5fba785df677a6f1f28a7d8`; Laravel including MySQL 8.0, Browser E2E and Composer Security Audit all passed. The corrected snapshot table has 60 unique plugin slugs and 636 Git blob files; directory tree objects are excluded. This is inventory/evidence documentation, not functional certification. Fresh independent review and authorized ruleset enablement required. Do not merge #320 and #350 together. |
| Exam Management immutable attempt snapshot / eligibility | [#343](../pull/343), owner `mdlalchandakand-cell`; branch `security/exam-attempt-snapshot-mainline` | [#311](../pull/311), superseded legacy candidate | #343 is based on current `main` at `0f3aad9cc469d1abff7fcb7efcd6610ba3fbfc30` (6 commits ahead / 0 behind). Exact head `f5a725f896d1f0a4925c1efab7596f4ffd9e4824`: Laravel SQLite + MySQL 8.0, Browser E2E and Composer Security Audit all passed. Current code rechecks the locked User active state and Student role pivot; sequential regressions cover deactivation and role revocation. Dedicated MySQL account/role-revocation interleaving tests and fresh independent review remain outstanding. Do not merge #311 and #343 together. |
| Invoice-wide gateway payment-session reservations | [#341](../pull/341), owner `mdlalchandakand-cell`; branch `security/invoice-payment-session-reservation-mainline` | [#326](../pull/326), superseded legacy candidate | #341 is based on current `main` at `0f3aad9cc469d1abff7fcb7efcd6610ba3fbfc30` (1 commit ahead / 0 behind). Exact head `06e709d3fc2ac22ca6345ff9c7d830f3fb97618a`: Laravel SQLite + MySQL 8.0, Browser E2E and Composer Security Audit all passed. Fresh independent financial/security approval is still required. Do not merge #326 and #341 together. |

### Legacy Flash Sale integration boundary

- [#335](../issues/335) remains the separate direct-enrollment, legacy-cart and Unified Commerce quote integration workstream. #334/#347 do not edit #275-owned shared commerce files and do not claim discounted checkout integration.
- The #334 prior head `5d524e2228b714bfbcced0994b616f84d967ea10` passed SQLite **740/7,387**, MySQL 8 **742/7,413**, Browser E2E **6/6**, Composer Security Audit, PHP lint/metadata/Blade/backup checks and 6,764-file cPanel package verification. These are historical results for #334's old base, not evidence for new #347.

### Repository branch-protection blocker

GitHub repository ruleset **LMS** (ID `27607063`) was rechecked on 2026-11-20:
- `enforcement: disabled`
- `required_approving_review_count: 0`
- `required_status_checks: []`

Therefore review/CI are not currently enforced by this ruleset. An authorized repository administrator must enable the ruleset and configure the intended approval and required CI checks before any PR is treated as merge-ready. This integration cannot change the ruleset; a 403 was previously returned for branch-protection administration. No PR should be merged by bypassing this gate.

### PR #331 reconciliation

PR #331 was merged into main as commit `0f3aad9cc469d1abff7fcb7efcd6610ba3fbfc30` on 2026-11-20. This current-main addendum replaces the earlier point-in-time status entries above; no historical CI/review result is carried forward to a different head.


### Phonetics private speaking recordings — PR #292 / ownership-doc reconciliation

| Item | Current verified state (2026-11-20) |
|---|---|
| Feature PR | [#292](../pull/292), head `2db83e4cf04bfe06177526dd4959575bab27d0a8`, branch `feature/phonetics-speaking-recordings-review`, Draft; 10 recording-related changed paths. |
| Shared baseline owner | Merged [#288](../pull/288) owns the dedicated-course Phonetics practice baseline and its baseline tests. #292 owns the recording migration/model/controller/routes, recording admin views, recorder/history integration, and recording-specific feature/security tests. The current #292 changed-file list does not include #288's branding-loader test. |
| Exact-head CI | Laravel SQLite, Laravel MySQL 8, Browser E2E, and Composer Security Audit were verified green for the recorded #292 head. These results do not transfer to a later head after synchronization. |
| Mainline divergence | The last live compare recorded #292 as 66 commits ahead / 461 behind `main`, merge base `db493c6525f110cd4815024f7a8965c1da8baa22`. The feature diff overlaps current-main shared files; wholesale copying of the old practice view, routes, or manifest risks losing newer mainline changes. A conflict-aware sync and fresh 10-path diff/ownership audit are mandatory. |
| Security/test review | Source audit recorded self-review denial, reviewer permission revalidation under deterministic locks, branch/course scope rechecks, and private audio response handling. The MySQL concurrency regression test is present; its synchronous listener/lock-timeout orchestration remains a test-quality caveat and should be independently reviewed rather than treated as proof of all possible race schedules. |
| Independent approval | No independent APPROVED review was present at the last review query. Request fresh maintainer review after the synchronized candidate is ready. |
| Documentation follow-up | [#348](../pull/348) is a two-commit docs-only reconciliation of #292's ownership/status, but this current-mainline addendum records the same feature boundary in the broader current-main register. Do not merge both documentation PRs if they carry duplicate register content; compare the final diffs and retain one canonical update. |

**Required next steps:** an authorized branch owner must perform the conflict-aware integration of #292 onto current `main` (or explicitly authorize a new integration branch), preserve all unrelated mainline changes, rerun the full exact-head matrix (SQLite, MySQL 8, Browser E2E, Composer Audit, lint/Blade/build/package), attach responsive/accessibility evidence, and obtain independent maintainer approval. Keep #292 Draft until every gate is met. The GitHub connector available to this workflow exposes file/branch-pointer operations but no conflict-aware merge/rebase/cherry-pick operation; no unsafe replacement of shared files or force-update is authorized.


## 2026-11-20 exact-head CI refresh (16:55 Bangladesh time)

This refresh supersedes stale “running” notes above only where an exact-head workflow result is explicitly listed. It does not grant merge approval or mark a workstream Done.

| Candidate | Exact head | Current CI evidence | Remaining gate |
|---|---|---|---|
| [#351](../pull/351) ownership register | `8525c1fe455c824a5f4fe93cb4fe9053d5a95d41` | Laravel incl. MySQL 8.0, Browser E2E, Composer Security Audit: **passed** on this prior head; a later documentation commit now requires fresh exact-head CI | Independent review; authorized LMS ruleset configuration |
| [#350](../pull/350) plugin matrix | `bd39d771d886845fa5fba785df677a6f1f28a7d8` | Laravel incl. MySQL 8.0, Browser E2E, Composer Security Audit: **passed** | Independent review; ruleset configuration |
| [#349](../pull/349) red-team inventory | `7acd8b1f00c0217152d2cf1b8f9c1b63c2d36f2d` | Laravel incl. MySQL 8.0, Browser E2E, Composer Security Audit: **passed** | Independent review; inventory itself closes no findings |
| [#347](../pull/347) Flash Sale | `63a79b6fe9f8606c255c105589fc0756484b75f1` | SQLite/Laravel, Browser E2E, Composer: **passed**; MySQL 8.0: **in progress** | MySQL, independent review, human screen-reader smoke test, ruleset |
| [#346](../pull/346) Course Assessment student authorization | `11dd0dcbb9e5f217c8d3f3f5b20511c4631f3a26` | SQLite/Laravel, Browser E2E, Composer: **passed**; MySQL 8.0: **in progress** | MySQL, independent review, ruleset |
| [#345](../pull/345) teacher grading authorization | `900513e88ee7a7682dd8b5a81d33fc3af8fd75ac` | Browser E2E, Composer: **passed**; Laravel/MySQL 8.0: **in progress** | MySQL completion and independent review of account/role lock races; overlaps #319 controller/test scope |
| [#344](../pull/344) live-class branch consistency | `4ab33f9f3b649ed88044b27f2eb3209f19deca4f` | Browser E2E, Composer: **passed**; Laravel/MySQL 8.0: **in progress** | MySQL completion and independent review; coordinate with #312 live-class entitlement work |
| [#343](../pull/343) exam snapshots | `f5a725f896d1f0a4925c1efab7596f4ffd9e4824` | Laravel incl. MySQL 8.0, Browser E2E, Composer: **passed** | MySQL account/role-revocation race coverage and fresh independent review |
| [#342](../pull/342) provider-confirmed refunds | `5a86bae6b8dde8863500b1ce3769acf840735761` | Composer: **passed**; Laravel and Browser E2E: **in progress** | Exact-head CI, provider identity-binding review, sandbox merchant acceptance; refunds remain disabled by default |
| [#341](../pull/341) invoice-wide gateway reservations | `06e709d3fc2ac22ca6345ff9c7d830f3fb97618a` | Laravel incl. MySQL 8.0, Browser E2E, Composer: **passed** | Fresh independent financial/security approval |
| [#348](../pull/348) Phonetics ownership reconciliation | `fd2e22c0d5656cbdf10156a70872cefb7cf0e2f2` | Browser E2E, Composer: **passed**; Laravel: **in progress** | Exact-head Laravel completion and independent review |
| [#292](../pull/292) Phonetics recordings | `2db83e4cf04bfe06177526dd4959575bab27d0a8` | Laravel, Browser E2E, Composer: **passed** | Independent review; preserve #288 shared-file ownership boundary |

The live repository audit found 26 open PRs at this snapshot. Legacy/overlapping candidates remain open and must not be merged alongside their clean-mainline replacements. In particular: #318/#346, #311/#343, #326/#341, #315/#349, #320/#350 and #334/#347 are no-double-merge pairs. #293's account-reactivation finding appears addressed in current branch source/tests, but its 44-ahead / 568-behind divergence and missing exact-head CI keep it unmergeable; #336 and #294 are also reported unmergeable. The LMS ruleset remains disabled with zero required approvals/status checks; only an authorized repository admin can enable and configure it.


## 2026-11-20 coordinated security and payment follow-up

This addendum supersedes earlier status rows only for the exact heads below. It records work performed after the prior 16:55 Bangladesh-time snapshot; it is not merge approval.

| PR | Exact head | Verified result / new work | Remaining gate |
|---|---|---|---|
| [#341](../pull/341) invoice-wide gateway reservations | `be14c1f6c04ceba37d47cbe2861f24b85efc2943` | Added invoice+gateway-scoped 32-character provider idempotency keys for Stripe and PayPal. This prevents a client reusing the same key across invoices from causing the provider to replay another invoice's session. Added two-invoice integration regressions for both gateways. Composer passes; fresh Laravel SQLite/MySQL and Browser runs are in progress. | Exact-head full CI and independent financial/security review. |
| [#342](../pull/342) provider-confirmed refunds | `8258bba912ce8d686564553c610b0211642c569e` | Stripe, PayPal and SSLCOMMERZ provider response identity checks are present with negative mismatch regressions. Corrected Stripe form-metadata fixture parsing, including the pending-refund reconciliation fixture. Laravel SQLite, Browser E2E and Composer pass on this head; MySQL 8 is still running. | MySQL 8, fresh independent finance/security approval and official provider sandbox acceptance. Refunds remain disabled by default. |
| [#343](../pull/343) exam attempt snapshots | `a9248e1bfc7862506a34018bd19b51c2e42b44c6` | Added two MySQL 8 tests proving account deactivation and Student-role revocation writers wait while a protected authenticated exam request holds the User/role lock; denied follow-up submit must leave the attempt unchanged. Laravel SQLite, Browser E2E and Composer pass; MySQL 8 is still running. | Exact-head MySQL 8 and fresh independent assessment-integrity approval. |
| [#344](../pull/344) live-class branch consistency | `445ec89475b17226632022809ebae9f864bea559` | Added heartbeat/account-deactivation MySQL contention proof. Existing tests cover both account/profile branch mismatch directions on join and heartbeat, plus role-pivot, enrollment and paid-entitlement revocation. Laravel SQLite, Browser E2E and Composer pass; MySQL 8 is still running. | Exact-head MySQL 8 and fresh independent review; coordinate shared entitlement ownership with #312. |
| [#345](../pull/345) teacher grading authorization | `900513e88ee7a7682dd8b5a81d33fc3af8fd75ac` | Laravel SQLite, Laravel MySQL 8, Browser E2E and Composer Security Audit are all green on the exact head. | Fresh independent teacher-grading/account/branch authorization review. |
| [#346](../pull/346) Course Assessment student authorization | `11dd0dcbb9e5f217c8d3f3f5b20511c4631f3a26` | Laravel SQLite, Browser E2E and Composer pass. The previously running MySQL 8 job was cancelled; that job has been explicitly rerun and is in progress. | Rerun MySQL 8 result and independent review; do not treat the cancelled attempt as a pass. |
| [#347](../pull/347) Flash Sale clean-mainline v2 | `63a79b6fe9f8606c255c105589fc0756484b75f1` | Laravel SQLite, Browser E2E and Composer pass. The previously running MySQL 8 job was cancelled; that job has been explicitly rerun and is in progress. | Rerun MySQL 8 result, independent review, human screen-reader smoke test and separate #335 shared-commerce pricing integration. |
| [#339](../pull/339) referral payout concurrency | `211f360446bf3d4f9c9141281b2a6f03b10ad5ad` | Laravel SQLite, MySQL 8, Browser E2E and Composer Security Audit are all green. | Independent finance review; do not merge while Draft or review is outstanding. |
| [#332](../pull/332) verified course-cart referral payment | `345c0aa1d843d94bbca1bf4d36733b537feecc9d` | Laravel SQLite, MySQL 8, Browser E2E and Composer Security Audit are all green. | Formal CHANGES_REQUESTED review remains; obtain fresh independent review of locked invoice/buyer/amount/currency/payment binding before merge. |

All listed candidates remain Draft. No branch was merged or deployed. MySQL 8 results are explicitly pending where shown; a green SQLite run is not a substitute for row-lock proof. Repository LMS ruleset remains disabled with zero required approvals/status checks, so authorized repository administration is still required before any merge gate is considered enforced.
