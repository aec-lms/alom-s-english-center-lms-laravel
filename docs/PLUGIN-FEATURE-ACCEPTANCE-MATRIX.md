# Plugin Feature Acceptance Matrix

Inventory checkpoint: `main` at `73722f154e5e3361de15320c9b3a9b3f68c71e7d` (2026-11-20).

The current repository contains **60 plugin manifests** under `laravel/plugins/modules`. The 60-slug count was established at `db493c6525f110cd4815024f7a8965c1da8baa22` and revalidated through the mainline delta: three existing manifest files changed, with no plugin manifest path added or removed. Regenerate the tree inventory before accepting a later commit. The data-provider suite `AllPluginsIndependentAcceptanceTest` discovers manifests dynamically, so new modules are included without maintaining a hard-coded slug list.

This matrix describes automated coverage that exists in the repository. It is a coverage map, not a claim that every third-party integration or production environment has been accepted.

## Acceptance gates applied to every plugin

For every discovered manifest, \`AllPluginsIndependentAcceptanceTest\` verifies:

1. Plugin directory and manifest exist; slug matches; provider class autoloads.
2. No required sibling-plugin dependency; optional integrations do not silently install sibling modules.
3. Install and initial activation succeed.
4. A checksum-verified ZIP update advances the plugin version.
5. Disable and re-enable succeed.
6. Uninstall preserves the package; reinstall succeeds.
7. Settings reset removes custom configuration and reapplies declared manifest defaults.
8. Recycle-bin delete removes the installed package and marks it uninstalled.
9. Restore recovers the previous installed/enabled state.
10. Permanent recycle-bin deletion removes the recycle entry only after a verified private recovery copy is made.

Shared lifecycle/security regressions are also covered by \`PluginLifecycleTest\`, \`PluginCriticalRegressionTest\`, \`PluginSiblingDependencyAuditTest\`, and \`RecycleBinTest\`: incompatible Core versions, invalid setting keys/options, encrypted secrets, hook failure rollback, safe ZIP paths/checksums, dependency declarations, data preservation, tamper rejection, and audit history.

## Per-plugin functional acceptance mapping

| Plugin | Functional acceptance suite(s) |
|---|---|
| admin-boosting | \`BoostCampaignSafetyTest\` |
| admission-management | \`AdditionalModulesWorkflowTest\` (public application, branch validation, admin approval) |
| attendance-management | \`AttendanceRegisterWorkflowTest\`, \`AcademicAndExamWorkflowTest\`, \`PluginCriticalRegressionTest\` |
| batch-management | \`AdditionalModulesWorkflowTest\`, \`BatchAndClassManagementTest\` |
| branch-management | \`BranchManagerFailClosedTest\`, \`AcademicAndExamWorkflowTest\`, \`NoticeBoardBranchSafetyTest\` |
| carousel-builder | \`GrowthAndMarketingPluginsAcceptanceTest\`, \`WebsiteBuildersTest\` |
| certificate-management | \`AdditionalModulesWorkflowTest\` (issue, public verify, revoke) |
| class-management | \`AdditionalModulesWorkflowTest\`, \`BatchAndClassManagementTest\` |
| cms-website | \`CmsWebsiteSafetyTest\`, \`WebsiteBuildersTest\` |
| course-management | \`CourseOptionalIntegrationsTest\`, \`LessonContentProtectionTest\`, \`AcademicAndExamWorkflowTest\` |
| email-notifications | \`EmailNotificationsTest\`, \`AdditionalModulesWorkflowTest\`, \`PluginCriticalRegressionTest\` |
| exam-management | \`ExamStandaloneTest\`, \`AcademicAndExamWorkflowTest\` |
| fees-management | \`FeesStandaloneTest\`, \`AcademicAndExamWorkflowTest\` |
| flash-sale | \`FlashSaleEnrollmentTest\` |
| gateway-card | \`FinanceAndPaymentGatewayTest\` (fails closed without a verified acquirer adapter) |
| gateway-paypal | \`FinanceAndPaymentGatewayTest\` (checkout ownership and gateway/refund contract) |
| gateway-sslcommerz | \`FinanceAndPaymentGatewayTest\` (sandbox/adapter contract) |
| gateway-stripe | \`FinanceAndPaymentGatewayTest\` (checkout ownership and gateway/refund contract) |
| gmail-marketing | \`GrowthAndMarketingPluginsAcceptanceTest\` (secret storage and campaign logging; no false delivery claim) |
| google-console | \`GrowthAndMarketingPluginsAcceptanceTest\` (private token/settings storage) |
| guardian-management | \`GuardianStandaloneTest\`, \`AcademicAndExamWorkflowTest\` |
| hr-staff-management | \`AdditionalModulesWorkflowTest\`, \`PluginCriticalRegressionTest\` (payroll approval and branch restrictions) |
| learn-and-earn | \`PaidLearnAndEarnCheckoutTest\` |
| library-management | \`AdditionalModulesWorkflowTest\` (book, loan, return and stock count) |
| marketing-management | \`AdditionalModulesWorkflowTest\`, \`GrowthAndMarketingPluginsAcceptanceTest\` |
| media-library | \`MediaLibraryAndDigitalEbookTest\` |
| meta-pixel | \`GrowthAndMarketingPluginsAcceptanceTest\` (private settings/token storage) |
| mobile-app-api | \`MobileAppSecurityTest\`, \`MobileAppUpdateAndPublicParityTest\` |
| notice-board | \`NoticeBoardBranchSafetyTest\` |
| notification-center | \`NotificationCenterTest\`, \`PluginCriticalRegressionTest\` |
| page-builder | \`WebsiteBuildersTest\` (publish safe content/CTA; reject scripts and unsafe URLs) |
| payment-management | \`FinanceAndPaymentGatewayTest\`, \`UniversalPaymentProofTest\` |
| reports-analytics | \`GrowthAndMarketingPluginsAcceptanceTest\` (admin dashboard and audit CSV) |
| role-ai-assistant | \`AiAssistantSecurityTest\`, \`AdditionalModulesWorkflowTest\` |
| seo-manager | \`GrowthAndMarketingPluginsAcceptanceTest\` (robots, sitemap and route rules) |
| slider-builder | \`WebsiteBuildersTest\`, \`GrowthAndMarketingPluginsAcceptanceTest\` |
| sms-gateway | \`SmsGatewayTest\` |
| storefront-catalog | \`StorefrontCatalogAcceptanceTest\`, \`StorefrontProviderContractTest\`, \`PluginCriticalRegressionTest\` |
| student-management | \`StudentStandaloneTest\`, \`AdditionalModulesWorkflowTest\` |
| student-tracing | \`StudentTracingPluginTest\` |
| student-tracking | \`StudentTrackingPluginTest\` |
| teacher-management | \`TeacherStandaloneTest\`, \`AcademicAndExamWorkflowTest\` |
| universal-payment | \`UniversalPaymentProofTest\`, \`FinanceAndPaymentGatewayTest\`, \`PluginCriticalRegressionTest\` |
| visitor-popup-ads | \`VisitorPopupSecurityTest\`, \`PluginCriticalRegressionTest\` |

## Additional 16 current-main plugins (2026-11-20)

The following rows close the missing-slug gap identified in issue #300. They document existing named tests and their limited scope; they do not certify complete acceptance.

| Plugin | Focused feature-test evidence | Coverage / limitation |
|---|---|---|
| al-branding-loader | `AlBrandingLoaderProductionHardeningTest`, `AlomBrandingLoaderPhoneticsPluginTest` (loader-specific cases) | Partial: navigation loader timeout, route exclusions, safe logo paths, settings and plugin independence |
| assignment-management | `AssignmentManagementPluginTest` | Targeted: standalone install, submit/grade, enrollment, branch isolation, private attachments and mobile API |
| board-question-solver | `BoardQuestionSolverPluginTest` | Targeted: private history, in-memory image handling, daily limits, disabled-provider behavior and admin controls |
| commerce-cart | `UnifiedCommerceCartTest`, `CourseGiftCommerceTest`, `CourseCommercePaymentSettlementTest` | Targeted: cart ownership, branch checks, gift/voucher redemption and settlement; cross-gateway concurrency/refund gates remain open |
| course-assessments | `CourseAssessmentsPluginTest`, `CourseAssessmentsMobileApiTest` | Targeted: standalone sets, immutable question snapshots, student autosave/grading, branch checks and mobile API; teacher branch and current-role gaps tracked separately |
| course-bundles | `CourseBundlesPluginTest` | Targeted: standalone install, free/paid bundle purchase, branch visibility, invoice snapshot and archive history |
| course-content-studio | `CourseContentStudioPluginTest` | Targeted: standalone content, safe media, lesson progress, enrollment protection and private files |
| course-wishlist | `CourseWishlistPluginTest` | Targeted: student ownership, unpublished/branch restrictions, standalone install and mobile API |
| expense-management | `ExpenseManagementPluginTest`, `ExpenseFinanceReportTest` | Targeted: standalone expense, private receipts, approved totals, branch isolation and finance reports |
| guardian-portal | `GuardianPortalPluginTest` | Targeted: standalone install, verified child links, read-only summaries and cross-branch denial |
| phonetics | `AlomBrandingLoaderPhoneticsPluginTest` | Targeted: enrollment and branch gates, server-side IPA grading, learner progress analytics (7/30-day activity, accent/word accuracy and minimum-sample recommendations), spaced review queue, safe media, independent lifecycle and fail-closed host mappings; recording review race remains separately tracked |
| push-notifications | `PushNotificationDeliveryTest`, `MobilePushTokenLifecycleTest` | Targeted: FCM HTTP v1 delivery, retry idempotency, invalid-device deactivation, token registration and logout revocation |
| question-bank | `QuestionBankPluginTest`, `LearnAndEarnQuestionBankTest` | Targeted: independent categories/types, immutable paper snapshots, soft deletion and random paper constraints |
| quiz-contest | `QuizContestPluginTest` | Targeted: standalone contest, one submission, answer-key privacy, branch isolation, ranking and lifecycle locking |
| referral-income | `ReferralIncomePluginTest`, `ReferralIncomeCommerceSettlementTest`, `ReferralIncomeRedTeamHardeningTest`, `CourseCommercePaymentSettlementTest` | Targeted: attribution/commission idempotency, verified course-payment settlement gate, partial refund reversals, payout controls and branch isolation. First-paid-order attribution is serialized per buyer in the current service; remaining Issue #301 acceptance gaps are MySQL 8 two-connection payout/refund race tests, explicit over-refund/source-mismatch cases, fractional rounding and payout-committed recovery. |
| support-center | `SupportCenterPluginTest` | Targeted: standalone install, ticket ownership, student replies, closed-ticket protection and admin workflow |

## Verification boundaries

- Automated tests exercise application behavior and simulated/stubbed provider contracts. They do **not** prove that live bKash/Nagad/card/PayPal/Stripe/SSLCommerz, SMTP, SMS, Google, Meta, or other third-party accounts are approved, configured, reachable, or able to settle/send real transactions. Those require the owner's credentials and each provider's sandbox/live acceptance.
- Browser E2E checks only the browser flows covered by the workflow; it is not a substitute for real-device acceptance on the production domain.
- 300+ concurrent live-class load testing is a separate workflow and must be reviewed independently; it is not implied by passing plugin feature tests.
- MySQL 8 CI is a separate gate from SQLite. A plugin is not declared fully accepted for production until both DB suites pass.
- cPanel hosting, real backup/restore at production data volume, and main-domain deployment remain separate environment gates.
- "Independent" means no mandatory sibling plugin is installed or activated automatically. An optional integration may require the related plugin to be enabled before that particular cross-module action is available.

## CI status policy

This document does not store a stale CI snapshot. Before merge or release, inspect the latest workflow runs for the exact commit and require all applicable jobs to finish successfully: SQLite Laravel tests, MySQL 8 Laravel tests, Browser E2E, Composer Security Audit, and cPanel release-package verification. A queued, pending, cancelled, skipped, or in-progress job is not a pass.

Do not merge or deploy solely on the basis of this document. Refresh CI status and close all environment/provider-specific gates before release.


## Mandatory implementation-level acceptance (project-owner directive, 2026-11-21)

The 60-manifest inventory and existing lifecycle/feature-test mapping above are **not acceptance by themselves**. From this directive onward, no plugin, issue, or PR may be labelled Finished, Accepted, Complete, or Ready to Release until its own evidence record addresses every applicable gate below. Closing an issue, creating a PR, removing Draft, a manifest/provider loading successfully, or green generic CI is not acceptance.

For **each plugin independently**, inspect and record:

1. **Source code and architecture:** trace the real user/admin workflow from entry point through controller/action, service, model and persistence; confirm the implementation is production code (not placeholder/stub/demo-only), follows the plugin/Core ownership boundary, and has no duplicate or dead implementation.
2. **Routes and API contracts:** enumerate web/API routes, HTTP methods, middleware, authentication, CSRF/rate limits where applicable, request validation, response/error contracts, route names, and mobile/API parity. Prove unauthenticated and wrong-role requests are denied.
3. **Permissions and data scope:** verify every read/write/export/delete/restore/action permission against the current persisted role and account state; verify branch, tenant, owner, enrollment and record-level scope. Include direct URL/API IDOR attempts, role revocation, disabled-account and cross-branch negative tests.
4. **Database and migrations:** inspect schema, foreign keys, unique constraints, indexes, nullability, casts, transactions, locking and concurrency. Verify fresh install, upgrade from the prior schema, safe rollback where supported, uninstall/data-retention behavior, and no destructive loss of business/audit data. Exercise SQLite and MySQL 8; SQLite alone is insufficient for locking/constraint acceptance.
5. **UI and user journey:** inspect every applicable admin, staff, student, teacher, guardian and public screen; verify complete create/read/update/delete or workflow actions, validation feedback, loading/empty/success/error states, responsive desktop/tablet/mobile layouts, keyboard operation, labels/focus, and accessibility. Attach browser evidence for critical paths; record real-device/manual checks where browser automation cannot prove them.
6. **Tests and regressions:** map each acceptance claim to named tests and assertions. Cover happy paths, validation, authorization, branch/ownership boundaries, duplicates, concurrency/races, replay/idempotency, destructive actions, and regressions. Run focused tests plus full SQLite and MySQL 8 suites and relevant Browser E2E/security workflows on the exact candidate SHA.
7. **Integrations and dependencies:** enumerate required and optional plugin/provider dependencies and prove install/activation does not silently install optional siblings. Verify contract shape, credentials/secrets handling, timeouts, retries, idempotency, webhook signature and event identity, duplicate/out-of-order callbacks, reconciliation, and sandbox acceptance. Mark live-provider checks BLOCKED until authorized credentials and provider evidence exist; never infer live success from mocks.
8. **Failure handling and operations:** force provider/network/database/queue/storage failures, malformed or duplicate callbacks, permission denial, partial writes, lock timeout/deadlock, retry exhaustion and rollback. Verify fail-closed behavior, transaction rollback/no duplicate charge or ledger mutation, safe user-facing messages, structured/audited logs without secrets/PII leakage, actionable monitoring and recoverability.
9. **Release and ownership evidence:** name one accountable owner and independent reviewer; record exact commit SHA, migration and deployment notes, rollback/recovery plan, CI run IDs/results, browser evidence, external sign-offs, unresolved risks and explicit N/A rationale for any inapplicable gate.

### Per-plugin evidence record (required)

Use one record per plugin slug; do not copy a shared/generic pass to all plugins. At minimum record:

| Gate | Required evidence | Result |
|---|---|---|
| Source / architecture | Files and real workflow trace; placeholder/duplicate audit | PASS / FAIL / BLOCKED / N/A + evidence |
| Routes / API | Route list, middleware, validation and denial tests | PASS / FAIL / BLOCKED / N/A + evidence |
| Permissions / scope | Role/account/branch/owner matrix and negative tests | PASS / FAIL / BLOCKED / N/A + evidence |
| Database | Migrations, constraints, upgrade/rollback, SQLite + MySQL 8 | PASS / FAIL / BLOCKED / N/A + evidence |
| UI / accessibility | Screens and critical browser journeys, responsive/a11y evidence | PASS / FAIL / BLOCKED / N/A + evidence |
| Tests / regression | Named tests, assertions, exact-SHA CI links | PASS / FAIL / BLOCKED / N/A + evidence |
| Integrations | Dependency/provider contracts, sandbox or explicit blocker | PASS / FAIL / BLOCKED / N/A + evidence |
| Failure handling | Injected failures, rollback/idempotency/logging evidence | PASS / FAIL / BLOCKED / N/A + evidence |
| Release / review | Owner, independent approval, release/rollback evidence | PASS / FAIL / BLOCKED / N/A + evidence |

Allowed results are **PASS**, **FAIL**, **BLOCKED**, or **N/A with a reason**. Missing evidence is BLOCKED, never assumed PASS. A plugin is accepted only when every applicable row is PASS, every N/A is justified, no unresolved severity-1/2 security/data-integrity defect remains, exact-head CI passes, independent formal approval is recorded, and required external/staging/manual gates are signed off. If one gate is blocked, the plugin remains In Progress/Blocked even when its PR is open, Ready for Review, or all available CI is green.

The existing 60-plugin rows remain a coverage index only. They must be upgraded one plugin at a time with the evidence record above; no bulk status promotion is permitted. This rule applies to all current and future plugins and supersedes any weaker interpretation of the earlier matrix.


## Initial implementation audit — `teacher-management` (2026-11-21)

**Disposition: BLOCKED — not accepted.** This is a first-pass evidence record, not a declaration that the module is complete. Source inspected on main and the focused security candidate branch `security/teacher-account-reactivation-mainline` at PR #357 head `b073bfb92efee389410af206264757b6e011024d`. Related assignment authorization is tracked separately in PR #359; do not treat either PR as full plugin acceptance.

| Gate | Evidence observed | Result / remaining work |
|---|---|---|
| Source / architecture | `TeacherController`, `Teacher` model, service provider and plugin manifest inspected. #357 adds deterministic account/profile lock order, current actor/role revalidation, fail-closed missing teacher-role behavior, disabled-account preservation and actor-attributed reactivation audit. | **PARTIAL** — audit photo upload/storage failure lifecycle, duplicate creation races and controller/service separation; inspect restore path and all shared Core interactions before PASS. |
| Routes / API | `Routes/web.php` defines GET index, POST store, PUT update and DELETE destroy under `web`, `installed`, `auth`, `plugin:teacher-management`, `permission:teachers.manage`. No plugin-owned API route file is present in the module tree inspected. | **PARTIAL** — verify route registration/model binding, inactive-user behavior on read routes, CSRF and authorization denial for every route; record API as N/A only after confirming no Core/mobile route exposes equivalent teacher mutations. |
| Permissions / scope | Manifest declares `teachers.manage`; controller enforces branch-manager active-branch availability and branch ownership. #357 tests that branch manager cannot explicitly reactivate a disabled teacher account and that admin can; MySQL role-revocation lock proof is included. | **PARTIAL** — add/verify unauthenticated, wrong-role, cross-branch index/store/update/delete, inactive actor and direct-IDOR cases across every route; confirm teacher-role revocation and existing assignment authorization together with #359. |
| Database / migrations | Teacher table has user FK with cascade delete, nullable indexed branch ID, unique employee code, soft deletes and nullable `deleted_user_was_active`. Recycle-bin migration is additive and intentionally leaves columns on rollback; initial migration also has a no-op `down()`. | **BLOCKED** — document and test fresh install, upgrade from real prior schema, rollback/recovery and permanent-delete behavior on SQLite + MySQL 8. The no-op rollback is non-destructive but not a reversible rollback; explicitly validate deployment recovery. |
| UI / accessibility | One admin Blade index contains create form, teacher list, edit/delete controls and public-directory preview; it uses responsive Tailwind layout and labels for photo upload. | **BLOCKED** — no dedicated current-head browser evidence or manual keyboard/screen-reader/responsive sign-off is attached to this plugin audit. Verify validation, empty/loading/error states, all actions and mobile layout. |
| Tests / regressions | `TeacherStandaloneTest`, `TeacherProfileValidationTest` and shared `AcademicAndExamWorkflowTest` are mapped. #357 adds omitted-status preservation, admin-only reactivation, audit attribution, missing-role fail-closed, normalized email/legacy collision and MySQL role-revocation lock tests. Exact #357 SQLite, MySQL 8, Browser E2E and Composer checks were reported green. | **PARTIAL** — add route-by-route denial/branch matrix, duplicate employee-code/email concurrent insert, storage/database failure, recycle-bin restore/permanent-delete, and plugin-specific browser assertions. Re-run on final integrated head including #359. |
| Integrations | Manifest has no required sibling dependencies and lists `branch-management` as optional. `TeacherStandaloneTest` proves global teacher creation works without installing branch-management; branch-manager access fails closed unless branch-management is enabled and the assigned branch is active. | **PARTIAL** — verify optional-plugin enable/disable transitions, missing/inactive branch behavior and no hidden mandatory dependency in Core/mobile/public-directory consumers. |
| Failure handling | #357 fails closed when teacher role is missing; it locks actor and linked user records and audits account status changes. Photo upload uses server-detected image extension. | **BLOCKED** — inject storage failure and database failure after upload to prove no orphaned public files; test DB/lock failure rollback, audit logger failure policy, malformed image, duplicate employee code and retry behavior. |
| Release / review | #357 head `b073bfb92efee389410af206264757b6e011024d`; independent review on the current head is COMMENTED, not APPROVED. #359 is a separate six-file active-teacher assignment security slice. | **BLOCKED** — obtain formal independent approval, reconcile #357/#359 lock and authorization contract, re-run exact integrated CI, and complete manual UI/release evidence. |

**Immediate acceptance blockers:** non-reversible migration rollback/recovery evidence; route-by-route authorization and cross-branch negative coverage; public photo cleanup on transaction/storage failure; dedicated browser/accessibility evidence; and formal independent approval. Do not label `teacher-management` Finished or Accepted until these are closed with evidence. The active-teacher assignment guard in #359 is a dependency for end-to-end teacher access security, not a substitute for this plugin's own acceptance.


## Initial implementation audit — `payment-management` (2026-11-21)

**Disposition: BLOCKED — not accepted.** This is a source-level first pass on current `main`; it does not certify provider adapters or the related #341/#342 PRs.

| Gate | Evidence observed | Result / remaining work |
|---|---|---|
| Source / architecture | Inspected manifest, `PaymentController`, `PaymentSettlementService`, `PaymentTransaction` / `PaymentRefund` models and migrations. Settlement uses transaction and invoice row locks, state checks, currency/amount validation, duplicate callback idempotency, order snapshot reconciliation, and optional commerce/referral/coupon/gift integration guards. | **PARTIAL** — review full service helper paths and lock order across all provider callbacks/refunds; verify all optional class calls are guarded and behavior is safe when each optional plugin is absent or disabled. |
| Routes / API | Three web routes: GET ledger, POST offline receipt, POST transaction refund. All use `web`, `installed`, `auth`, `plugin:payment-management`, `permission:payments.manage`. No plugin-owned API route file was found in this module. | **PARTIAL** — test unauthenticated/wrong-role denial and direct transaction-ID access for each action; prove no Core/mobile API bypasses this middleware. |
| Permissions / scope | Branch-manager list is filtered by branch; offline payment checks invoice branch before and after invoice lock; refund checks transaction branch before and after transaction lock. | **BLOCKED** — actor and role pivot are not re-read/locked inside the offline-payment/refund transaction in the inspected controller path; add role/account revocation race tests and cross-branch direct-IDOR tests. |
| Database / migrations | Payment transaction reference is unique; amount is decimal; refund references payment transaction with cascade delete. Branch/invoice IDs are indexed but not declared foreign keys. Initial migration and branch-nullability migration intentionally have non-restoring `down()` methods. | **BLOCKED** — provide documented forward-only recovery strategy and tested upgrade/restore on SQLite + MySQL 8; verify permanent-delete/retention policy for refunds because refund rows cascade with transaction deletion; test duplicate transaction-reference and concurrent refund constraints. |
| UI / accessibility | `Resources/views/index.blade.php` displays a paginated ledger and empty state. | **BLOCKED** — inspected page contains no offline-payment entry form or refund action/form although the routes/controllers exist; implement complete discoverable UI or document the intended external workflow. Add responsive, keyboard and accessibility evidence and browser coverage. |
| Tests / regressions | Named coverage includes `FinanceAndPaymentGatewayTest`, `PaymentSettlementStateMachineTest`, `PaymentBranchIsolationTest`, `PaymentSettlementStateMachineTest` and shared provider/checkout suites. Existing tests cover partial settlement, duplicate callbacks, currency/overpayment rejection, branch/payment integration and gift refund fail-closed behavior. | **PARTIAL** — add actor-role revocation races, cross-branch direct-IDOR for refund/offline, concurrent partial refunds, provider callback replay/out-of-order/malformed payload, storage/logging failure, and UI journey assertions on exact integrated heads. |
| Integrations | Manifest declares no required siblings; fees, referral, student, marketing and course modules are optional. Settlement gates coupon redemption on marketing being enabled, referral recording on referral plugin enabled, and checks optional commerce tables. | **PARTIAL** — run independent install/activation tests with each optional plugin absent/disabled one at a time; verify direct settlement cannot be called without the required invoice provider; sandbox acceptance remains separate for each gateway. |
| Failure handling | Refund transaction rolls back on thrown exceptions and blocks online refunds without a verified adapter; gift/voucher refunds fail closed. | **BLOCKED** — refund catches every `Throwable` and returns `$e->getMessage()` to the browser, which can disclose SQL/internal exception details. Separate expected business-rule errors from unexpected exceptions, report unexpected failures safely, and return a generic message; add regression tests. |
| Release / review | Payment provider reservation/refund integration is separately tracked by #341/#342; this audit is against main source, not their unmerged candidate heads. | **BLOCKED** — resolve the actor-lock, raw-exception, missing-UI and migration-recovery gaps; run exact-head SQLite/MySQL 8/Browser/Security checks; obtain independent finance/security approval and required provider sandbox sign-off. |

**Immediate code-level blockers:** the ledger screen does not expose the available offline-payment/refund actions; refund failure handling returns raw exception text; account/role revocation is not serialized within the offline/refund controller transactions. These require remediation and focused tests before acceptance. Provider CI or a mocked callback cannot be counted as live provider acceptance.


### Payment Management candidate review addendum — PR #342 (2026-11-21)

PR #342 head `ca58ec8fc3cc64bd0f091206e23c982b495a0139` adds refund-request/history UI, approval/rejection/reconciliation actions, provider adapters and a provider-refund lifecycle migration. This corrects the earlier current-main observation that the ledger-only UI had no refund actions; the candidate does add those refund controls. It does not, in the inspected view, add an offline invoice-payment recording form.

The candidate still has an actionable security blocker: `RefundSettlementService::assertActorBranchScope()` locks the actor's User row but does not lock/revalidate the actor's role/permission pivot or the `payments.manage` permission inside the transaction. For non-branch-manager actors it returns without a role/permission recheck. `PaymentController::recordOffline()` continues to use the pre-transaction actor object. Concurrent permission/role revocation can race with offline refund, online refund request, approval, rejection or reconciliation. Additionally, controller catch blocks return raw `Throwable::getMessage()` text to the browser. These findings were posted to PR #342 comment **7058336714**; they are blockers, not approval. Require deterministic actor + permission/role locks and MySQL 8 revocation race tests, safe unexpected-error responses, and offline payment UI decision before acceptance.


## Initial implementation audit — `gateway-sslcommerz` (2026-11-21)

**Disposition: BLOCKED — not accepted.** Review spans main source plus the clean-mainline invoice-session candidate in PR #341 (`9157c4e1fc2fb4d96b3ae5c3a22211ff30c05772`) and the provider-refund adapter candidate in PR #342 (`ca58ec8fc3cc64bd0f091206e23c982b495a0139`). The candidates are separate and #341 must be reconciled before #342; no result is transferred between heads.

| Gate | Evidence observed | Result / remaining work |
|---|---|---|
| Source / architecture | Inspected manifest, provider, controller, routes and `SslcommerzGatewayService`. Checkout uses a hosted provider page, server-created transaction reference, allowlisted provider host, HTTPS, 20-second HTTP timeout, official validation-server API, and payment settlement service. #341 routes checkout creation through invoice-wide reservation service; #342 adds a provider refund adapter. | **PARTIAL** — review exact merged composition after #341/#342; verify credential encryption and provider refund adapter lifecycle in the final integrated code. |
| Routes / API | POST initiate is authenticated; IPN, success, failed and cancelled routes are public callback endpoints under installed/plugin middleware. CSRF is disabled only for provider callbacks. Raw card/PAN/CVV input is explicitly rejected. | **PARTIAL** — add explicit unauthorized/inactive-user/role-revocation tests for initiation and confirm callback rate/abuse controls; public callbacks must remain provider-validated and non-mutating when unverified. |
| Permissions / scope | Initiation allows invoice owner, finance operator with admin/accountant or gateway/payment permission, or assigned branch manager. Student ownership is checked through Student profile when enabled, otherwise payer email. | **BLOCKED** — PR #341 controller checks authorization before reservation, but reservation locks invoice only; actor account and persisted role/permission revocation are not revalidated under lock. A concurrent deactivation/revocation can race with session creation. Finding posted on #341 comment **7058353705**. Add MySQL 8 concurrency tests. |
| Database / migrations | Gateway module has no private business tables; it depends optionally on Fees/Payment/Student plugins. Payment session state is stored in payment_transactions and its JSON metadata by #341. #341 serializes all gateway reservations on the invoice row and stores one-way request fingerprints/idempotency metadata. | **PARTIAL** — test reservation upgrade/recovery and MySQL JSON/index behavior on clean and upgraded installations; confirm retention/cleanup policy for failed, unknown and risk-review sessions. |
| UI / accessibility | Gateway has no standalone admin/customer Blade views; it exposes hosted-checkout initiation API and redirects to SSLCOMMERZ. Merchant settings are supplied through Plugin Center. | **BLOCKED** — verify end-to-end student checkout UI and return states in Browser E2E and on a real device; test keyboard/accessibility and clear pending/failure/risk-review messaging. Plugin-specific UI is not proven by API tests alone. |
| Tests / regressions | `FinanceAndPaymentGatewayTest` covers invoice-owner denial, raw-card rejection, hosted session creation, wrong amount rejection, official validation success and card gateway fail-closed behavior. `InvoicePaymentSessionReservationTest` covers cross-gateway active-session exclusion, same-key replay, parameter fingerprint normalization and provider idempotency key scoping. | **PARTIAL** — add actor deactivation/permission-revocation races, callback replay/out-of-order/duplicate/risk-level tests, network timeout/5xx/invalid-host recovery, and final integrated exact-head SQLite + MySQL 8 + Browser + Composer evidence. |
| Integrations | Uses SSLCOMMERZ Session API and Validation API; only the expected sandbox/live HTTPS host is accepted. Credentials are stored via PluginManager secret settings. #342 adds refund adapter support, but it is a separate candidate. | **BLOCKED** — actual merchant sandbox credentials, real callback verification, refund sandbox confirmation and production/live merchant sign-off have not been supplied/verified. Mocked HTTP tests are not live acceptance. |
| Failure handling | Session transport/5xx/invalid-success responses are tracked as failed or ambiguous reservations; corrupt/missing encrypted hosted URL fails closed; risk_level=1 enters risk review; failed/cancelled browser returns do not mutate payment state. | **PARTIAL** — verify timeout/retry and reconciliation recovery for every ambiguous reservation, no double charge after late callback, safe error presentation and provider outage alerting. |
| Release / review | #341 has exact-head CI reported green but fresh formal financial/security approval is outstanding; #342 refund workflow is separately pending its own exact-head gates/review and external sandbox. | **BLOCKED** — close actor authorization race, prove complete browser journey, reconcile #341 then #342, obtain independent formal approval and real provider sandbox/live sign-off. |

Do not mark `gateway-sslcommerz` Accepted because a hosted URL can be created or mocked validation passes. Acceptance requires the full integrated session reservation, callback, settlement, refund/reconciliation, user authorization, failure-recovery and real-provider evidence.


## Initial implementation audit — `gateway-stripe` (2026-11-21)

**Disposition: BLOCKED — not accepted.** Candidate source inspected on PR #341 branch at `9157c4e1fc2fb4d96b3ae5c3a22211ff30c05772`; refund adapter work is separately proposed in #342.

| Gate | Evidence / result |
|---|---|
| Source / architecture | Manifest, provider, controller, service and admin view inspected. Uses Stripe PaymentIntents, Stripe.js PaymentMethod IDs, secret settings and invoice-wide reservation. **PARTIAL:** final refund composition remains pending. |
| Routes / API | Admin index has auth, permission and central-admin middleware; checkout/return require auth; webhook is public with CSRF exemption and signed payload verification. **PARTIAL:** inactive-account and permission-revocation race coverage is missing. |
| Permissions / scope | Invoice owner, finance operator and assigned branch manager are checked. **BLOCKED:** authorization is checked before reservation and not revalidated under the invoice lock. Account/permission revocation can race with checkout. See #341 comment **7058353705**. |
| Database | Uses Payment Management transactions/metadata; #341 adds invoice-wide session reservation and hashed idempotency fingerprints. **PARTIAL:** upgrade/recovery/retention evidence remains. |
| UI / accessibility | Admin view shows credential state, environment, webhook endpoint and recent transactions. **PARTIAL:** prove Stripe.js checkout, 3DS/action, return/pending states, keyboard/mobile accessibility in Browser E2E. |
| Tests | FinanceAndPaymentGatewayTest and InvoicePaymentSessionReservationTest cover gateway workflows and reservation replay. **BLOCKED:** no immediate PaymentIntent `succeeded` case. Stripe stores this as local `pending`, but PaymentSettlementService accepts only `initiated` (or already `settled`), so the verified success webhook cannot settle that path. Add signed-webhook and replay tests. See #341 comment **7058366593**. |
| Integrations | HMAC/timestamp, event type, PaymentIntent ID, transaction reference, amount and currency are checked. **BLOCKED:** real Stripe test-mode webhook and refund sandbox evidence not verified. |
| Failure handling | Ambiguous provider outcomes are reserved; invalid signatures and mismatches fail closed. **PARTIAL:** verify timeout recovery, repeated/late events, monitoring and safe errors. |
| Release / review | #341 CI was previously reported green; independent approval and #342 refund integration remain. **BLOCKED:** fix the two findings, rerun exact integrated checks and obtain formal approval/provider evidence. |

Do not accept `gateway-stripe` until the immediate-success state transition, actor authorization race, complete browser/3DS journey, refund lifecycle and real provider evidence pass on the final integrated head.


## Initial implementation audit — `gateway-paypal` (2026-11-21)

**Disposition: BLOCKED — not accepted.** Candidate source reviewed on PR #341 exact head `9157c4e1fc2fb4d96b3ae5c3a22211ff30c05772`; provider-confirmed refund support is separately proposed in #342.

| Gate | Evidence / result |
|---|---|
| Source / architecture | Manifest, provider, controller, service and admin view inspected. Uses PayPal Orders v2, OAuth, provider request idempotency, trusted approval URL hosts, capture verification and server-side webhook signature verification. #341 adds invoice-wide session reservation. **PARTIAL:** verify final refund adapter composition after #342. |
| Routes / API | Admin page has auth/permission/central-admin middleware; create order, return and cancel require auth; webhook is public with CSRF exemption and PayPal server-side signature verification. Raw card data is rejected. **PARTIAL:** test unauthorized/inactive actor and callback abuse paths. |
| Permissions / scope | Invoice owner, finance operator and assigned branch manager are checked for create/capture. **BLOCKED:** authorization is checked before service transaction; actor account and role/permission assignments are not revalidated under lock. See #341 comment **7058381537**. |
| Database | No PayPal-owned business tables; payment transactions/metadata and invoice reservation are owned by Payment Management. Provider idempotency key is scoped by invoice + gateway. **PARTIAL:** test reservation cleanup/reconciliation and retention for cancelled/ambiguous sessions. |
| UI / accessibility | Admin page shows credential/environment state, webhook endpoint and recent transactions. Checkout is hosted by PayPal; no separate checkout Blade view exists in the plugin. **BLOCKED:** verify actual student journey, return/cancel/pending/error messaging, keyboard/mobile accessibility and browser screenshots. |
| Tests | FinanceAndPaymentGatewayTest and InvoicePaymentSessionReservationTest cover gateway and cross-gateway reservation behavior. **PARTIAL:** add cancel-return and provider-verified cancellation tests, capture timeout/retry/replay tests, and account/permission revocation races on MySQL 8. |
| Integrations | OAuth credentials, Orders v2 create/capture, trusted approval hosts, PayPal webhook verification API, event type, capture status, amount/currency and transaction identity checks are present. #342 proposes refund adapters. **BLOCKED:** real PayPal sandbox webhook/capture/refund acceptance and live merchant sign-off not evidenced. |
| Failure handling | Provider request errors distinguish 4xx rejection from 5xx ambiguity during order creation; capture verifies one completed capture and exact amount/currency. **BLOCKED:** browser cancel currently only returns a JSON cancelled message and does not reconcile/release the local reservation; capture failures need explicit recoverable state and operator guidance. |
| Release / review | #341 exact-head CI was previously reported green; formal independent approval remains pending; #342 is a dependent refund candidate. **BLOCKED:** close actor race and cancellation/capture recovery gaps, run exact integrated checks and obtain provider sandbox/finance approval. |

Do not accept `gateway-paypal` until authorization serialization, provider-verified cancellation/reconciliation, capture failure recovery, refund lifecycle and real sandbox evidence pass on the final integrated head.


## Initial implementation audit — `gateway-card` (2026-11-21)

**Disposition: BLOCKED — intentionally not a functioning payment processor.** Source inspected on current main: manifest, route, controller, service and provider.

| Gate | Evidence / result |
|---|---|
| Source / architecture | The manifest explicitly says Visa/Mastercard are card networks and require a merchant acquirer/PSP adapter. `CardGatewayService` always throws rather than creating a fake transaction. **BLOCKED:** no acquirer-specific adapter is installed. |
| Routes / API | Authenticated checkout route requires `gateway.card.use`; webhook is public and CSRF-exempt. Controller rejects raw PAN/CVV fields. **PARTIAL:** webhook currently only returns the explicit disabled error from the service; test rate/abuse behavior when a real adapter is added. |
| Permissions / scope | Checkout requires auth and the gateway permission; controller allows only admin/accountant or invoice owner. **PARTIAL:** branch-manager support, current persisted account/permission revalidation, and branch-scoped invoice checks are not evidenced. |
| Database | No gateway-card-owned schema; payment transaction schema belongs to Payment Management. Service intentionally creates no transaction. **N/A** for local gateway tables; adapter-specific storage/constraints must be designed and reviewed. |
| UI / accessibility | No plugin-owned Resources/views directory was found. **BLOCKED:** no checkout UI or accessible tokenization component is supplied by this plugin; the app must use another configured processor or install a concrete adapter. |
| Tests | `FinanceAndPaymentGatewayTest` asserts raw PAN/CVV rejection, HTTP 503 when no acquirer adapter exists, no payment transaction creation and no invoice mutation. **PARTIAL:** this proves fail-closed behavior only, not a successful payment journey. |
| Integrations | Manifest lists Fees and Payment Management as optional dependencies. The service contains no actual acquirer integration. **BLOCKED:** select and install a named bank/PSP adapter and verify its sandbox contract, credentials, tokenization and signed callback. |
| Failure handling | Missing adapter returns an explicit non-success response; no fake pending transaction or false settlement is created. **PASS for fail-closed behavior only;** successful-path/failure-recovery acceptance remains BLOCKED until an adapter exists. |
| Release / review | The module description clearly discloses its adapter requirement. | **BLOCKED:** do not present Visa/Mastercard direct processing as available. Either keep the module visibly disabled with this limitation or add a specific acquiring-bank/PSP adapter and complete the full matrix. |

This plugin is **not accepted as a working payment gateway**. Its current correct behavior is to refuse processing safely. Stripe may support card payments independently, but that does not satisfy acceptance for this separate gateway-card plugin.


## Initial implementation audit — `universal-payment` (2026-11-21)

**Disposition: BLOCKED — not accepted.** Current-main source inspected: manifest, routes, controller, orchestrator, storefront gateway service, external-reference registry, migrations, admin UI and focused feature tests.

| Gate | Evidence / result |
|---|---|
| Source / architecture | Credential-free orchestrator; dedicated gateway plugins own online credentials. Manual receipts use UniversalPaymentTransaction; online invoice settlement uses dedicated gateway routes; storefront e-book checkout has its own SSLCOMMERZ path. Optional dependencies are declared. **PARTIAL:** duplicate SSLCOMMERZ integration logic exists between StorefrontPaymentGatewayService and gateway-sslcommerz; prove both paths share the same settlement/replay/failure guarantees or consolidate them. |
| Routes / API | Public checkout requires auth and throttle:10,1; admin routes require auth + payments.universal.manage; callback routes are public and CSRF-exempt. Proof download is authenticated and private. **PARTIAL:** test direct-ID access, inactive account, role revocation, callback spoofing and every branch-manager route. |
| Permissions / scope | Invoice checkout verifies payer email and rechecks ownership under invoice lock; e-book checkout locks the item and checks existing access/pending sessions; branch-manager scope is checked. **BLOCKED:** updateStatus locks the payment transaction but uses the preloaded actor role/branch state; persisted account, role pivot and payments.universal.manage permission are not revalidated under the same transaction. Add MySQL 8 role/permission/branch revocation race tests. |
| Database / migrations | Universal payment transactions include fee/storefront/exam references, branch and private proof metadata; external reference claims have a unique gateway+normalized-reference key and migration backfills legacy claims. Proof files use private local storage and are deleted if the DB write fails. | **PARTIAL** — migration rollback intentionally retains replay-protection claims; document forward recovery and test upgrade/restore, duplicate legacy claims and MySQL 8 unique-key concurrency. |
| UI / accessibility | Admin UI includes gateway selection, offline toggle, currency configuration, manual transaction entry/review and receipt proof workflow. | **PARTIAL** — provide responsive/keyboard/a11y and complete customer e-book/invoice browser journey evidence; label bKash/Nagad/Rocket accurately as manual receipt methods unless a merchant API plugin is installed. |
| Tests / regressions | `UniversalPaymentProofTest` covers private proof storage/download and unassigned branch-manager denial. `UniversalPaymentInvoiceSettlementTest` covers manual invoice receipt, duplicate pending receipt rejection, verified/rejected ledger mutation and branch mismatch. | **PARTIAL** — add forged failed/cancelled callback, duplicate/out-of-order provider callback, concurrent same-item purchase, external-reference race and actor permission-revocation tests. |
| Integrations | Gateway status checks installed/configured provider credentials; orchestrator dispatches to SSLCommerz/Stripe/PayPal services; manual bank/cash/bKash/Nagad/Rocket receipts use admin verification. | **PARTIAL** — gatewayStatus() does not include Fees/Payment plugin readiness in its computed availability; prove UI never displays a provider as ready when its required optional dependencies are absent. bKash/Nagad/Rocket are manual verification, not verified API integrations in this module. |
| Failure handling | Storefront checkout locks item row and prevents another pending/risk-review checkout; unknown network outcome is held for reconciliation; private proof is removed on failed DB transaction; success callback reports errors without granting access. | **BLOCKED** — `markSslcommerzFailed()` changes a pending storefront transaction to failed/cancelled based only on an unauthenticated browser callback, without provider verification. A forged return can release the pending-session guard and allow a second checkout. Treat browser fail/cancel as display-only until provider-verified reconciliation; add spoofed callback tests. |
| Release / review | Current source has named tests but no complete per-plugin evidence pack or formal independent approval in this audit. | **BLOCKED** — fix callback mutation and actor revocation race, prove exact-head SQLite/MySQL 8/Browser/security workflows, complete customer journey and independent review. |

**Immediate blockers:** unverified browser fail/cancel callback mutates local payment state; authorization is not revalidated under transaction locks; gateway availability does not prove dependency readiness; and bKash/Nagad/Rocket are manual receipt flows rather than live merchant integrations. Do not accept `universal-payment` until each is resolved or explicitly scoped as a non-supported capability.
