# Plugin Feature Acceptance Matrix

Branch: \`main\`

The current repository contains **44 plugin manifests** under \`laravel/plugins/modules\`. The data-provider suite \`AllPluginsIndependentAcceptanceTest\` discovers manifests dynamically, so new modules are included without maintaining a hard-coded slug list.

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

| al-branding-loader | `AlBrandingLoaderProductionHardeningTest`, `AlomBrandingLoaderPhoneticsPluginTest` (loader timeout, navigation exclusions, upload-path validation, enabled/disabled rendering) — targeted, not full browser acceptance |
| assignment-management | `AssignmentManagementPluginTest` (standalone install, assignment submission/grading, enrollment and branch guards, private attachment ownership, mobile API) — targeted |
| board-question-solver | `BoardQuestionSolverPluginTest` (provider disabled/budget, in-memory image handling, exam-safe behavior, platform-admin settings/history) — targeted |
| commerce-cart | `UnifiedCommerceCartTest`, `CourseGiftCommerceTest`, `CourseCommercePaymentSettlementTest`, `StorefrontPaymentCheckoutTest` (cart, gifts, checkout and settlement paths) — partial; invoice reservation/refund reconciliation remain open |
| course-assessments | `CourseAssessmentsPluginTest`, `CourseAssessmentsMobileApiTest` (assessment creation/import, immutable attempts, grading, deadlines, enrollment, branch and answer-key privacy) — targeted; #304/#305 fixes are pending PR review |
| course-bundles | `CourseBundlesPluginTest` (standalone install, free/paid purchase, branch visibility, immutable course snapshot and archive history) — targeted |
| course-content-studio | `CourseContentStudioPluginTest` (content post, safe video, lesson authoring/progress, enrollment gating and private files) — targeted |
| course-wishlist | `CourseWishlistPluginTest` (student ownership/privacy, published-course and branch checks, standalone install and mobile API) — targeted |
| expense-management | `ExpenseManagementPluginTest` (expense records, private receipts, approved totals, recycle-bin restore and branch/export isolation) — targeted |
| guardian-portal | `GuardianPortalPluginTest`, `GuardianStudentTrackingAuthorizationTest` (verified child links, read-only summaries, non-guardian denial and cross-branch denial) — targeted |
| phonetics | `AlomBrandingLoaderPhoneticsPluginTest` (dedicated course access and integration) — partial; speaking recording/review work is in open PR #292 and is not current-main evidence |
| push-notifications | `PushNotificationDeliveryTest`, `MobilePushTokenLifecycleTest` (dispatch behavior and per-device token lifecycle) — targeted; live provider delivery not proven |
| question-bank | `QuestionBankPluginTest`, `LearnAndEarnQuestionBankTest` (question types/categories, immutable paper snapshots, soft-delete and random paper generation) — targeted |
| quiz-contest | `QuizContestPluginTest` (standalone contest lifecycle, branch scope, submission validation, answer-key timing and result publication locks) — targeted |
| referral-income | `ReferralIncomePluginTest`, `ReferralIncomeCommerceSettlementTest` (attribution, settlement, partial/full refund reversal, payout and branch restrictions) — partial; financial red-team issue #301 remains open |
| support-center | `SupportCenterPluginTest` (standalone install, ticket ownership, replies, close state and mobile API) — targeted |

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
