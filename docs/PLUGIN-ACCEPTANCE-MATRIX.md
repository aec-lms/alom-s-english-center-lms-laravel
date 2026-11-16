# Plugin implementation & acceptance matrix

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Inventory source:** `main` tree at commit `2471a9a929d1b36684aa82f2f9889d4c75ecfb01`  
**Review date:** 2026-11-16

## How to read this matrix

This first pass is a **static file inventory**, not a functional certification. “Present” means a matching file/directory exists in the plugin folder; it does not prove the feature works, is secure, or is production-ready. “Not present” means no matching file was found in that plugin directory and may be appropriate for an integration-only plugin. No module is marked functionally accepted until its acceptance tests pass.

| Plugin | Files | Manifest | Controller | Model | Blade view | Routes | Migration | Functional acceptance |
|---|---:|---|---|---|---|---|---|---|
| `admin-boosting` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `admission-management` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `attendance-management` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `batch-management` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `branch-management` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `carousel-builder` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `certificate-management` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `class-management` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `cms-website` | 10 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `course-management` | 16 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `email-notifications` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `exam-management` | 9 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `fees-management` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `gateway-card` | 5 | Present | Not present | Not present | Present | Not present | **Not yet functionally accepted** |
| `gateway-paypal` | 6 | Present | Not present | Present | Present | Not present | **Not yet functionally accepted** |
| `gateway-sslcommerz` | 5 | Present | Not present | Not present | Present | Not present | **Not yet functionally accepted** |
| `gateway-stripe` | 6 | Present | Not present | Present | Present | Not present | **Not yet functionally accepted** |
| `gmail-marketing` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `google-console` | 5 | Present | Not present | Present | Present | Not present | **Not yet functionally accepted** |
| `guardian-management` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `hr-staff-management` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `library-management` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `marketing-management` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `meta-pixel` | 5 | Present | Not present | Present | Present | Not present | **Not yet functionally accepted** |
| `mobile-app-api` | 9 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `notice-board` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `notification-center` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `page-builder` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `payment-management` | 10 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `reports-analytics` | 5 | Present | Not present | Present | Present | Not present | **Not yet functionally accepted** |
| `role-ai-assistant` | 9 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `seo-manager` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `slider-builder` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `sms-gateway` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `storefront-catalog` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `student-management` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `teacher-management` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `universal-payment` | 8 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |
| `visitor-popup-ads` | 7 | Present | Present | Present | Present | Present | **Not yet functionally accepted** |

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