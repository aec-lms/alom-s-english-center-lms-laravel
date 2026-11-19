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
