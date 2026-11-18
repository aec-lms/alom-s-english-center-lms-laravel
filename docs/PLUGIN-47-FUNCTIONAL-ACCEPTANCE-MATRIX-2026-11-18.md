# 47-Plugin Functional Acceptance Matrix

Audit date: 2026-11-18  
Audited branch: `main`  
Audited commit: `a0deb699f74b98ffbe7a656050f246bb8f9f1e03`

## Verified repository inventory

The current `laravel/plugins/modules/` directory contains **47 plugin packages**, each represented by a `plugin.json` manifest. The previous 39-plugin count is obsolete.

The recursive repository tree contains 858 files beneath the plugin module directories. The Laravel test tree contains 78 Feature/Unit test files.

## CI evidence for the audited commit

All three workflows below ran against the same main-branch commit and completed successfully:

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

**Current result: 47/47 packages are covered by this universal lifecycle test on the audited main commit.**

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

- Plugin inventory: **47/47 verified**.
- Universal plugin lifecycle test: **47/47 covered; suite passed**.
- Laravel automated suite: **317 passed / 3,822 assertions**.
- MySQL 8.0.46 automated suite: **317 passed / 3,822 assertions**.
- Composer security audit: **passed**.
- Browser E2E workflow: **passed**.
- Full independent business-feature acceptance for every plugin: **not yet declared complete; continue closing the remaining per-plugin gates above**.
