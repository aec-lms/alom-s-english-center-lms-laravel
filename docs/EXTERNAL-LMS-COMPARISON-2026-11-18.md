# External LMS Comparison and Safe Integration Review — 2026-11-18

Compared repositories:
- Reference: https://github.com/lalchandakand-LMS/finalLMS (tree inspected at 6e76f827605d17e3f4827362db9d4678b96da5f6)
- Alom's LMS: https://github.com/aec-lms/alom-s-english-center-lms-laravel (main inspected at 579b9a2c486a0b17745af627e0cb04689d80007c)

## Executive decision

Do not merge the repositories wholesale. They are separate Laravel layouts with different plugin keys, route prefixes, runtime contracts and database conventions. A wholesale merge risks duplicate tables/routes, bypassed plugin lifecycle checks, incompatible migrations and weakening branch/tenant isolation.

The reference repository has useful implementation patterns, particularly Flutter student/instructor clients and an encrypted offline attendance queue. Alom's LMS already has stronger evidence for its current Laravel plugin lifecycle, role/branch controls, attendance synchronization, QR expiry/replay defenses and CI/package verification. Reuse concepts and port only after contract-level adaptation.

## Feature comparison

| Area | Reference repository evidence | Alom's LMS evidence | Decision |
|---|---|---|---|
| Laravel LMS foundation | Separate Laravel app tree, app-level controllers/models | Laravel 12 app under laravel/, plugin-first modular structure | Keep Alom's current architecture |
| Plugin lifecycle | Extensive plugin runtime, installer, signature/integrity and recovery classes in reference tree | Core PluginManager, manifest/lifecycle, protected private plugin packages, lifecycle tests and 47 manifests | Keep Alom's lifecycle; compare specific hardening cases before any port |
| Course / lesson / batch | Course, lesson, batch controllers/models and migrations | Course, batch, lesson and enrollment plugins/workflows | Already represented; avoid duplicate modules |
| Exams / question bank | Exam attempts, reusable question sets, grading and quiz-contest code | Exam management, question bank and Phonetics exam/practice plugin | Preserve Alom's independent plugin boundaries; evaluate quiz contest as a separate future feature only if product scope needs it |
| Attendance | QR attendance plus Flutter student scanning and instructor offline queue | QR sessions, roster mapping, offline sync/review, idempotency, branch isolation and browser E2E | Backend safety already present; Flutter clients are the clearest valuable gap |
| Mobile API | Reference Flutter clients target /api/v1; reference mobile_api manifest declares status: metadata_only | Working Mobile App API plugin exposes /api/mobile routes and Sanctum auth | Do not copy API client unchanged; create an explicit versioned compatibility contract first |
| Student / instructor mobile apps | apps/student_app and apps/instructor_app include Flutter UI, API clients and tests | No Flutter application directories in Alom's repository tree | Port as a dedicated, separately tested workstream after endpoint mapping |
| Guardian portal | Reference guardian_portal manifest declares status: metadata_only | Guardian management plugin exists; a complete guardian self-service portal is not established by the current inventory alone | Do not claim the reference portal is complete; implement only after verified guardian-child authorization and portal acceptance tests |
| Finance / payments | Invoices, refunds, bKash service, payment gateway abstractions | Fee, payment, universal gateway and finance/reporting plugins | Keep Alom's ledger and provider adapters; no unverified gateway code copied |
| CMS / content studio | CMS, page builder and course content studio paths/docs | CMS website, page builder, slider/carousel, media library and course-management capabilities | Preserve current builders and content IDs; avoid competing duplicate builders |
| CI / release | Reference CI, CodeQL and Flutter workflows | Laravel + MySQL integration, Browser E2E, Composer audit and cPanel package verification | Keep Alom's CI as release gate; add Flutter CI only with real app code |

## Specific high-value patterns to port

### 1. Flutter student and instructor apps — accepted for a dedicated integration phase

The reference apps contain useful patterns:
- secure token storage and device identity;
- student QR scanning with foreground-only location permission;
- instructor offline attendance queue with bounded records and sync batching;
- idempotency keys and explicit sync states;
- tests for API base URL, QR validation and offline queue behavior.

These are patterns, not drop-in compatible code. The reference clients default to /api/v1, while Alom's current Mobile App API routes are under /api/mobile. The attendance endpoints and payload contracts also differ. Copying the clients without an adapter and contract tests would ship broken app flows. Port them only after a documented API v1 contract and endpoint-by-endpoint tests exist.

### 2. Guardian self-service portal — not accepted as a code transplant

The reference manifest marks its guardian portal metadata-only. Alom already has guardian management. A real portal must be built against Alom's verified guardian-child relationship model, and must never allow a guardian to enumerate or access another student's data. Add read-only child progress, attendance, notices and invoices first; require relationship-scope tests for every endpoint.

### 3. Plugin runtime patterns — selective review only

The reference tree has useful named services for archive staging, manifest validation, signature verification, resource access and package recovery. Alom already has corresponding plugin-core functionality and security tests. Do not import duplicate runtime classes. Any missing hardening should be expressed as a targeted regression test against Alom's own PluginManager contract.

## No-copy / no-regression rules

- Do not copy migrations or models across repositories by filename; their schemas and namespaces differ.
- Do not register reference routes/controllers directly in Alom's app.
- Preserve Alom's plugin independence: AL Branding & Loader remains one plugin with separate feature areas; Phonetics remains a separate plugin.
- Keep permission checks, branch isolation, private media authorization, audit logging, idempotency and protected core services in the Alom implementation.
- Do not treat a manifest or documentation page as proof that a feature is implemented.
- Do not enable external payment, SMS, email, analytics or conferencing integrations without credentials and sandbox verification.

## Validation / release evidence

At the inspected Alom main commit, 579b9a2c486a0b17745af627e0cb04689d80007c, the Laravel workflow run 44253941141 completed successfully. Its Laravel test job, MySQL 8.0 integration job, attendance offline/PWA/QR browser E2E, production cPanel package build, package-content verification and artifact upload all passed.

Verified release artifact:
- Name: aec-lms-cpanel-release
- Artifact ID: 14404813541
- Size: 15,034,439 bytes
- Expires: 2026-12-02T06:49:19Z
- Workflow: https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/44253941141

This CI evidence does not replace a real cPanel staging install/restore, live provider sandbox verification or the authorized conferencing load test. It is not a production deployment.

## Follow-up implementation order

1. Define and test Alom Mobile API v1 contracts, retaining /api/mobile compatibility during migration.
2. Port the reference Flutter student app and instructor app as independent applications; adapt authentication/device approval, course/lesson access, QR attendance and instructor offline sync to Alom endpoints.
3. Add Flutter CI (format, analyze, unit/widget tests and Android build) and publish no store listing until signing/configuration is real.
4. Implement guardian self-service portal with relationship and branch isolation tests.
5. Re-run the full Laravel/MySQL/browser/security/package pipeline after each backend integration.

This review deliberately does not mark the mobile apps or guardian portal as completed; they require the integration work above.
