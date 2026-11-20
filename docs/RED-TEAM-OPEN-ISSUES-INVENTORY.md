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
| #311 Exam attempt snapshot | `aad0caa89374` | Open, Draft | Laravel (SQLite + MySQL) and Composer Security Audit passed; Browser E2E failed because final `POST /exams/1/attempt` returned HTTP 429 after autosaves. Generic authenticated-user throttle buckets may be shared despite different numeric limits. Implement separate named per-student/per-exam submit and autosave limiters; prove autosaves do not exhaust submit quota and repeated submits remain limited; rerun browser + full CI. |
| #292 Phonetics recordings | `1ed2dc6034a5` | Open, Draft | Browser E2E and Composer Security Audit passed; SQLite Laravel passed; MySQL Laravel failed at `PhoneticsSpeakingRecordingSecurityTest.php:452` because the expected session `status` key was absent. Log does not expose response status/body. Inspect actual response and ensure the two-connection lock test is not confounded by an outer test transaction/uncommitted fixtures; prove both race orderings and rerun full matrix. |
| #312 Live Class entitlement | `896c9a25ec87` | Open, Draft | Browser E2E and Composer Security Audit passed; Laravel failed on SQLite and MySQL at `LiveClassCapacityTest.php:349` because `DB` resolves as `Tests\\Feature\\DB`. Import `Illuminate\\Support\\Facades\\DB` (or fully qualify it), then rerun both database suites. |
| #275 Gift Commerce | `faa48d13621c` | Open, Draft | Laravel, Browser E2E and Composer Security Audit passed. Combined MySQL concurrency proof with #325 and ownership coordination on shared settlement paths remain required. |

**Interpretation:** green workflows are necessary but not sufficient. Draft state, unresolved review feedback, missing feature-specific browser/accessibility evidence, independent review, exact-head rechecks and external provider gates still block merge/release where listed. Recheck every head before acting because branch updates invalidate this snapshot.

| #315 Red-Team inventory | `68976434f6b3` | Open, Draft | Documentation-only inventory refresh; Laravel, Browser E2E and Composer Security Audit all passed on this exact head. It closes no application findings and is not merge/release approval. |

## Release decision

**BLOCKED pending evidence.** No production deployment or final customer ZIP should be declared ready while any P1 security/financial finding or external gate A–E remains open. Do not merge a PR merely because it is green on SQLite; require exact-head MySQL 8, Browser E2E, Composer security, lint/Blade/package checks where applicable, and independent review.

## Audit limitation

This tracker records the repository-visible open issue set and known findings. It does not replace a full source audit of every controller, route, API, migration, plugin manifest, export, background job or provider callback. New findings discovered during those reviews must be added with reproducible evidence and a non-overlapping owner.

## Maintainer addendum — current coordinated PR state (2026-11-20)

The historical snapshot above is retained for audit history. The following is the newer exact-head view and supersedes older rows for these workstreams. Recheck before any merge because CI and branch heads can move.

| PR / issue | Current exact head | State | Current gate / ownership |
|---|---|---|---|
| #311 / #308 Exam attempt snapshots | 2247159a16880b611d1f6e41828867252cf82096 | Open, Draft; latest formal review approves this SHA | Laravel SQLite + MySQL 8, Browser E2E and Composer Audit pass. Older CHANGES_REQUESTED reviews need reviewer reconciliation on this exact head; migration rollback is intentionally non-destructive/forward-only and needs explicit independent-review acknowledgement. Shared Browser E2E workflow is coordinated with #334. |
| #312 / #309 Live Class entitlement | 428e764f8cc240e7cd9c929dac280566f351fa5c | Open, Draft | CI was green on this head, but independent review requests fail-closed rejection when locked account and active Student-profile branch IDs conflict, with both-direction join/heartbeat regressions. Branch owner: mdlalchandakand-cell; do not duplicate edits without handoff. |
| #332 Referral commission payment proof | 448eb973e9aa9909f43d57ec3239d1d3e3f2d008 | Open, Draft | CI was green on this head, but review requires locking/re-reading the linked verified payment transaction and exact invoice/buyer/order/currency/gateway-payable amount binding (including wallet-credit accounting), plus negative and replay regressions. Branch owner: mdlalchandakand-cell; coordinate rather than duplicate. |
| #334 / #335 Flash Sale campaign engine | 520ef902ac0f8e7359c3ab50408f9d7f045bbb92 | Open, Draft | Current source/spec correct the false modal semantics: native non-modal disclosure, labelled form, no dialog/aria-modal, keyboard close and focus return. Composer Audit passed; Laravel SQLite/MySQL and Browser E2E were still running at snapshot time. Human NVDA/Firefox or NVDA/Chrome screen-reader smoke test and fresh independent review remain mandatory. Direct-enrollment/Unified Commerce quote integration is separate issue #335; no discounted checkout claim. |
| #338 / #313 Lesson media authorization | e79f97d7fa6df8dd43652b2ccbb47e98e7fbf1c0 | Open, Draft; approved on exact SHA | Laravel SQLite + MySQL 8, Browser E2E and Composer Audit pass. Author/maintainer still needs to complete ready/merge and ownership-register gates. |
| #342 / #303 Provider-confirmed online refunds | 8369178648948bdac445086a595cbec3af82a6ea | Open, Draft | Browser E2E passed; Laravel and Composer Audit were still running/pending at snapshot time. PayPal multi-capture is explicitly rejected pending capture-level ledger design. Provider refunds remain Disabled by default; merchant sandbox and independent finance/security review are external gates. |

**Coordination:** #311 and #334 share .github/workflows/browser-e2e.yml; Alomsenglishcenter is the single integration owner and both PRs must retain compatible optional fixture seeding. Do not merge one and assume the combined post-merge Browser E2E is proven; run the combined workflow on main after both are integrated.

**Merge discipline:** a green workflow is necessary, not sufficient. Keep Draft while a current-head Request Changes, missing human acceptance evidence, missing independent approval, stale ownership state or external financial/provider gate remains. Never dismiss another reviewer's request on their behalf.

