# Plugin category architecture — current 60-plugin inventory

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Inventory ref:** `main` at `f7af7bc8e66a15db565bc40881da794ccc784987`  
**Audit date:** 2026-11-19  
**Scope:** taxonomy and package ownership only; no package identifiers, paths, providers, routes, permissions, or dependency declarations are changed by this document.

## Inventory evidence

A recursive Git tree scan of `laravel/plugins/modules/` at the inventory ref found **60 plugin manifests** (`plugin.json`). This is newer than the 58-plugin snapshot in `PLUGIN-58-FUNCTIONAL-ACCEPTANCE-MATRIX-2026-11-18.md`; that document remains a valid historical snapshot and must not be silently rewritten.

All 60 manifest files were read. The current `category` values are not a normalized taxonomy: they include `academic`, `education`, `finance`, `payment-gateway`, `commerce`, `communications`, `communication`, `website`, `content`, `appearance`, `marketing`, `seo`, `analytics`, `operations`, `integration`, and `ai`. At that inventory ref, the Plugin Center displayed the raw category value and sorted records by it; it did not enforce a closed category enum. This PR adds a display-only registry and grouping layer without changing the raw values.

## Proposed functional taxonomy

The following groups classify packages by their primary responsibility. A plugin appears in one primary group only; secondary capabilities belong in its description, tags, or dependency metadata rather than duplicate package entries.

| Canonical group | Purpose | Plugins |
|---|---|---|
| **Academic & Learning** | Teaching, curriculum, assessments, learning delivery and academic resources | `assignment-management`, `attendance-management`, `batch-management`, `board-question-solver`, `certificate-management`, `class-management`, `course-assessments`, `course-content-studio`, `course-management`, `course-wishlist`, `exam-management`, `learn-and-earn`, `library-management`, `phonetics`, `question-bank`, `quiz-contest` |
| **Student & People Lifecycle** | Admissions, student/guardian/teacher records, tracking and learner relationships | `admission-management`, `guardian-management`, `guardian-portal`, `student-management`, `student-tracing`, `student-tracking`, `teacher-management` |
| **Finance & Commerce** | Cart, catalogue sales, invoices, payment orchestration, gateways, commissions and expenses | `commerce-cart`, `course-bundles`, `expense-management`, `fees-management`, `gateway-card`, `gateway-paypal`, `gateway-sslcommerz`, `gateway-stripe`, `payment-management`, `referral-income`, `storefront-catalog`, `universal-payment` |
| **Communication & Support** | Email/SMS/push delivery, notices and support conversations | `email-notifications`, `notice-board`, `notification-center`, `push-notifications`, `sms-gateway`, `support-center` |
| **Content & Website CMS** | Public pages, editorial content, media assets and visual presentation builders | `carousel-builder`, `cms-website`, `media-library`, `page-builder`, `slider-builder` |
| **Marketing & Growth** | Campaigns, promotions, analytics tags, search visibility and conversion tools | `admin-boosting`, `flash-sale`, `gmail-marketing`, `google-console`, `marketing-management`, `meta-pixel`, `seo-manager`, `visitor-popup-ads` |
| **Analytics & Organization Operations** | Organization/branch operations, staff administration and reporting | `branch-management`, `hr-staff-management`, `reports-analytics` |
| **Integrations & APIs** | Public/mobile API surfaces and external application integration contracts | `mobile-app-api` |
| **AI & Automation** | Role-aware AI assistance and automated reasoning workflows | `role-ai-assistant` |
| **Platform Branding** | LMS identity, site branding and global visual shell behavior | `al-branding-loader` |

**Inventory reconciliation:** 16 + 7 + 12 + 6 + 5 + 8 + 3 + 1 + 1 + 1 = **60 packages**. No plugin is omitted or duplicated.

## Safe compatibility strategy

Do not rename plugin slugs or move plugin directories to match the taxonomy. Those identifiers are referenced by manifests, database records, routes, permissions, service providers, settings, archives, update packages and optional integrations.

Do not bulk-rewrite the existing `category` field in this pass. It is consumed by PluginManifest/PluginRecord and passed to icon resolution; changing category values without an explicit compatibility layer could alter stored catalog values or visual behavior.

Implemented in this candidate:

1. Added `PluginCategoryRegistry`, mapping all 60 current slugs and legacy category strings to canonical keys and labels. Unknown future categories fall back to **Other** rather than invalidating a manifest.
2. Kept every raw manifest `category` value intact; no manifest schema or stored catalog migration is required.
3. Plugin Center cards are grouped by canonical category label while icons, package paths and runtime operations continue using the original slug/category fields.
4. Added `PluginCategoryRegistryTest` for the 60-slug count/uniqueness, known-slug coverage, legacy fallbacks and unknown-category behavior.
5. Exact-head CI and the existing dynamic plugin lifecycle suite must still pass before merging this implementation.
6. Physical folder movement remains intentionally out of scope: the current layout is slug-based and no stable package-path migration system exists.

## Dependency and ownership policy

- Keep sibling integrations optional unless a plugin truly cannot provide its declared primary function without the sibling.
- Required dependencies must be explicit, acyclic, and covered by activation/install failure tests. Do not auto-install optional siblings.
- Prefer contracts, events, and service interfaces over direct sibling-controller/model imports.
- A taxonomy group is not a dependency boundary: e.g. a finance plugin may optionally consume academic course data, but must not silently install Course Management.
- Keep payment gateways as distinct packages under Finance & Commerce; do not merge their provider-specific callbacks or credentials into the generic commerce cart.
- Keep public-site builders separate packages while documenting their shared CMS storage integration.

## Acceptance status and limitations

- **60/60 manifests inventoried** at the stated main commit.
- **Taxonomy implementation:** canonical categories are now resolved by `PluginCategoryRegistry` and used to group Plugin Center cards; the original manifest `category` values remain unchanged.
- **Package relocation:** intentionally not performed.
- **Functional acceptance:** this taxonomy does not certify any plugin's complete business workflows. Use the current functional acceptance matrix and per-plugin tests.
- **External providers:** live payment, SMTP, SMS, analytics verification and push delivery still require approved provider credentials and sandbox/staging evidence.
- **Production release:** this document does not authorize deployment or close the external staging gates in issue #14.

This document implements the classification/reconciliation portion of issue #285 while deliberately preserving current package compatibility.
