# Plugin Feature Acceptance Matrix

Branch: `chore/release-audit-and-plugin-matrix`

This matrix records the plugin-level acceptance coverage present in the Laravel test suite. The repository currently contains **40 plugin manifests**. The data-provider suite `AllPluginsIndependentAcceptanceTest` runs against each of the 40 slugs.

## Acceptance gates applied to every plugin

For every manifest, `AllPluginsIndependentAcceptanceTest` verifies:

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

Shared lifecycle/security regressions are also covered by `PluginLifecycleTest`, `PluginCriticalRegressionTest`, `PluginSiblingDependencyAuditTest`, and `RecycleBinTest`: incompatible Core versions, invalid setting keys/options, encrypted secrets, hook failure rollback, safe ZIP paths/checksums, dependency declarations, data preservation, tamper rejection, and audit history.

## Per-plugin functional acceptance mapping

| Plugin | Functional acceptance suite(s) |
|---|---|
| admin-boosting | `BoostCampaignSafetyTest` |
| admission-management | `AdditionalModulesWorkflowTest` (public application, branch validation, admin approval) |
| attendance-management | `AttendanceRegisterWorkflowTest`, `AcademicAndExamWorkflowTest`, `PluginCriticalRegressionTest` |
| batch-management | `AdditionalModulesWorkflowTest`, `BatchAndClassManagementTest` |
| branch-management | `BranchManagerFailClosedTest`, `AcademicAndExamWorkflowTest`, `NoticeBoardBranchSafetyTest` |
| carousel-builder | `GrowthAndMarketingPluginsAcceptanceTest`, `WebsiteBuildersTest` |
| certificate-management | `AdditionalModulesWorkflowTest` (issue, public verify, revoke) |
| class-management | `AdditionalModulesWorkflowTest`, `BatchAndClassManagementTest` |
| cms-website | `CmsWebsiteSafetyTest`, `WebsiteBuildersTest` |
| course-management | `CourseOptionalIntegrationsTest`, `LessonContentProtectionTest`, `AcademicAndExamWorkflowTest` |
| email-notifications | `EmailNotificationsTest`, `AdditionalModulesWorkflowTest`, `PluginCriticalRegressionTest` |
| exam-management | `ExamStandaloneTest`, `AcademicAndExamWorkflowTest` |
| fees-management | `FeesStandaloneTest`, `AcademicAndExamWorkflowTest` |
| gateway-card | `FinanceAndPaymentGatewayTest` (must fail closed without acquirer adapter) |
| gateway-paypal | `FinanceAndPaymentGatewayTest` (checkout ownership, gateway/refund flows) |
| gateway-sslcommerz | `FinanceAndPaymentGatewayTest` (sandbox/adapter contract) |
| gateway-stripe | `FinanceAndPaymentGatewayTest` (checkout ownership, gateway/refund flows) |
| gmail-marketing | `GrowthAndMarketingPluginsAcceptanceTest` (secret storage and campaign logging; no false delivery claim) |
| google-console | `GrowthAndMarketingPluginsAcceptanceTest` (private token/settings storage) |
| guardian-management | `GuardianStandaloneTest`, `AcademicAndExamWorkflowTest` |
| hr-staff-management | `AdditionalModulesWorkflowTest`, `PluginCriticalRegressionTest` (payroll approval and branch restrictions) |
| library-management | `AdditionalModulesWorkflowTest` (book, loan, return and stock count) |
| marketing-management | `AdditionalModulesWorkflowTest`, `GrowthAndMarketingPluginsAcceptanceTest` |
| meta-pixel | `GrowthAndMarketingPluginsAcceptanceTest` (private settings/token storage) |
| mobile-app-api | `MobileAppSecurityTest`, `MobileAppUpdateAndPublicParityTest` |
| notice-board | `NoticeBoardBranchSafetyTest` |
| notification-center | `NotificationCenterTest`, `PluginCriticalRegressionTest` |
| page-builder | `WebsiteBuildersTest` (publish safe content/CTA; reject scripts and unsafe URLs) |
| payment-management | `FinanceAndPaymentGatewayTest`, `UniversalPaymentProofTest` |
| reports-analytics | `GrowthAndMarketingPluginsAcceptanceTest` (admin dashboard and audit CSV) |
| role-ai-assistant | `AiAssistantSecurityTest`, `AdditionalModulesWorkflowTest` |
| seo-manager | `GrowthAndMarketingPluginsAcceptanceTest` (robots, sitemap and route rules) |
| slider-builder | `WebsiteBuildersTest`, `GrowthAndMarketingPluginsAcceptanceTest` |
| sms-gateway | `SmsGatewayTest` |
| storefront-catalog | `StorefrontCatalogAcceptanceTest`, `StorefrontProviderContractTest`, `PluginCriticalRegressionTest` |
| student-management | `StudentStandaloneTest`, `AdditionalModulesWorkflowTest` |
| student-tracking | `StudentTrackingPluginTest` |
| teacher-management | `TeacherStandaloneTest`, `AcademicAndExamWorkflowTest` |
| universal-payment | `UniversalPaymentProofTest`, `FinanceAndPaymentGatewayTest`, `PluginCriticalRegressionTest` |
| visitor-popup-ads | `VisitorPopupSecurityTest`, `PluginCriticalRegressionTest` |

## Verification boundaries

- Automated tests exercise application behavior and simulated/stubbed provider contracts. They do **not** prove that live bKash/Nagad/card/PayPal/Stripe/SSLCommerz, SMTP, SMS, Google, Meta, or other third-party accounts are approved, configured, reachable, or able to settle/send real transactions. Those require the owner's credentials and each provider's sandbox/live acceptance.
- Browser E2E checks the browser flows covered by the workflow; it is not a substitute for real-device acceptance on the production domain.
- 300+ concurrent live-class load testing is a separate workflow and must be reviewed independently; it is not implied by passing plugin feature tests.
- MySQL 8 CI is a separate gate from SQLite. A plugin is not declared fully accepted for production until both DB suites pass.
- cPanel hosting, real backup/restore at production data volume, and main-domain deployment remain separate environment gates.

## Current CI snapshot

Latest run for the current audit branch at the time this matrix was authored:
- SQLite Laravel suite: **205 passed, 3,027 assertions**.
- Browser E2E: **passed**.
- Composer Security Audit: **passed**.
- MySQL 8: **still running** at last check; not yet marked passed.

Do not merge or deploy solely on the basis of this document. Refresh CI status and close all environment/provider-specific gates before release.
