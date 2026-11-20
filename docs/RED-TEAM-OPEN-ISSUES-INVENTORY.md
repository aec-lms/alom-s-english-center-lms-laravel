# Red-Team open-issue inventory and remediation tracker

**Scope:** repository-visible open GitHub issues and canonical implementation PRs reviewed on 2026-11-20.  
**Purpose:** single auditable inventory; this is not a claim that the listed defects are fixed or that the LMS is production-ready.

## Status semantics

- **Confirmed finding:** source-level defect or release blocker is described with concrete evidence in its issue.
- **Needs implementation:** fix and regression coverage remain open.
- **External gate:** cannot be completed by repository code alone; requires owner-provided staging/provider resources.
- **Verification required:** inspect current exact branch/head and tests before deciding whether the finding remains present.

## Open security, integrity and release work

| Issue | Area | Risk / required remediation | Required proof |
|---|---|---|---|
| #242 | Main branch protection | Ruleset enforcement is disabled; require independent review, required CI, thread resolution and verify effective protection. | Admin-confirmed effective rules and a protected-branch test. |
| #285 | Coordination / ownership | Keep one accountable owner per workstream; record canonical branch, overlap, handoff and exact head. | Current ownership register; no overlapping unapproved implementation. |
| #296 | Student Management audit | Complete student lifecycle, branch scope, sensitive-data, upload/export, audit, integration and performance review without duplicating lifecycle modules. | Risk-ranked inventory plus focused regression PRs and exact-head CI. |
| #300 | Plugin acceptance | Acceptance matrix has drifted from current manifests; regenerate from exact target commit and map real tests/limitations per plugin. | Every manifest has exactly one evidence-based row; SQLite/MySQL/browser/security/package checks. |
| #301 | Referral finance | Serialize attribution against first paid checkout; validate commission source/refund evidence, partial refund arithmetic, payout state machine, branch scope and audit history. | Deterministic MySQL 8 concurrency tests; idempotent settlement/refund/payout tests; independent finance review. |
| #302 | Payment sessions | One shared invoice reservation contract across SSLCommerz, Stripe and PayPal; ambiguous sessions remain blocked until provider reconciliation. | MySQL 8 simultaneous checkout tests, same-key idempotency and cross-gateway contract tests. |
| #303 | Online refunds | Keep online refunds fail-closed until provider-confirmed refund adapters and reconciliation exist. | Official provider sandbox evidence, idempotent refund/reversal ledger and reconciliation tests. |
| #304 | Course Assessment teacher branch isolation | Recheck assessment/course branch and teacher assignment inside grading transaction; reject inconsistent branch ownership. | Cross-branch list/write denial including stale assignment rows; MySQL 8 transaction tests. |
| #305 | Course Assessment student role | Apply current student-role/account checks to every web/API endpoint while retaining attempt ownership checks and answer-key secrecy. | Role-revocation web/API tests and active-student regression. |
| #306 | Recycle Bin restore | Restore must not reactivate a user after a later independent security deactivation; separate profile restore from account reactivation and lock linked user row. | Student and teacher delete/deactivate/restore tests, audit and concurrency proof. |
| #307 | Recycle Bin migrations | Audit all recycle-bin migrations; rollback may remove only schema objects created by that migration. | Pre-existing-column/data preservation and clean/partial/repeated up/down tests on SQLite and MySQL 8. |
| #308 | Exam Management | Freeze question/scoring snapshot per attempt; preserve historical submitted results and fail closed for legacy attempts without provable snapshot. | Exact-head CI plus independent review, responsive journey and accessibility evidence. |
| #309 | Live Class entitlement | Recheck enrollment, entitlement and branch within seat-grant/heartbeat transaction; coordinate common lock order with student lifecycle. | Deterministic MySQL 8 revoke/join/heartbeat race tests; leave remains usable. |
| #313 | Protected lesson media | Revalidate current role, active account, non-deleted active student profile and enrollment at issuance and stream/download time. | Old signed URL denied after role/account/profile/enrollment revocation; no media bytes returned. |
| #289 | Spoken English Practice | Obtain reviewed artifact, integrate via plugin lifecycle, prove compatibility and run staging acceptance; do not conflate with Phonetics. | Artifact hash, lifecycle/permission/branch tests, MySQL and staging evidence. |
| #290 | Phonetics speaking practice | Complete explicit microphone consent, private recording lifecycle, provider adapter (no fabricated score), teacher rubric, accessibility and branch isolation. | Upload-abuse, authorization, retention, MySQL and browser tests. |
| #14 | External release gates | Real restore, cPanel install/rollback, official payment sandboxes and authorized 300+ meeting participant validation remain external. | Recorded evidence for gates A–E; mocks/CI are not substitutes. |

## Existing PR workstreams that must remain coordinated

- Student lifecycle #297/#298 and Live Class #312 share authorization and row-lock concerns with #306/#309. A single documented lock order and explicit owner handoff are required before shared-file edits.
- Phonetics #288/#292 share access/recording dependencies. Permission revocation must be serialized with the final review write.
- Gift Commerce #275 and Flash Sale #294 share catalog/pricing/payment paths; only one canonical implementation may own shared files.
- Protected lesson #314 and issue #313 describe closely related authorization requirements; reconcile into one canonical guard/test suite rather than duplicate fixes.
- Exam #311 implements #308; do not reimplement its snapshot logic in a parallel branch.
- Course Assessment #304/#305 should use bounded controller/test ownership and not alter shared enrollment/payment modules.
- Referral #301 and payment reservation/refund #302/#303 must coordinate with the payment settlement owner.
- Recycle Bin restore #317 is merged; its migration follow-up remains tracked separately in #322. Do not reopen or duplicate the completed restore implementation.

## Canonical PR and exact-head CI snapshot (2026-11-20)

This is a point-in-time snapshot, not a merge approval. The workflow names below are the repository's Laravel, Browser E2E and Composer Security Audit checks.

| Workstream / PR | Exact head (short SHA) | Current state | Exact-head workflow result / remaining gate |
|---|---|---|---|
| #317 Recycle Bin restore | `adfcce0a4784` | Merged | Closed as merged; preserve its implementation and regression history. |
| #325 Referral finance | `1b9aa1baeaf` | Open, Draft | Laravel, Browser E2E and Composer Security Audit passed. Still requires independent finance review and combined settlement/attribution MySQL concurrency proof with #275; provider refund approval/payout remains blocked on #303 evidence. |
| #319 Teacher grading branch isolation | `ffe20fdf6e78` | Open, Draft | Laravel, Browser E2E and Composer Security Audit passed. Independent review/approval remains required. |
| #318 Assessment student role | `fecd51baaf5e` | Open, not Draft | Browser E2E and Composer Security Audit passed; Laravel workflow was cancelled. Review feedback says `requireStudent()` is not yet consistently applied across all web/API endpoints and inactive-account policy needs resolution. Do not merge until fixed, rerun and independently reviewed. |
| #322 Recycle Bin migration rollback | `7b7f42cff120` | Open, not Draft | Laravel, Browser E2E and Composer Security Audit passed on this head. Independent review and ownership/merge gates remain. |
| #320 Plugin acceptance matrix | `4cf27ceda217` | Open, Draft | Laravel, Browser E2E and Composer Security Audit passed on this head. Confirm the matrix is generated from the exact target manifest set and independently reviewed. |
| #311 Exam attempt snapshot | `6f5a48cf5ce8` | Open, Draft | Laravel and Composer Security Audit passed; Browser E2E failed. Review feedback also requires an exam-taking browser journey and accessibility evidence, not merely the Attendance journey. |
| #292 Phonetics recordings | `1ed2dc6034a5` | Open, Draft | Browser E2E and Composer Security Audit passed; Laravel failed. Known MySQL failure: `PhoneticsSpeakingRecordingSecurityTest` permission-revocation/concurrency case did not retain the expected session status. Fix the authorization/locking path and rerun the complete matrix. |
| #312 Live Class entitlement | `896c9a25ec87` | Open, Draft | Browser E2E and Composer Security Audit passed; Laravel failed on SQLite and MySQL because `Tests\\Feature\\DB` was not found in the concurrency test. Add the correct DB facade import/reference and rerun both database suites. |
| #275 Gift Commerce | `faa48d13621c` | Open, Draft | Laravel, Browser E2E and Composer Security Audit passed. Combined MySQL concurrency proof with #325 and ownership coordination on shared settlement paths remain required. |

**Interpretation:** green workflows are necessary but not sufficient. Draft state, unresolved review feedback, missing feature-specific browser/accessibility evidence, independent review, exact-head rechecks and external provider gates still block merge/release where listed. Recheck every head before acting because branch updates invalidate this snapshot.

## Release decision

**BLOCKED pending evidence.** No production deployment or final customer ZIP should be declared ready while any P1 security/financial finding or external gate A–E remains open. Do not merge a PR merely because it is green on SQLite; require exact-head MySQL 8, Browser E2E, Composer security, lint/Blade/package checks where applicable, and independent review.

## Audit limitation

This tracker records the repository-visible open issue set and known findings. It does not replace a full source audit of every controller, route, API, migration, plugin manifest, export, background job or provider callback. New findings discovered during those reviews must be added with reproducible evidence and a non-overlapping owner.
