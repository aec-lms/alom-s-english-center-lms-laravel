# 58-Plugin Functional Acceptance Matrix

Audit date: 2026-11-18  
Audited branch: `main`  
Audited commit: `050d12243a5206ddc86d2e73469968477b0816e3`

## Verified repository inventory

The `laravel/plugins/modules/` directory contains **58 plugin packages**, each represented by a `plugin.json` manifest. The previous 39-plugin count is obsolete.

A recursive Git tree audit at the audited commit confirms **1,148 entries beneath the plugin module directories**. The Laravel test tree contains **110 Feature/Unit test files**.

## Historical CI evidence for the previous 47-plugin snapshot

The following successful runs belong to the previous audited commit `a0deb699f74b98ffbe7a656050f246bb8f9f1e03`. They are historical evidence only and do not certify the newer 58-plugin snapshot:

| Workflow | Run | Result |
|---|---:|---|
| Laravel + production package build | [44248406867](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/44248406867) | Passed |
| Composer Security Audit | [44248406808](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/44248406808) | Passed |
| Browser E2E | [44248407054](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/44248407054) | Passed |

Both the default test database run and MySQL 8.0.46 run report **317 passed tests and 3,822 assertions**. The Laravel workflow also completed attendance offline, PWA restart/offline, QR expiry/replay browser checks, production cPanel package build, package-content verification, and release artifact upload.

## Universal plugin lifecycle acceptance

`Tests/Feature/AllPluginsIndependentAcceptanceTest.php` dynamically discovers every valid plugin manifest, so new modules are included without maintaining a second hard-coded list. For each plugin it verifies:

- Manifest slug, provider autoloading, and absence of required sibling-plugin dependencies.
- Install and enabled state.
- Optional integrations do not silently install sibling plugins.
- A checksum-verified ZIP package update and version change.
- Disable and re-enable.
- Uninstall and reinstall while retaining package source.
- Reset to manifest-declared default settings.
- Recycle-bin deletion, restore, and permanent-delete archive behavior.

**Current inventory: 58/58 plugin manifests are present. The universal lifecycle test discovers manifests dynamically, so all 58 are included in the test's discovery scope; CI must pass on this exact snapshot before this is marked accepted.**

This is lifecycle acceptance, not proof that every plugin's complete business feature set has been manually or independently accepted.

## Plugin inventory and feature-test evidence

The following table records the current plugin inventory and the most directly relevant existing feature-test suites. Grouped suites intentionally cover related modules together. A named test suite is evidence of automated coverage, not a claim that every possible UI path or external provider interaction is covered.

| Plugin package | Relevant feature-test evidence | Assessment |
|---|---|---|
| admin-boosting | BoostCampaignSafetyTest; BranchManagerCampaignFailClosedTest | Targeted coverage exists |
| admission-management | AdditionalModulesWorkflowTest | Shared workflow coverage; standalone acceptance to confirm |
| al-branding-loader | AlomBrandingLoaderPhoneticsPluginTest; SiteIdentityBrandingTest | Targeted coverage exists |
| attendance-management | AttendanceRegisterWorkflowTest; AttendanceStandaloneIndexTest | Targeted + browser E2E coverage |
| batch-management | BatchAndClassManagementTest | Targeted coverage exists |
| branch-management | BranchManagerDashboardIsolationTest; BranchManagerFailClosedTest; CourseBranchIsolationTest | Targeted isolation coverage |
| carousel-builder | GrowthAndMarketingPluginsAcceptanceTest; WebsiteBuildersTest | Grouped feature coverage |
| certificate-management | AdditionalModulesWorkflowTest | Shared workflow coverage; standalone acceptance to confirm |
| class-management | BatchAndClassManagementTest | Targeted coverage exists |
| cms-website | CmsWebsiteSafetyTest; WebsiteBuildersTest | Targeted coverage exists |
| course-management | AcademicAndExamWorkflowTest; CourseOptionalIntegrationsTest; CourseBranchIsolationTest | Targeted coverage exists |
| email-notifications | EmailNotificationsTest | Targeted coverage exists |
| exam-management | AcademicAndExamWorkflowTest; ExamStandaloneTest | Targeted coverage exists |
| fees-management | FeesStandaloneTest; FeeBranchIsolationTest | Targeted coverage exists |
| flash-sale | FlashSaleEnrollmentTest | Targeted coverage exists |
| gateway-card | FinanceAndPaymentGatewayTest; UniversalPaymentGatewayToggleTest | Shared gateway coverage; provider sandbox pending |
| gateway-paypal | FinanceAndPaymentGatewayTest; UniversalPaymentGatewayToggleTest | Shared gateway coverage; provider sandbox pending |
| gateway-sslcommerz | FinanceAndPaymentGatewayTest; UniversalPaymentGatewayToggleTest; PluginCriticalRegressionTest | Shared gateway coverage; provider sandbox pending |
| gateway-stripe | FinanceAndPaymentGatewayTest; UniversalPaymentGatewayToggleTest | Shared gateway coverage; provider sandbox pending |
| gmail-marketing | GrowthAndMarketingPluginsAcceptanceTest | Targeted settings/campaign coverage; live delivery pending |
| google-console | GrowthAndMarketingPluginsAcceptanceTest | Targeted settings coverage; external verification pending |
| guardian-management | GuardianStandaloneTest; GuardianStudentTrackingAuthorizationTest | Targeted coverage exists |
| hr-staff-management | PluginCriticalRegressionTest | Targeted payroll/security regression coverage |
| learn-and-earn | PaidLearnAndEarnCheckoutTest | Targeted coverage exists |
| library-management | MediaLibraryAndDigitalEbookTest | Shared feature coverage |
| marketing-management | MarketingGlobalDataAdminOnlyTest; BoostCampaignSafetyTest | Targeted coverage exists |
| media-library | MediaLibraryAndDigitalEbookTest | Targeted coverage exists |
| meta-pixel | GrowthAndMarketingPluginsAcceptanceTest | Targeted settings coverage; live event delivery pending |
| mobile-app-api | MobileAppSecurityTest; MobileAppUpdateAndPublicParityTest | Targeted API/security coverage |
| notice-board | NoticeBoardBranchSafetyTest | Targeted coverage exists |
| notification-center | NotificationCenterTest; PluginCriticalRegressionTest | Targeted coverage exists |
| page-builder | PageBuilderBlocksTest; WebsiteBuildersTest | Targeted coverage exists |
| payment-management | FinanceAndPaymentGatewayTest; PaymentBranchIsolationTest; UniversalPaymentInvoiceSettlementTest | Shared payment coverage |
| phonetics | AlomBrandingLoaderPhoneticsPluginTest | Targeted coverage exists |
| question-bank | QuestionBankPluginTest | Targeted coverage exists |
| reports-analytics | GrowthAndMarketingPluginsAcceptanceTest | Grouped dashboard/export coverage |
| role-ai-assistant | AiAssistantSecurityTest; PluginCriticalRegressionTest | Targeted security coverage |
| seo-manager | GrowthAndMarketingPluginsAcceptanceTest | Grouped settings coverage |
| slider-builder | WebsiteBuildersTest; GrowthAndMarketingPluginsAcceptanceTest | Grouped feature coverage |
| sms-gateway | SmsGatewayTest | Targeted settings/provider coverage; live delivery pending |
| storefront-catalog | StorefrontCatalogAcceptanceTest; StorefrontProviderContractTest | Targeted coverage exists |
| student-management | StudentStandaloneTest; StudentTeacherBranchOptionsTest | Targeted coverage exists |
| student-tracing | StudentTracingIndependenceTest; StudentTracingPluginTest | Targeted independence/feature coverage |
| student-tracking | StudentTrackingPluginTest; StudentTrackingAcademicEventHooksTest | Targeted feature/privacy coverage |
| teacher-management | TeacherStandaloneTest; StudentTeacherBranchOptionsTest | Targeted coverage exists |
| universal-payment | UniversalPaymentGatewayToggleTest; UniversalPaymentInvoiceSettlementTest; UniversalPaymentProofTest | Targeted feature coverage |
| visitor-popup-ads | VisitorPopupSecurityTest; PluginCriticalRegressionTest | Targeted security coverage |

### Additional packages present in the 58-plugin snapshot

These 11 packages were added after the previous 47-plugin matrix snapshot and are now included in the repository inventory. Their named test files provide targeted evidence, but do not replace full per-plugin UI, authorization, migration and external-provider acceptance.

| Plugin package | Relevant feature-test evidence | Assessment |
|---|---|---|
| assignment-management | AssignmentManagementPluginTest | Targeted assignment workflow coverage |
| board-question-solver | BoardQuestionSolverPluginTest | Targeted solver/security coverage |
| course-assessments | CourseAssessmentsPluginTest; CourseAssessmentsMobileApiTest | Targeted assessment and mobile API coverage |
| course-bundles | CourseBundlesPluginTest | Targeted bundle workflow coverage |
| course-content-studio | CourseContentStudioPluginTest | Targeted content authoring/learning coverage |
| course-wishlist | CourseWishlistPluginTest | Targeted wishlist coverage |
| expense-management | ExpenseManagementPluginTest; ExpenseFinanceReportTest | Targeted expense and finance-report coverage |
| guardian-portal | GuardianPortalPluginTest; GuardianStudentTrackingAuthorizationTest | Targeted guardian and linked-student authorization coverage |
| push-notifications | PushNotificationDeliveryTest; MobilePushTokenLifecycleTest | Targeted delivery and device-token lifecycle coverage |
| quiz-contest | QuizContestPluginTest | Targeted contest workflow coverage |
| support-center | SupportCenterPluginTest | Targeted support-ticket workflow coverage |

## Current snapshot CI gate

The previous 47-plugin CI run IDs below must not be used as evidence for the 58-plugin snapshot. The 58-plugin snapshot requires a fresh Laravel SQLite + MySQL 8.0 run, Composer Security Audit, Browser E2E, and production package verification on the exact commit under review. Until those checks complete successfully, lifecycle and feature acceptance remain pending for release purposes.

## Remaining acceptance gates

Before declaring every plugin fully functionally accepted, continue with the following per-plugin gates and record test evidence for each:

1. Admin UI: list, create, view, edit, delete, empty/error states, and settings.
2. Authorization: admin/teacher/student/guardian/branch-manager access boundaries and cross-tenant isolation where applicable.
3. Business workflow: success, validation failure, duplicate submission, cancellation, and recovery.
4. Data lifecycle: fresh migration, rollback, reinstall, upgrade from previous version, and preservation of user data.
5. Integration independence: plugin disabled or absent, optional sibling absent, and no undeclared hard coupling.
6. Security: input validation, CSRF/authentication, rate limits, secret encryption, file upload/path safety, and private data exposure.
7. External integrations: sandbox success/failure/webhook replay/idempotency only when provider credentials and sandbox access are available.

## Explicit limits

- GitHub Actions success does not prove a live cPanel installation or production-domain smoke test.
- Gateway, SMTP, SMS, Google/Meta verification and webhook delivery cannot be claimed as live-verified without valid provider credentials and sandbox/production access.
- A 300+ concurrent live-class capacity claim requires a dedicated load-test environment and the actual conferencing provider.
- No production deployment is authorized by this audit document.

## Acceptance status

- Plugin inventory: **58/58 manifests verified at commit `050d12243a5206ddc86d2e73469968477b0816e3`**.
- Plugin module tree entries: **1,148 verified at that commit**.
- Feature/Unit test files: **110 present at that commit**.
- Universal plugin lifecycle test: dynamically discovers all valid manifests; exact 58-plugin run is pending CI confirmation.
- Laravel automated suite: **317 passed / 3,822 assertions**.
- MySQL 8.0.46 automated suite: **317 passed / 3,822 assertions**.
- Composer security audit: **passed**.
- Browser E2E workflow: **passed**.
- Full independent business-feature acceptance for every plugin: **not yet declared complete; continue closing the remaining per-plugin gates above**.
