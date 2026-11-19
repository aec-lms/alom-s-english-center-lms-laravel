# Central Work Ownership & Status Register

> **Single source of truth for active repository work.** Check this register and the linked issue/PR before starting or changing any work. Do not independently reimplement a claimed scope.

Governance: [Issue #285 — Official Team Instructions](https://github.com/aec-lms/alom-s-english-center-lms-laravel/issues/285)

**Last reconciled:** 2026-11-19  
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
| [#294](../pull/294) | Flash-sale campaign engine and storefront | `md-lalchand` | #275 owner integrates bounded Flash Sale quote patch into shared commerce catalog | Finance & Commerce; #294 owns Flash Sale module-specific migration/controller/provider/pricing service/views/manifest/tests; #275 is sole editor/owner of shared CommerceCatalog, CourseCommerceController and course-management manifest integration | CI Passed; Draft | `feat/flash-sale-premium-campaign-engine` | Latest head `6a3ca84b25756923d67a58ca3c81c597dcba19b5`: Laravel, Security Audit, Browser E2E passed; remove/hand off shared-file edits to #275 owner before merge; independent review remains |
| [#293](../pull/293) | Teacher provisioning hardening and teacher directory | `mdlalchandakand-cell` | Maintainer review pending | Student & People Lifecycle; preserve existing teacher/referral/permission ownership; no parallel teacher implementation | CI Failed; Draft | `feat/teacher-management-premium-hardening` | Laravel PHP lint and MySQL test jobs both fail because `laravel/tests/Feature/TeacherProfileValidationTest.php:86` contains an unexpected `\\` token (doubled namespace separators in the `DB` facade reference). Fix this syntax error, rerun full CI, then review tests. |
| [#292](../pull/292) | Private Phonetics speaking recordings and teacher review | `aec-lms` | #288 owner for shared practice view/test integration | Academic & Learning; #288 owns shared practice view/test baseline; #292 owns recording-specific migration/model/controller/routes/admin recording UI and recording-specific security tests; consolidate duplicate branch-review test coverage within #292, shared view/test changes only by agreed handoff | CI Failed / In Progress; Draft | `feature/phonetics-speaking-recordings-review` | Latest head `6adeb03226863d8b8de07e2d570a9c3f419d854e`: PHP namespace syntax is fixed; Laravel SQLite ran 657 tests with one failure at `AlomBrandingLoaderPhoneticsPluginTest.php:377` (403 expected 404 because test user is not enrolled); MySQL still in progress. Enroll the second student in the same course to exercise the owner-scoped 404. Owner plans to consolidate duplicate branch-review tests; keep stacked behind #288 |
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

#292 is based on `main` but still has inherited #288 practice-view/test paths in its changed-file list. Rebase/clean against latest `main`; #288 remains canonical owner of the shared practice baseline, #292 owns recording-specific backend/admin files and tests. Current latest PR metadata must be refreshed before claiming CI completion.

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
