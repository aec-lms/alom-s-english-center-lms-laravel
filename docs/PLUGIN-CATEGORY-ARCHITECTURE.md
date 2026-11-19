# Plugin category architecture — current 60-plugin inventory

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Inventory ref:** `main` at `f7af7bc8e66a15db565bc40881da794ccc784987`  
**Audit date:** 2026-11-19  
**Scope:** taxonomy and package ownership only; no package identifiers, paths, providers, routes, permissions, or dependency declarations are changed by this document.

## Inventory evidence

A recursive Git tree scan of `laravel/plugins/modules/` at the inventory ref found **60 plugin manifests** (`plugin.json`). This is newer than the 58-plugin snapshot in `PLUGIN-58-FUNCTIONAL-ACCEPTANCE-MATRIX-2026-11-18.md`; that document remains a valid historical snapshot and must not be silently rewritten.

All 60 manifest files were read. The current `category` values are not a normalized taxonomy: they include `academic`, `education`, `finance`, `payment-gateway`, `commerce`, `communications`, `communication`, `website`, `content`, `appearance`, `marketing`, `seo`, `analytics`, `operations`, `integration`, and `ai`. The Plugin Center displays the raw category value and sorts records by it; it does not currently enforce a closed category enum.

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

Recommended implementation sequence:

1. Add a core `PluginCategoryRegistry` that maps each existing raw category and plugin slug to a canonical key and display label. Unknown categories must remain visible under **Other** rather than making a manifest invalid.
2. Keep the raw `category` value intact for backward compatibility. If a future manifest adds `category_key`, treat it as optional and fall back to the registry's slug/category mapping.
3. Use canonical labels only for Plugin Center grouping/filtering. Preserve plugin slug, provider class, route names, permission slugs, setting keys, dependency declarations and archive paths.
4. Add a registry test covering all 60 known slugs, unknown-category fallback, uniqueness, and the invariant that discovery/install/enable/disable/update/uninstall are unchanged by category resolution.
5. Add a manifest schema test and a full dynamic plugin lifecycle run on the exact migration candidate before any category values are changed.
6. Only consider physical folder movement if the plugin runtime gains an explicit stable package-path registry and migration tooling. The current layout is intentionally slug-based; moving folders now is not justified.

## Dependency and ownership policy

- Keep sibling integrations optional unless a plugin truly cannot provide its declared primary function without the sibling.
- Required dependencies must be explicit, acyclic, and covered by activation/install failure tests. Do not auto-install optional siblings.
- Prefer contracts, events, and service interfaces over direct sibling-controller/model imports.
- A taxonomy group is not a dependency boundary: e.g. a finance plugin may optionally consume academic course data, but must not silently install Course Management.
- Keep payment gateways as distinct packages under Finance & Commerce; do not merge their provider-specific callbacks or credentials into the generic commerce cart.
- Keep public-site builders separate packages while documenting their shared CMS storage integration.

## Acceptance status and limitations

- **60/60 manifests inventoried** at the stated main commit.
- **Taxonomy proposal:** complete in this document; it is not yet implemented in the Plugin Center.
- **Package relocation:** intentionally not performed.
- **Functional acceptance:** this taxonomy does not certify any plugin's complete business workflows. Use the current functional acceptance matrix and per-plugin tests.
- **External providers:** live payment, SMTP, SMS, analytics verification and push delivery still require approved provider credentials and sandbox/staging evidence.
- **Production release:** this document does not authorize deployment or close the external staging gates in issue #14.

This document implements the classification/reconciliation portion of issue #285 while deliberately preserving current package compatibility.
