# Plugin implementation & acceptance matrix

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Inventory source:** `main` tree at commit `2471a9a929d1b36684aa82f2f9889d4c75ecfb01`  
**Review date:** 2026-11-16

## How to read this matrix

This first pass is a **static file inventory**, not a functional certification. “Present” means a matching file/directory exists in the plugin folder; it does not prove the feature works, is secure, or is production-ready. “Not present” means no matching file was found in that plugin directory and may be appropriate for an integration-only plugin. No module is marked functionally accepted until its acceptance tests pass.

| Plugin | Files inventoried | Inventory status | Functional acceptance |
|---|---:|---|---|
| `admin-boosting` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `admission-management` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `attendance-management` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `batch-management` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `branch-management` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `carousel-builder` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `certificate-management` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `class-management` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `cms-website` | 10 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `course-management` | 16 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `email-notifications` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `exam-management` | 9 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `fees-management` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `gateway-card` | 5 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `gateway-paypal` | 6 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `gateway-sslcommerz` | 5 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `gateway-stripe` | 6 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `gmail-marketing` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `google-console` | 5 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `guardian-management` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `hr-staff-management` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `library-management` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `marketing-management` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `meta-pixel` | 5 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `mobile-app-api` | 9 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `notice-board` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `notification-center` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `page-builder` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `payment-management` | 10 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `reports-analytics` | 5 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `role-ai-assistant` | 9 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `seo-manager` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `slider-builder` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `sms-gateway` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `storefront-catalog` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `student-management` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `teacher-management` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `universal-payment` | 8 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |
| `visitor-popup-ads` | 7 | Static inventory only; implementation not certified by file presence | Not yet functionally accepted |

## Source-level dependency scan

`PluginSiblingDependencyAuditTest` scans PHP files under all 39 plugin directories for direct `Plugins\\Modules\\...` namespace references and verifies that each sibling reference is declared in the plugin manifest as required or optional. The current policy keeps all sibling integrations optional. This scan does not prove standalone runtime behavior and does not yet detect every string-based table reference, dynamic service lookup, event coupling or external provider dependency; those remain part of each plugin's functional acceptance.

## Targeted regression coverage (not full plugin acceptance)

The current code candidate `02e3e31b111c62d734575c3816c247ed08d038ae` passed PHP 8.3 CI run [43858321888](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/43858321888): **129 tests / 1,011 assertions on SQLite and 129 tests / 1,011 assertions on MySQL 8**. PHP syntax lint, Composer security audit, cPanel package build and package verification also passed.

Targeted automated regressions cover plugin lifecycle and ZIP safety, branch isolation, Batch/Class updates, payroll authorization, mobile API privacy and enrolled-lesson access, standalone Fees/Course enrollment, Universal Payment private receipt evidence, and Stripe/PayPal/SSLCOMMERZ checkout paths. These focused tests do **not** certify every plugin in the 39-plugin inventory; the per-plugin acceptance gate below remains mandatory.

## Required acceptance gate for every plugin

- [ ] Manifest schema, unique slug, version and dependency constraints validated.
- [ ] Install, enable, disable, update and uninstall tested; failed operations roll back safely.
- [ ] Admin UI is complete, validates input server-side and reports success/failure accurately.
- [ ] Every route has authentication, role/permission middleware and CSRF protection where applicable.
- [ ] Create/read/update/delete and business workflows work with real persisted data (where applicable).
- [ ] Every branch-owned record is scoped on index, show, create, update, delete, export, download and API paths.
- [ ] Cross-branch access is rejected by negative tests.
- [ ] Input validation, authorization failures, missing dependencies and provider/network errors are covered.
- [ ] Audit events and operational logs are emitted for sensitive actions.
- [ ] Automated feature/integration tests cover success, validation, authorization, branch isolation and failure/rollback.
- [ ] Browser end-to-end test covers the actual Admin and end-user workflow.
- [ ] No hard-coded credentials, fake payment settlement, demo records or external-provider secrets.

## Priority order

1. Core plugin lifecycle and branch/permission enforcement shared by all modules.
2. Student, Guardian, Teacher, Admission, Course, Class, Batch, Exam and Attendance.
3. Fees, Payment Management, individual payment gateways and accounting reconciliation.
4. Certificate, Library, HR/Staff, Reports and Notifications.
5. CMS, Page Builder, Slider Builder, Carousel Builder and Storefront.
6. SEO, Marketing, Gmail, Meta Pixel, Google Search Console, SMS and email integrations.
7. Mobile App API and Role-based AI Assistant.

## Tracking rule

Update each row's functional acceptance only after code review and tests have been run on the exact candidate commit. Do not mark an integration as production-ready until sandbox/credential-backed provider tests are complete.

## Verified common lifecycle acceptance (39/39)

At candidate commit `e15a0299c55a1876ba555778eb19b8d1c47192c0`, the parameterized `AllPluginsIndependentAcceptanceTest` ran once for each of the 39 plugin slugs. It verifies each manifest and provider autoload, no required sibling dependency, install/enable, disable/re-enable, checksum-verified ZIP update with a newer version, uninstall/reinstall, reset-to-default settings, move-to-Recycle-Bin, restore to the prior installed/enabled state, and permanent removal from the Recycle Bin with a verified private recovery snapshot for test isolation. Optional integrations are not auto-installed. This establishes common lifecycle behavior for all 39 packages, not full feature acceptance for each plugin.

The same candidate adds permanent deletion to the main LMS Recycle Bin for all 17 supported record types (students, teachers, courses, categories, live classes, exams, batches, classes, branches, CMS content, sliders, campaigns and pop-ups). The action is admin/permission protected, requires explicit confirmation, only accepts soft-deleted records, writes an audit event, and returns a user-safe error when linked database records prevent deletion.

The exact candidate's GitHub Actions checks completed successfully:
- Laravel workflow `43873892471`: SQLite and MySQL 8 suites each passed **183 tests / 2,758 assertions**; PHP 8.3 syntax, Composer validation, cPanel production package build and package-content verification passed.
- Browser E2E workflow `43873892493`: passed.
- Composer Security Audit workflow `43872996475`: passed on the immediately preceding candidate; no Composer manifest or lockfile changes were made between that audit and this candidate.

**Still open:** plugin-specific feature acceptance for every business workflow; payment/email/SMS/marketing provider sandbox tests requiring approved credentials; real cPanel staging installation and migration/rollback validation; and a real staging 300-user load test. The LMS live-class module currently relies on external meeting providers, so the 300-user web-tier test does not prove that an external Zoom/Meet/Teams room supports 300 attendees. No plugin is being marked fully feature-accepted or production-certified by these common lifecycle checks.


## Current main inventory reconciliation (2026-11-18)

The historical matrix above is a 39-plugin snapshot and is not the current inventory. A recursive tree scan of current main at `aff05c40eaedbc5f01950bfb764bace999f1a369` found **44** plugin manifests. The five additional slugs absent from the historical table are:

| Additional plugin | Inventory status | Functional acceptance |
|---|---|---|
| `flash-sale` | Manifest present on current main | Not yet functionally accepted by this matrix |
| `learn-and-earn` | Manifest present on current main | Not yet functionally accepted by this matrix |
| `media-library` | Manifest present on current main | Not yet functionally accepted by this matrix |
| `student-tracing` | Manifest present on current main | Not yet functionally accepted by this matrix |
| `student-tracking` | Manifest present on current main | Not yet functionally accepted by this matrix |

This reconciles the count only; it does not certify these five modules or change the acceptance status of the original 39. The 39/39 common lifecycle result documented above applies only to its named historical candidate and must not be extrapolated to current main or treated as full feature acceptance. Re-run the common lifecycle suite and feature-specific acceptance against the exact current main candidate before updating any plugin to accepted.


## Current-main acceptance checkpoint (2026-11-18, commit 6b84fe1069bdf1caeb391f25b598e4737c7e1f9a)

The current-main CI checks for this exact commit have now all completed successfully:
- Laravel test workflow: SQLite suite passed.
- Laravel MySQL workflow: MySQL suite passed.
- Browser E2E smoke workflow: passed.
- Composer lockfile/security audit: passed.

The current main tree contains 59 plugin manifests; the PR #269 candidate contains 60, including the new commerce-cart module. The parameterized `AllPluginsIndependentAcceptanceTest` discovers plugin slugs from the module directories at runtime, so its lifecycle scenarios include all 44 manifests on this candidate rather than a hard-coded historical list. Its common lifecycle assertions cover provider autoload, independent install/enable, ZIP update, disable/re-enable, uninstall/reinstall, settings reset, Recycle Bin restore, and permanent-delete recovery handling. This is lifecycle acceptance only, not proof that every plugin's complete business feature set is correct.

### Current 44-plugin inventory and focused test references

| Plugin slug | Focused feature-test evidence in current test tree | Acceptance interpretation |
|---|---|---|
| `admin-boosting` | `BoostCampaignSafetyTest`, `BranchManagerCampaignFailClosedTest` | Partial targeted coverage |
| `admission-management` | `AcademicAndExamWorkflowTest`, `AdditionalModulesWorkflowTest` | Partial targeted coverage |
| `attendance-management` | `AttendanceRegisterWorkflowTest`, `AttendanceStandaloneIndexTest` | Targeted workflow coverage |
| `batch-management` | `BatchAndClassManagementTest` | Targeted workflow coverage |
| `branch-management` | `BranchManagerDashboardIsolationTest`, `BranchManagerFailClosedTest`, `BranchManagerTrackingFailClosedTest` | Targeted isolation/security coverage |
| `carousel-builder` | `GrowthAndMarketingPluginsAcceptanceTest` | Targeted CRUD/security coverage |
| `certificate-management` | `AcademicAndExamWorkflowTest` | Partial targeted coverage |
| `class-management` | `BatchAndClassManagementTest` | Targeted workflow coverage |
| `cms-website` | `CmsWebsiteSafetyTest`, `WebsiteBuildersTest` | Targeted safety/builder coverage |
| `course-management` | `CourseBranchIsolationTest`, `CourseOptionalIntegrationsTest` | Targeted isolation/integration coverage |
| `email-notifications` | `EmailNotificationsTest` | Targeted workflow coverage |
| `exam-management` | `AcademicAndExamWorkflowTest`, `ExamStandaloneTest` | Targeted workflow coverage |
| `fees-management` | `FeeBranchIsolationTest`, `FeesStandaloneTest` | Targeted workflow coverage |
| `flash-sale` | `FlashSaleEnrollmentTest` | Targeted enrollment coverage |
| `gateway-card` | `FinanceAndPaymentGatewayTest` | Shared gateway coverage; provider-specific sandbox still open |
| `gateway-paypal` | `FinanceAndPaymentGatewayTest` | Shared gateway coverage; provider-specific sandbox still open |
| `gateway-sslcommerz` | `PluginSettingsResetBackupTest`, `FinanceAndPaymentGatewayTest` | Settings/security plus shared gateway coverage; sandbox still open |
| `gateway-stripe` | `FinanceAndPaymentGatewayTest` | Shared gateway coverage; provider-specific sandbox still open |
| `gmail-marketing` | `GrowthAndMarketingPluginsAcceptanceTest` | Campaign/configuration coverage; live SMTP delivery not proven |
| `google-console` | `GrowthAndMarketingPluginsAcceptanceTest` | Configuration coverage; external verification not proven |
| `guardian-management` | `GuardianStandaloneTest` | Targeted workflow coverage |
| `hr-staff-management` | `PluginCriticalRegressionTest`, `AdditionalModulesWorkflowTest` | Partial targeted coverage |
| `learn-and-earn` | `PaidLearnAndEarnCheckoutTest` | Targeted checkout coverage |
| `library-management` | `MediaLibraryAndDigitalEbookTest` | Targeted workflow coverage |
| `marketing-management` | `MarketingGlobalDataAdminOnlyTest`, `BoostCampaignSafetyTest` | Partial targeted coverage |
| `media-library` | `MediaLibraryAndDigitalEbookTest` | Targeted workflow coverage |
| `meta-pixel` | `GrowthAndMarketingPluginsAcceptanceTest` | Configuration/privacy coverage; live event delivery not proven |
| `mobile-app-api` | `MobileAppSecurityTest`, `MobileAppUpdateAndPublicParityTest` | Targeted API/security coverage |
| `notice-board` | `NoticeBoardBranchSafetyTest` | Targeted authorization/branch coverage |
| `notification-center` | `NotificationCenterTest` | Targeted workflow coverage |
| `page-builder` | `PageBuilderBlocksTest`, `WebsiteBuildersTest` | Targeted builder coverage |
| `payment-management` | `FinanceAndPaymentGatewayTest`, `PaymentBranchIsolationTest` | Shared payment/isolation coverage |
| `reports-analytics` | `GrowthAndMarketingPluginsAcceptanceTest` | Reports/export coverage |
| `role-ai-assistant` | `AiAssistantSecurityTest`, `PluginCriticalRegressionTest` | Targeted security/configuration coverage |
| `seo-manager` | `GrowthAndMarketingPluginsAcceptanceTest` | Targeted SEO configuration/robots/sitemap coverage |
| `slider-builder` | `WebsiteBuildersTest`, `SiteLayoutBuilderTest` | Shared builder coverage |
| `sms-gateway` | `SmsGatewayTest` | Configuration/dispatch workflow coverage; live carrier delivery not proven |
| `storefront-catalog` | `StorefrontCatalogAcceptanceTest`, `StorefrontProviderContractTest` | Targeted catalog/provider-contract coverage |
| `student-management` | `StudentStandaloneTest`, `StudentTracingIndependenceTest` | Targeted workflow/integration coverage |
| `student-tracing` | `StudentTracingIndependenceTest`, `StudentTracingPluginTest` | Targeted event/independence coverage |
| `student-tracking` | `StudentTrackingPluginTest` | Targeted event validation/recording coverage |
| `teacher-management` | `TeacherStandaloneTest`, `StudentTeacherBranchOptionsTest` | Targeted workflow/branch-option coverage |
| `universal-payment` | `UniversalPaymentGatewayToggleTest`, `UniversalPaymentInvoiceSettlementTest`, `UniversalPaymentProofTest`, `PluginCriticalRegressionTest` | Targeted payment/security/settlement coverage; live provider settlement still open |
| `visitor-popup-ads` | `VisitorPopupSecurityTest`, `PluginCriticalRegressionTest` | Targeted CRUD/security coverage |

**Interpretation:** “Targeted” means a named automated feature test exists in the current test tree and passed as part of the current-main CI run. It does not mean exhaustive acceptance of every screen, edge case, permission, upgrade path, localization, or production integration. “Partial” and “shared” explicitly indicate narrower coverage.

### Remaining acceptance gaps

- Run a dedicated feature-by-feature acceptance checklist for each plugin, including authorization matrix, validation failures, empty states, pagination, localization, audit logs, and failure/rollback behavior.
- Exercise each payment gateway against its own approved provider sandbox, including signed webhooks, duplicate callbacks, refunds, reconciliation, and failure paths. Current generic payment tests do not substitute for these.
- Verify SMTP and SMS delivery with controlled test accounts; verify Google Search Console, GA4/GTM, Meta Pixel/CAPI with actual test events and consent behavior.
- Test clean cPanel install/upgrade/rollback on the target PHP 8.3 hosting profile and restore a production-sized backup into a separate disposable database.
- Run a provider-backed 300+ participant live-class test. Application/web-tier tests do not prove external meeting-provider room capacity.
- Review PR #31 separately; do not merge its divergent draft branch until its base/history are reconciled and its complete diff is revalidated against current main.

No production deployment or live data migration is authorized by this CI result.

## 2026-11-19 autopsy follow-up — candidate PR #269

The current candidate adds targeted regression coverage beyond the historical inventory above:

| Area / plugins | New source-level finding | Candidate change | Validation |
|---|---|---|---|
| Core plugin lifecycle (all modules) | Disable, uninstall and update did not invalidate compiled plugin routes | Centralized compiled-route invalidation on lifecycle transitions | Tests cover disable, uninstall and update; exact-head CI pending |
| Exam Management + Learn & Earn | Student-facing web/API paths relied on account branch IDs instead of the authoritative Student profile branch | Resolve student profile branch for listing, view/take/history/autosave/submit and API paths; branch-manager manual grant checks target profile branch | Regression test uses deliberately mismatched account/profile branch IDs; exact-head CI pending |
| Commerce Cart + Payment Management | Offline refunds could leave paid orders and non-course entitlements active | Suspend order entitlements and revoke order-bound exam/eBook access when invoice becomes underpaid; restore entitlements on later verified settlement | Regression test covers partial refund and repayment; exact-head CI pending |
| Commerce Cart + Payment Management | Existing suspended entitlement rows were not reactivated by insert-ignore settlement writes | Settlement now upserts the existing entitlement and refreshes its active state | Covered by refund/repayment regression; exact-head CI pending |

These are focused fixes, not a substitute for the complete per-plugin acceptance gate. All 60 candidate plugin manifests still require feature-by-feature review and exact-candidate validation. No plugin is promoted to fully accepted by this checkpoint.

### Candidate manifest/dependency reconciliation (2026-11-19)

A recursive Git tree scan of the exact PR #269 head `bf028e241a09579d30212135b74ded64602737cd` found **60 plugin manifests**. This supersedes the earlier partial estimate of 48. The exact 60 slugs are listed below; the 44-plugin count above remains a dated main-branch snapshot and the original 39-plugin matrix remains historical. Manifest presence is inventory only, not feature acceptance.

| Current PR #269 plugin slug (60) |
|---|
| `admin-boosting`, `admission-management`, `al-branding-loader`, `assignment-management`, `attendance-management` |
| `batch-management`, `board-question-solver`, `branch-management`, `carousel-builder`, `certificate-management` |
| `class-management`, `cms-website`, `commerce-cart`, `course-assessments`, `course-bundles` |
| `course-content-studio`, `course-management`, `course-wishlist`, `email-notifications`, `exam-management` |
| `expense-management`, `fees-management`, `flash-sale`, `gateway-card`, `gateway-paypal` |
| `gateway-sslcommerz`, `gateway-stripe`, `gmail-marketing`, `google-console`, `guardian-management` |
| `guardian-portal`, `hr-staff-management`, `learn-and-earn`, `library-management`, `marketing-management` |
| `media-library`, `meta-pixel`, `mobile-app-api`, `notice-board`, `notification-center` |
| `page-builder`, `payment-management`, `phonetics`, `push-notifications`, `question-bank` |
| `quiz-contest`, `referral-income`, `reports-analytics`, `role-ai-assistant`, `seo-manager` |
| `slider-builder`, `sms-gateway`, `storefront-catalog`, `student-management`, `student-tracing` |
| `student-tracking`, `support-center`, `teacher-management`, `universal-payment`, `visitor-popup-ads` |

`phonetics` and `al-branding-loader` remain separate plugin manifests; neither is merged into another module. The count and slug list must be regenerated whenever the candidate head changes.

A manifest-by-manifest dependency review found one dangling optional dependency: commerce-cart declared subscription-management, but no subscription-management plugin manifest exists in this candidate. The stale optional dependency has been removed. PluginSiblingDependencyAuditTest now also rejects required or optional manifest dependencies that do not resolve to a plugin manifest, in addition to checking direct sibling namespace references.

The candidate still requires the full dynamic manifest-discovery and lifecycle suite to pass on its exact head. A manifest resolving to another manifest is not evidence that the referenced plugin's workflows are complete.
