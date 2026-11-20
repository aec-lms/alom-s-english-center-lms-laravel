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
| #311 / #308 Exam attempt snapshots | 2247159a16880b611d1f6e41828867252cf82096 | Open, Draft; latest formal review approves this SHA | Laravel SQLite + MySQL 8, Browser E2E and Composer Audit pass. One reviewer still has an unreconciled CHANGES_REQUESTED state; another reviewer acknowledged fixes in a later COMMENTED review. Mainline compare: 89 ahead / 460 behind (diverged); do not merge without a safe integration plan. Migration rollback is intentionally non-destructive/forward-only and needs explicit independent-review acknowledgement. Shared Browser E2E workflow is coordinated with #334. |
| #312 / #309 Live Class entitlement | 428e764f8cc240e7cd9c929dac280566f351fa5c | Open, Draft | CI was green on this head, but independent review requests fail-closed rejection when locked account and active Student-profile branch IDs conflict, with both-direction join/heartbeat regressions. Branch owner: mdlalchandakand-cell; do not duplicate edits without handoff. |
| #332 Referral commission payment proof | 448eb973e9aa9909f43d57ec3239d1d3e3f2d008 | Open, Draft | CI was green on this head, but review requires locking/re-reading the linked verified payment transaction and exact invoice/buyer/order/currency/gateway-payable amount binding (including wallet-credit accounting), plus negative and replay regressions. Branch owner: mdlalchandakand-cell; coordinate rather than duplicate. |
| #334 / #335 Flash Sale campaign engine | 5d524e2228b714bfbcced0994b616f84d967ea10 | Open, Draft | Admin title selector was scoped to the campaign table row after strict-mode CI failure. Composer Audit and Browser E2E passed; Laravel SQLite passed and MySQL 8 is still running. Earlier Request Changes states remain; fresh independent review and human NVDA/Firefox or NVDA/Chrome screen-reader smoke test are mandatory. Mainline compare: 55 ahead / 17 behind. Direct-enrollment/Unified Commerce quote integration remains separate issue #335. |
| #338 / #313 Lesson media authorization | e79f97d7fa6df8dd43652b2ccbb47e98e7fbf1c0 | Open, Ready; approved on exact SHA | Laravel SQLite + MySQL 8, Browser E2E and Composer Audit pass. GitHub reports mergeable=false; compare is 5 ahead / 17 behind main. Owner must integrate safely and rerun exact-head CI; do not merge a diverged branch. |
| #342 / #303 Provider-confirmed online refunds | 8eb4fd3e8adcb18d912589d5797cbbe9ee9359d3 | Open, Draft | Fixed the latest CI TypeError by changing the allowlisted optional gateway adapter map to module/class definitions before dynamic class resolution; Composer Audit and Browser E2E passed; Laravel SQLite and MySQL 8 are still running on this head. Prior issues fixed: ineffective global RuntimeException import, rejected_at mass-assignment, stale static guard test, duplicate-refund test vs DB uniqueness, plus plugin namespace coupling. Formal provider-identity finding remains: Stripe metadata transaction/refund IDs, PayPal invoice_id/custom_id, SSLCOMMERZ trans_id; owner to add negative tests and re-review. PayPal multi-capture fail-closed; refunds remain Disabled by default. Mainline compare: 22 ahead / 17 behind (recheck after new commits). |

**Coordination:** #311 and #334 share .github/workflows/browser-e2e.yml; Alomsenglishcenter is the single integration owner and both PRs must retain compatible optional fixture seeding. Do not merge one and assume the combined post-merge Browser E2E is proven; run the combined workflow on main after both are integrated.

**Merge discipline:** a green workflow is necessary, not sufficient. Keep Draft while a current-head Request Changes, missing human acceptance evidence, missing independent approval, stale ownership state or external financial/provider gate remains. Never dismiss another reviewer's request on their behalf.

## 2026-11-20 current coordinated remediation update

This newer exact-head snapshot supersedes the older rows above only for the PR heads and findings listed here. It does not close the repository-wide audit or authorize merging.

| PR / finding | Exact head | Current evidence | Remaining gate |
|---|---|---|---|
| #341 invoice reservation / provider idempotency | cdbcd004eecc4451a9f1f60115442d09010e6e8a | Found and fixed cross-invoice provider idempotency collision: Stripe and PayPal now receive bounded 32-character keys derived from gateway + invoice ID + client key; integration tests prove one client key cannot replay another invoice's provider session. Exact-head Laravel SQLite, Browser E2E and Composer Security Audit pass; MySQL 8 is in progress. | Complete exact-head MySQL 8 and independent payment-security review. |
| #341 invoice reservation / provider idempotency | 385d770de5947f38ee212c70b847748a8049c8a8 | Found and fixed cross-invoice provider idempotency collision: Stripe and PayPal receive bounded 32-character keys derived from gateway + invoice ID + client key; integration tests prove one client key cannot replay another invoice's provider session. SQLite, Browser E2E and Composer pass; MySQL job timeout is 30 minutes and exact-head MySQL is running. | Complete exact-head MySQL 8 and independent payment-security review. |
| #342 online refunds | 99f05adba09f274c6d28013ed3e803263762dc94 | Stripe transaction/refund metadata, PayPal invoice/custom IDs and SSLCOMMERZ transaction identity are checked fail-closed; negative mismatch tests assert no ledger mutation. Restored the complete test helper, fixed provider registry class references, and added a PayPal wrong-refund identity regression. Latest SQLite, Browser E2E and Composer checks are green; MySQL 8 is pending/running. | Exact-head MySQL 8 and independent finance/security approval; official provider sandbox acceptance. Refunds remain disabled by default. |
| #344 live-class account/profile consistency | f740e1bbaa0860a352ee33d10ddd11ec06bfcbc2 | Added MySQL heartbeat/account-deactivation race; join and heartbeat reject account/profile branch mismatch in both directions; role, enrollment and paid-entitlement revocation contention are covered. Prior MySQL run hit the 20-minute limit while still executing. MySQL timeout is now 30 minutes; the MySQL rerun is running, while the SQLite job from the first attempt was cancelled and needs a rerun. | Exact-head SQLite + MySQL 8, independent review; coordinate with #312 rather than merging both. |
| #344 live-class account/profile consistency | 4d22cf8746d3169f275cd02d13f741b2fa50a187 | Added MySQL heartbeat/account-deactivation race; join and heartbeat reject account/profile branch mismatch in both directions; role, enrollment and paid-entitlement revocation contention are covered. MySQL timeout is 30 minutes; SQLite, Browser E2E and Composer pass on current head; MySQL is running. | Exact-head MySQL 8 and independent review; coordinate with #312 rather than merging both. |
| #346 Course Assessment student authorization | 11dd0dcbb9e5f217c8d3f3f5b20511c4631f3a26 | SQLite, Browser E2E and Composer pass; cancelled MySQL attempt was rerun and is in progress. | MySQL 8 result and independent review; this is the current-mainline candidate over legacy #318. |
| #346 Course Assessment student authorization | 0f8b71136f53a5490c9e37cce85f0c8ba9cc2b68 | MySQL lock-wait diagnostics use the app test user with PROCESS granted only to the ephemeral CI database user; no root monitor credentials are passed to Laravel. Prior SQLite/MySQL attempts were cancelled during execution; current exact-head matrix is still pending/running. | Exact-head SQLite + MySQL 8 completion and independent review; this is the current-mainline candidate over legacy #318. |
| #347 Flash Sale clean-mainline v2 | 7032a5a6f8a7d34de78cae23d811028ccbd1198a | SQLite, Browser E2E and Composer pass on current head; MySQL concurrency timeout is 30 minutes and job is running. Migration is intentionally forward-only to avoid dropping pre-existing branch/campaign data. | MySQL 8, independent review, human screen-reader smoke test and separate #335 direct-enrollment/Unified-Commerce pricing integration. Do not merge with #334 or #294. |
| #353 Student identity normalization / generated code retry | d24cbe643a1a472826f7e4dd2d2c85e498bcfbe1 | Normalizes email and student codes, rejects case-insensitive legacy email/code duplicates on create/update, maps concurrent unique-index violations to validation errors, retries generated 10-character codes up to ten times, and rolls back after exhaustion. Composer passes; exact-head SQLite, MySQL 8 and Browser E2E are running. | Exact-head full CI and independent Student Management/security review. |
| #332 verified course-cart referral payment | 345c0aa1d843d94bbca1bf4d36733b537feecc9d | Laravel SQLite, MySQL 8, Browser E2E and Composer Security Audit all pass. Locked transaction/invoice/buyer/order/currency/gateway-payable amount checks and negative/replay tests are present. | Formal CHANGES_REQUESTED review remains; fresh independent finance/security re-review required. |
| #293 teacher account reactivation | 7f90927c10e8ddfe5d68627138c8c4c58b25f78e | Current source preserves independently disabled linked User state when is_active is omitted, restricts explicit reactivation to admins, records actor-attributed before/after audit, and includes regressions. | Branch is diverged 44 ahead / 568 behind main; no exact-head checks found. Clean-mainline integration, full CI and fresh review required. |
| #292 phonetics recordings | 2db83e4cf04bfe06177526dd4959575bab27d0a8 | Existing recorded CI results are historical only; branch is 66 ahead / 461 behind main. | Safe conflict-aware integration and fresh full CI/accessibility review; no force-push or wholesale shared-file replacement. |

## Merge and release controls

Every candidate above remains Draft unless a later formal status explicitly says otherwise. No PR was merged or deployed by this update. The repository LMS ruleset is still disabled with zero required approvals/status checks, so the intended approval/CI gate is not enforced by GitHub settings. Exact-head green checks are necessary but not sufficient: independent review, no-double-merge ownership, human accessibility validation, official provider sandbox evidence, cPanel rollback/restore rehearsal and authorized 300+ participant live-class validation remain external or maintainer gates.


## 2026-11-20 Student Management identity remediation

| Finding | Candidate | Exact head / evidence | Remaining gate |
|---|---|---|---|
| Email case variants and generated student-code collision handling | [#353](../pull/353) | Current clean-mainline head `6939cfe0ec3d2a224ecd853453681a0dbafe5750`; includes #352's normalization/case-insensitive legacy checks plus concurrent unique-index handling and bounded retry/exhaustion rollback. Composer passed; SQLite/MySQL/Browser workflows running. | Full exact-head CI and independent review; #352 is closed as superseded. |
