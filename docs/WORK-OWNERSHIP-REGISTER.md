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
| [#294](../pull/294) | Flash-sale campaign engine and storefront | `md-lalchand` | Maintainer review pending | Finance & Commerce; coordinate with #275 over `commerce-cart/Services/CommerceCatalog.php`, `course-management/Http/Controllers/CourseCommerceController.php`, and `course-management/plugin.json`; do not duplicate checkout orchestration | CI Passed; Draft | `feat/flash-sale-premium-campaign-engine` | Laravel, Security Audit, and Browser E2E passed on recorded head; independent source review and acceptance checks remain |
| [#293](../pull/293) | Teacher provisioning hardening and teacher directory | `mdlalchandakand-cell` | Maintainer review pending | Student & People Lifecycle; preserve existing teacher/referral/permission ownership; no parallel teacher implementation | CI Failed; Draft | `feat/teacher-management-premium-hardening` | Laravel PHP lint and MySQL test jobs both fail because `laravel/tests/Feature/TeacherProfileValidationTest.php:86` contains an unexpected `\\` token (doubled namespace separators in the `DB` facade reference). Fix this syntax error, rerun full CI, then review tests. |
| [#292](../pull/292) | Private Phonetics speaking recordings and teacher review | `aec-lms` | Maintainer review pending | Academic & Learning; coordinate with Phonetics course access #288; recordings/review only, avoid duplicating course access | CI Not Started; Draft | `feature/phonetics-speaking-recordings-review` | No workflow runs found for exact head `6247917b72e4f8ba9737e405015a516a3cb5f8ac`; verify CI trigger, coordinate overlap with #288 in `phonetics/Resources/views/learning/practice.blade.php` and `tests/Feature/AlomBrandingLoaderPhoneticsPluginTest.php`, and review private storage, retention, and teacher/student authorization |
| [#288](../pull/288) | Dedicated Phonetics course access | `aec-lms` | Maintainer review pending | Academic & Learning; coordinate with speaking-recording workflow #292 and avoid overlapping access gates | CI Passed; Open, not Draft | `fix/phonetics-dedicated-course-access` | Laravel, Security Audit, and Browser E2E passed on current head; independent review of authorization regression tests remains |
| [#275](../pull/275) | Course gifts and gift vouchers in Unified Commerce Cart | `Alomsenglishcenter` | Maintainer review pending | Finance & Commerce; unified cart owns orchestration; gift/voucher feature must not create a second cart/checkout | CI Pending (Laravel workflow cancelled/incomplete); Draft | `feature/unified-gift-commerce-safety` | Composer Security Audit and Browser E2E passed on exact head; rerun full Laravel SQLite + MySQL checks. Coordinate overlap with #294 in `commerce-cart/Services/CommerceCatalog.php`, `course-management/Http/Controllers/CourseCommerceController.php`, and `course-management/plugin.json`; validate ledger/refund/redemption idempotency |
| [#283](../pull/283) | CMS multi-author contributor and editorial access management | `mdlalchandakand-cell` | Maintainer review pending | Content & Website CMS; must preserve approval independence and prevent author self-approval/publish bypass | CI Passed; Draft | `feat/cms-contributor-management-v2` | Security Audit, Browser E2E, and Laravel passed on recorded head; independent source review and workflow bypass tests remain |
| [#276](../pull/276) | Advanced responsive campaign slider builder | `aec-lms` | Maintainer review pending | Content & Website CMS; do not duplicate existing slider/content builder | CI Passed; Open, not Draft | `feat/advanced-slider-builder` | Security Audit, Browser E2E, and Laravel passed on recorded head; independent review and acceptance checks remain |

| [#295](../pull/295) | Governance: central ownership and status register | `mdlalchandakand-cell` | Independent maintainer review required | Repository-wide coordination; canonical register only, no competing ownership tracker | CI Pending; Ready for Review | `chore/central-work-ownership-register` | Composer Security Audit passed on exact head `88d2bc912edd8bea52ab0630e60128dc168fc737`; Laravel and Browser E2E are still running; no independent review submitted yet |
| [#297](../pull/297) | Student Management admin edit controls: branch assignment and password reset | `md-lalchand` | Maintainer review pending | Student & People Lifecycle; bounded to student edit view/tests; no Teacher Management, enrollment, commerce, or shared lifecycle controller changes | CI Passed; Draft | `fix/student-management-admin-edit-controls` | Laravel, Browser E2E, and Composer Security Audit all passed on exact head `92b816d8ba69809d3c067f049035bb9ea45e779d`; independent source review and branch authorization/password-reset regression review remain |

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

- **2026-11-19:** Initial register created and reconciled against open PRs. Follow-up reconciliation added #295, #296, and #297; recorded exact-head CI outcomes; documented #275/#294 and #288/#292 shared-file boundaries; and retained the Teacher Management parse error as an owner-action blocker. #297 exact-head checks are green; #295 Laravel/Browser E2E remain in progress; #292 has no workflow runs; #275's Laravel workflow is incomplete because its MySQL job was cancelled. Existing PR authors are initial accountable owners pending confirmation. This is a point-in-time snapshot; refresh it before assigning new overlapping work.
