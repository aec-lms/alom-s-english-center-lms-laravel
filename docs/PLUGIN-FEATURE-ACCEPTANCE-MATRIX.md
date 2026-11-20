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
| phonetics | `AlomBrandingLoaderPhoneticsPluginTest` | Targeted: enrollment and branch gates, server-side grading, safe media, independent lifecycle and fail-closed host mappings; recording review race remains separately tracked |
| push-notifications | `PushNotificationDeliveryTest`, `MobilePushTokenLifecycleTest` | Targeted: FCM HTTP v1 delivery, retry idempotency, invalid-device deactivation, token registration and logout revocation |
| question-bank | `QuestionBankPluginTest`, `LearnAndEarnQuestionBankTest` | Targeted: independent categories/types, immutable paper snapshots, soft deletion and random paper constraints |
| quiz-contest | `QuizContestPluginTest` | Targeted: standalone contest, one submission, answer-key privacy, branch isolation, ranking and lifecycle locking |
| referral-income | `ReferralIncomePluginTest`, `ReferralIncomeCommerceSettlementTest` | Targeted: attribution/commission idempotency, partial refund reversals, payout controls and branch isolation; first-paid-order race remains open |
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
