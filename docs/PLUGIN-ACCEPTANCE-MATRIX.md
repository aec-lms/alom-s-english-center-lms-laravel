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