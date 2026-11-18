# finalLMS Feature Parity Tracker

Reference repository: https://github.com/lalchandakand-LMS/finalLMS
Alom's LMS: Laravel 12, plugin-first application under `laravel/`.

This is an implementation tracker, not a claim that every listed capability is already complete. A feature is considered delivered only after its backend workflow, authorization boundaries, UI/client flow and automated acceptance tests are present.

## First integration slice: versioned mobile API compatibility

- [x] Add `/api/v1` compatibility routes for currently implemented mobile backend operations.
- [x] Preserve the existing `/api/mobile` routes.
- [x] Return both `token` and `access_token` from mobile registration/login for old and versioned clients.
- [x] Keep Sanctum authentication on profile, logout, push-token and enrolled lesson routes.
- [x] Add automated tests for login/session restore, public catalog, protected lessons and token revocation.
- [ ] Run Laravel, MySQL 8.0, browser E2E and production package checks on this branch.
- [ ] Build and test actual Flutter clients against the versioned API.

## Student app feature inventory from reference client

Reference app route usage observed in `apps/student_app/lib/main.dart`:

- [ ] Student authentication and session restore using Alom's device/session policy.
- [ ] Course catalogue and course detail.
- [ ] My courses / active enrollments.
- [ ] Course wishlist.
- [ ] Student support tickets and ticket messages.
- [ ] Cart, coupon validation, checkout options and manual/bKash checkout.
- [ ] Invoice detail and payment history.
- [ ] Enrolled lesson viewing and lesson completion/progress.
- [ ] Assignment list, detail and submission with attachments.
- [ ] Exam list, attempt start, answer save and submission.
- [ ] Notices scoped by branch and audience.
- [ ] Course reviews.
- [ ] Question-set/quiz list, attempt start, answer save and submission.
- [ ] QR attendance check-in with strict QR expiry/replay and foreground location validation.
- [ ] Private lesson video playback with authenticated streaming.
- [ ] App configuration, maintenance state and minimum-version enforcement.
- [ ] Secure token storage and logout/revocation.

## Instructor app feature inventory from reference client

Reference app route usage observed in `apps/instructor_app/lib/attendance_page.dart` and `attendance_offline_queue.dart`:

- [ ] Instructor authentication and approved-device policy.
- [ ] Assigned batches and batch roster.
- [ ] Attendance policy retrieval.
- [ ] Foreground-only location capture and age/accuracy validation.
- [ ] Encrypted, bounded offline attendance queue; bearer tokens must never be stored in queue records.
- [ ] Idempotent batch synchronization with explicit synced/conflict/failed/review states.
- [ ] Conflict resolution with reason and audit history.
- [ ] Instructor-generated expiring attendance QR.
- [ ] Connectivity/resume-triggered background synchronization.
- [ ] Instructor course/lesson management and assessment workflows, reusing existing Alom web permissions and service rules where available.

## Guardian portal feature inventory

The reference `guardian_portal/plugin.json` identifies itself as metadata-only. Do not copy it as a completed feature. Alom's guardian self-service portal must be implemented only against verified guardian-student relationships:

- [ ] Read-only child profile and enrolled courses.
- [ ] Child attendance and progress.
- [ ] Notices and academic announcements.
- [ ] Invoices and payment status.
- [ ] Branch and relationship isolation tests for every endpoint.
- [ ] No access to another guardian's child or unrestricted student search.

## Existing Alom capabilities to reuse rather than duplicate

The repository already contains dedicated plugins/workflows for course management, batch and branch management, attendance, exam management, question bank, Phonetics, assignments, fee/payment, invoices/refunds, live classes, certificates, notices, support, guardian management, storefront/ebooks, CMS/builders, reporting, SEO and plugin lifecycle/security.

These are not automatically marked fully accepted here: each still requires business-workflow acceptance coverage. Reuse their services and permissions rather than creating parallel tables, controllers or duplicate plugin identities.

## API contract gaps before copying the reference apps

The current mobile API exposes a limited set of endpoints under `/api/mobile`. The new `/api/v1` compatibility surface intentionally aliases only operations that already exist. It does not pretend the remaining student/instructor workflows exist. In particular, the reference app's wishlist, ticket, checkout, assignment, exam-attempt, quiz-attempt, QR check-in, instructor batch/roster, offline sync and conflict-resolution endpoints still need implementation or explicit adapters.

The app clients must not be copied unchanged: the reference client expects authentication and route contracts that do not fully match Alom's current backend. Every endpoint must be mapped to Alom's existing business service and authorization rules, then covered by tests before a screen is enabled.

## Security and architecture invariants

- Preserve plugin ownership, manifest/lifecycle checks and permissions.
- Preserve branch, role, enrollment and guardian-child scoping on every read and write.
- Keep private lesson/video/ebook delivery behind authorized server endpoints.
- Keep payment callbacks and provider secrets server-side; mobile clients never mark an order paid.
- Keep idempotency and audit logs on payment and attendance mutations.
- Do not add fake store links, app signing credentials, push credentials or provider secrets.
- Keep AL Branding & Loader as one plugin with distinct feature areas; keep Phonetics independent.

## Acceptance rule

A checkbox is marked complete only after implementation is merged and automated tests pass. Native binaries, app-store publication, production provider callbacks, real cPanel install/restore and live conferencing load tests remain separate operational release gates.
