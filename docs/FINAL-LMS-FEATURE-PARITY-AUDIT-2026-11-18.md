# finalLMS → AEC Feature Parity Audit

Audit date: 2026-11-18  
Reference repository: https://github.com/lalchandakand-LMS/finalLMS  
Target repository: https://github.com/aec-lms/alom-s-english-center-lms-laravel  
Reference main: `6e76f827605d17e3f4827362db9d4678b96da5f6`  
Target main at audit start: `8242463837d43e1ce300592db8c6bf0b4f480826`

## Audit rules

- A directory or `plugin.json` is not proof that a feature is implemented.
- A matching AEC module is only a candidate equivalent; it must pass feature-level acceptance before being marked complete.
- Port business workflows, tests and user-visible capability—not framework-specific files or secrets.
- Preserve AEC's independent/portable plugin architecture. Optional integrations must remain optional.
- Keep external provider, cPanel, production restore and 300+ participant tests as separate release gates.

## Feature inventory and target mapping

| finalLMS capability | AEC candidate / current status | Parity action |
|---|---|---|
| Academic Levels | admission-management; academic-level custom workflow needs comparison | Verify dynamic level CRUD, level-scoped courses/batches/exams/pages; port gaps |
| AEC branding loader | al-branding-loader | Verify settings and branding parity |
| AI Builder | role-ai-assistant is not a full builder equivalent | Gap: assess safe admin content/site builder workflow |
| AI Developer | role-ai-assistant is not a full developer workflow | Gap: assess isolated, permissioned AI admin assistant |
| Analytics | reports-analytics | Compare report dimensions, exports and dashboards |
| Assignments | No assignment-management module at audit start | Ported in PR #136; pending CI/acceptance |
| Attendance | attendance-management | Compare offline queue, QR, roster, branch workflows |
| Batch Management | batch-management | Verify CRUD, schedules, capacity and student assignment |
| Blog Post Builder | cms-website / page-builder | Verify builder UX and publishing workflow |
| Branch Management | branch-management | Compare branch-scoped permissions and isolation |
| Carousel Builder | carousel-builder | Verify builder controls and front-end output |
| Certificates | certificate-management | Compare templates, verification and issuance |
| Communications | notification-center / email-notifications | Verify channels, templates and delivery logs |
| Content Store | storefront-catalog | Compare products, paid content and purchase lifecycle |
| Coupons & Promotions | marketing-management | Verify coupon validation, redemption, limits and checkout integration |
| Course Builder | course-management / page-builder | Compare lesson authoring and course builder UX |
| Course Content Studio | No direct AEC module at audit start | Gap: port content posts, paid media, eBook/PDF and learner progress |
| Course Management | course-management | Compare catalog, categories, enrollment and course lifecycle |
| Email | email-notifications | Verify templates, queue, provider configuration and logs |
| Email OTP | No dedicated equivalent | Gap: optional email OTP provider and verification lifecycle |
| Exam Management | exam-management / question-bank | Compare timed attempts, autosave/resume, integrity and question sets |
| Expense Management | No dedicated equivalent | Gap: port expenses, approvals, categories and reports |
| Fee Management | fees-management | Compare invoice/installment/collection/arrears workflows |
| Finance | fees-management / payment-management / reports-analytics | Gap: consolidated ledger, income/expense reconciliation and finance reports |
| Gmail | gmail-marketing | Verify OAuth and outbound campaign boundaries |
| Guardian Management | guardian-management | Compare guardian/student linking and access rules |
| Guardian Portal | guardian-management candidate only | Verify independent guardian dashboard and linked-student workflows |
| Instructor Mobile App | No Flutter app in AEC | Gap: port app after API contract and security review |
| Integrations | No generic integrations hub | Gap: admin-managed provider catalog, credentials and health checks |
| Lesson Management | course-management | Verify lesson sequencing, media and completion tracking |
| Live Classes | course-management | Compare scheduling, join links, recording and provider integration |
| Marketing | marketing-management | Compare campaigns, offers and attribution |
| Media Library | media-library | Verify storage, access control and media transformations |
| Meta Ads | meta-pixel only partial candidate | Gap: campaign/ads integration is not the same as pixel tracking |
| Meta Pixel | meta-pixel | Verify consent, event mapping and deduplication |
| Mobile API | mobile-app-api | Verify versioning, device tokens, rate limits and authorization |
| Mobile Verification | mobile-app-api candidate | Verify device approval, OTP and revocation |
| Notice Board | notice-board | Verify audiences, scheduling and read tracking |
| OTP Verification | sms-gateway candidate | Compare SMS/email OTP and abuse protections |
| Page Builder | page-builder | Compare reusable sections, templates and publishing |
| Payments | payment-management / universal-payment / gateway plugins | Compare callbacks, idempotency, receipts and reconciliation |
| Push Notifications | notification-center candidate only | Gap until native/web push delivery and device subscriptions pass |
| Refund Management | payment-management PaymentRefund | Verify request, approval, provider refund and reconciliation |
| Reporting | reports-analytics | Compare filters, exports and role/branch scoping |
| SEO | seo-manager | Verify metadata, sitemap, canonical and schema |
| Slider | slider-builder | Verify scheduling, responsive output and media support |
| Slider Builder | slider-builder | Verify CRUD and front-end placement |
| SMS | sms-gateway | Verify provider adapters, delivery logs and retry rules |
| SMS OTP | sms-gateway candidate | Verify OTP-specific rate limits and lifecycle |
| Staff Management | hr-staff-management | Compare HR records, roles and attendance links |
| Storage | media-library candidate only | Gap: provider adapters, quota, private storage and cPanel-safe configuration |
| Student Management | student-management | Compare admission, profiles, status and branch isolation |
| Student Mobile App | No Flutter app in AEC | Gap: port app after API contract and security review |
| Student Portal | portal/dashboard + student-management | Compare course, assignment, exam, payment and progress UX |
| Support Center | No dedicated equivalent | Gap: ticket lifecycle, assignment, SLA and student/guardian messaging |
| Teacher Management | teacher-management | Compare profile, workload, batch and access |
| Website CMS | cms-website | Compare posts, menus, pages, SEO and publishing |

## First implementation slices

1. Student Course Wishlist — PR #132, merged only after Laravel, MySQL, Browser E2E and Composer audit passed.
2. Assignment Management — PR #136, currently awaiting its own CI and review.
3. Next: Course Combo / Bundle Sales with immutable payment-course snapshots and atomic enrollment.
4. Then: Course Content Studio, Finance/Expense/Refund gaps, Guardian Portal and Support Center.
5. Student and Instructor Flutter apps follow only after AEC mobile API contracts and device-security acceptance are complete.

## Acceptance status

This document is an inventory and work plan, not a claim that all listed capabilities are already implemented. Every port requires feature-specific tests, standalone-plugin installation tests, branch/role isolation tests, upgrade/rollback checks and the normal CI suite. External integrations and production operations require their own real staging evidence.
