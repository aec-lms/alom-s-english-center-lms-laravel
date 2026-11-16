# 39-Plugin Source-Code Audit — First Pass

**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Audited ref:** `main` at `2471a9a929d1b36684aa82f2f9889d4c75ecfb01`  
**Review date:** 2026-11-16  
**Status:** Source inspection completed for the 39 plugin manifests, route files and primary controllers. This is a first-pass source audit, not functional acceptance or production certification.

## Scope and limits

Reviewed the plugin inventory, each `plugin.json`, route definitions, primary controller/service code for all 39 modules, shared manifest parsing/registration, and selected migrations/models/views. No live payment credentials, provider sandboxes, production database, browser E2E environment, or cPanel installation was available in this review. Findings below must be confirmed/fixed with automated tests before release.

## Cross-plugin findings

1. **Portability/independence requirement not met:** modules are Laravel-specific and use the LMS plugin runtime, Eloquent models and core services. Several declare sibling-plugin dependencies or directly import sibling models. Examples include Attendance → Branch/Student/Course; Student/Teacher/Guardian-adjacent modules → Branch; Certificate → Student; gateway modules → FeeInvoice/Payment modules. They are not currently drop-in plugins for an arbitrary LMS. Introduce documented contracts/adapters and optional integrations; keep standalone functionality when optional providers are absent.
2. **Branch isolation is inconsistent:** multiple controllers scope records only when a branch manager has a non-null `branch_id`. Some modules fail closed, others silently omit the filter. Add one shared branch-scope policy and negative tests for missing branch assignment and cross-branch IDs on every read/write/export path.
3. **Plugin acceptance coverage is insufficient:** a manifest, route file, controller, model and migration do not prove the feature is complete. Most plugins have no dedicated test file. Add per-plugin CRUD/workflow, permission, branch-isolation, validation, rollback and failure tests.
4. **Payment integrations require provider-backed verification:** callbacks, signatures, amount/currency/order matching, idempotency, refunds, and ledger reconciliation need sandbox tests using provider-issued test credentials. A manual ledger record must never be presented as a verified provider settlement.
5. **Branding rule:** the AI feature must be presented as the LMS's own **AEC LMS AI Assistant** throughout its UI, help text, logs and documentation. Provider names may appear only in the Admin integration configuration where they identify the actual API being connected. No external AI product branding should be used as the LMS product name.

## Individual plugin findings

| # | Plugin | First-pass source finding | Priority |
|---:|---|---|---|
| 1 | `admin-boosting` | Public click-tracking route has no visible throttling. Campaign date validation does not enforce end-after-start. Destination redirects need an explicit safe URL policy. | Medium |
| 2 | `admission-management` | Public application endpoint has no visible rate limit/anti-abuse control. Branch-manager queries are not fail-closed when branch assignment is missing. Public branch selection needs server-side policy. | High |
| 3 | `attendance-management` | Controller queries `batches`, while Batch Management creates `study_batches`; this table mismatch can break attendance writes. Branch scope also needs fail-closed handling. | Critical |
| 4 | `batch-management` | Create/list/delete are present but no update route is defined. Branch-manager list/store paths need explicit assigned-branch enforcement when branch_id is null. | High |
| 5 | `branch-management` | Branch deletion detaches users/students but does not visibly reconcile all branch-owned records in other modules. Require a dependency-aware delete/disable workflow and integrity tests. | High |
| 6 | `carousel-builder` | CRUD source exists, but public rendering, ordering constraints, upload replacement and migration compatibility need end-to-end coverage. | Medium |
| 7 | `certificate-management` | Certificate issue form stores student/course names as free text rather than enforcing a verified student/course relationship. Branch-manager list path needs fail-closed enforcement. | High |
| 8 | `class-management` | Create/list/delete only; no update route. Branch assignment and branch-manager isolation need negative tests. | Medium |
| 9 | `cms-website` | CMS stores rich HTML content; verify all public rendering uses safe sanitization/escaping. Category/post relationship IDs need exists/ownership validation, not integer-only validation. | High |
| 10 | `course-management` | Course and live-class workflows exist, but branch-manager filtering is conditional on non-null branch_id. Cross-module CMS coupling and teacher/course ownership need explicit contracts and tests. | High |
| 11 | `email-notifications` | SMTP settings and campaign/template paths exist; verify actual send result, queue/retry behavior, recipient consent, unsubscribe and delivery logging. | High |
| 12 | `exam-management` | Server-side grading exists, but attempt flow needs enforcement of exam duration, enrollment/eligibility, attempt limits and answer bounds; branch isolation requires negative tests. | Critical |
| 13 | `fees-management` | Invoice creation is present; verify branch-manager list is fail-closed and fee category/student relationship constraints. Complete update, adjustment, scholarship and audit workflows. | High |
| 14 | `gateway-card` | Manifest correctly describes a generic adapter rather than a ready-to-use processor. Checkout controller does not visibly verify that the authenticated payer owns/is authorized for the invoice. | Critical |
| 15 | `gateway-paypal` | Ownership checks and idempotency hooks are present. Verify capture-return ownership, webhook signature, order amount/currency matching, replay handling and sandbox lifecycle. | High |
| 16 | `gateway-sslcommerz` | Hosted checkout and public callback routes exist. Verify IPN signature/validation, transaction-state transitions, amount/currency matching and callback replay in sandbox. | High |
| 17 | `gateway-stripe` | Student/branch invoice checks and webhook service are present. Verify PaymentIntent state, signature timestamp tolerance, idempotency and refund synchronization in sandbox. | High |
| 18 | `gmail-marketing` | Campaign sends are synchronous and capped at 100 recipients; add real queueing, response/delivery tracking, consent/unsubscribe and rate limits. | High |
| 19 | `google-console` | Settings UI saves IDs/tokens, but validate ID formats and prove tag injection/verification/GA4/GTM behavior on the public site. This is a provider integration, not LMS branding. | Medium |
| 20 | `guardian-management` | Assigned-branch guard and student-link branch check exist. Complete guardian profile update/unlink flows and test that guardians can see only linked wards. | High |
| 21 | `hr-staff-management` | Payroll creation records status as `paid` immediately without a separate verified disbursement step. Require draft/approved/paid states and branch-scoped approval. | Critical |
| 22 | `library-management` | Branch manager is not consistently rejected when branch_id is missing; loan borrower is free text rather than a verified member/student. Add branch and borrower validation. | High |
| 23 | `marketing-management` | Campaign/coupon creation exists, but update, pause, redemption, expiry/use-limit enforcement and conversion tracking are not present in the reviewed route/controller surface. | Medium |
| 24 | `meta-pixel` | Settings save path exists. Verify consent-aware browser event injection, deduplication and server-side CAPI event delivery; never expose CAPI token to browser. | High |
| 25 | `mobile-app-api` | Public registration/login have no visible throttling or email verification. Public notices endpoint returns records without visible audience/branch filtering. Add rate limits and privacy/authorization tests. | Critical |
| 26 | `notice-board` | Branch-manager queries are not fail-closed when branch_id is missing; create path can accept a caller-supplied branch if no assignment is present. No update/delete route surfaced. | High |
| 27 | `notification-center` | Dispatch writes a queued/sent record, but the reviewed controller does not actually deliver email/SMS/push. List is global; implement channel adapters, queue workers and user/branch scope. | Critical |
| 28 | `page-builder` | CRUD source exists; rich content and JSON blocks need sanitization, schema validation and public-route rendering tests. | High |
| 29 | `payment-management` | Settlement uses transaction locks and checks invoice overpayment. Online refund ledger mutation is now blocked until a verified provider refund adapter exists; offline refund still requires authorized manual review and audit. | High |
| 30 | `reports-analytics` | Branch-aware metrics/export exist; verify missing-branch behavior, PII minimization and export authorization. Global enabled-plugin count should be intentional. | Medium |
| 31 | `role-ai-assistant` | Ask route lacks the same explicit permission middleware as the index route; empty allowed_roles currently permits everyone; logs are globally listed and store prompt excerpts. Manifest advertises Anthropic but service only branches OpenAI vs Gemini, so unsupported providers fall through to Gemini. The end-user offline reply also exposes provider names/environment-variable details; keep those details inside Admin configuration and show only AEC LMS AI Assistant branding to users. Model/provider validation and private log scoping are required. | Critical |
| 32 | `seo-manager` | SEO rules and sitemap exist; validate canonical URLs and route paths, sanitize robots.txt policy, and test sitemap output for published/allowed pages only. | Medium |
| 33 | `slider-builder` | CRUD exists; validate destination URL scheme, image replacement, duplicate slider slots and public rendering. | Medium |
| 34 | `sms-gateway` | UI offers multiple providers, but reviewed send logic only implements Greenweb; other configured providers remain queued. Greenweb response status is not checked before marking sent. | Critical |
| 35 | `storefront-catalog` | Manifest uses `service_provider` instead of the runtime's `provider` key; its provider extends plain Laravel ServiceProvider instead of the required plugin interface. Provider resolution/view boot therefore cannot be relied on. Normalize manifest/provider contract and test install/enable/routes/views. | Critical |
| 36 | `student-management` | Assigned-branch guard and student CRUD are present. Verify branch ID cannot be reassigned cross-branch, user/student lifecycle rollback and guardian/enrollment references on delete. | High |
| 37 | `teacher-management` | Assigned-branch checks and teacher profile/user creation exist. Delete removes the linked user; verify course/live-class references, account deactivation policy and rollback behavior. | High |
| 38 | `universal-payment` | Public checkout accepts client-supplied item title/amount/gateway and only creates a pending record; it does not initiate a real provider checkout. Admin record flow accepts a caller-supplied verified status. This must not be treated as payment processing or settlement. | Critical |
| 39 | `visitor-popup-ads` | Create form supports image upload, but edit form omits image input and exposes badge/description/CTA text only as hidden fields, so Admin cannot properly edit them. Update validation also omits image_url. Scheduling is described but start/end scheduling fields are absent. | High |


## Remediation progress — 2026-11-16

The following fixes were committed on the audit branch after the first-pass review:

| Finding | Change made | Verification |
|---|---|---|
| Attendance used the wrong batch table | Controller and fresh-install migration now use `study_batches`; Attendance declares Batch Management as a required dependency; a forward migration repairs the legacy foreign key | Regression test plus full CI |
| Attendance branch manager could be unassigned | Index and write actions now fail closed for branch managers without a branch; submitted branch_id is no longer trusted | Existing branch workflow test updated and full CI |
| Storefront provider did not match the plugin contract | Manifest uses the runtime-recognized `provider` key; provider extends `BasePluginServiceProvider` and supplies its slug/path | Provider-interface regression test |
| AI ask route lacked permission middleware | Ask route now requires `ai.assistant.use` | Route definition regression test |
| AI role allowlist empty value granted access | Empty allowlist now denies access | Service regression test |
| AI prompt excerpts were stored in logs | Prompt content is withheld in the activity log; non-admin activity view is user-scoped | Source review and regression test |
| AI provider/brand mismatch | Only implemented OpenAI/Gemini integrations are accepted; unsupported provider configuration fails safely; user-facing identity is AEC LMS AI Assistant | Source review |
| SMS provider result was reported as sent without checking response | Greenweb requests check HTTP acceptance and use chunked recipients; status is `submitted` rather than claiming handset delivery; unimplemented provider adapters remain queued | Source review; live provider test still required |
| Payment Management could record an online refund from a caller-supplied reference | Online refunds now fail closed until a gateway-specific provider refund adapter executes and verifies the refund; no refund row, transaction state, or invoice balance is changed. Regression assertions cover the safety gate. | Automated source regression; provider refund adapters and sandbox tests remain outstanding |
| Universal Payment manual receipt review lacked an evidence reference | Manual receipt creation now requires a payment/deposit reference; verification refuses records without one. Admin UI clarifies that manual receipt verification is not Fee Invoice settlement. | Regression assertions; invoice-bound manual settlement workflow remains outstanding |
| Notification Center lacked delivery worker and branch isolation | Added queued email job, encrypted SMTP settings integration, 3-attempt retry/backoff, attempt/error timestamps, database queue and failed-job tables; branch managers are scoped to their own active branch and cross-branch dispatch is denied | Database-backed email-job and branch-isolation tests; SMTP/cPanel live delivery remains outstanding |
| Universal Payment accepted arbitrary public amounts | Public checkout now requires an authenticated student, an owned fee invoice, an allowed installed/configured gateway, and server-calculated outstanding amount; online gateway choices are limited to implemented adapters | Regression test added; provider sandbox still required |
| HR payroll was marked paid at creation | Payroll is now prepared as pending; only an administrator can confirm disbursement, with audit logging and a safe pending DB default | Regression test covers pending state and admin-only paid confirmation |
| Mobile App API exposed paid lesson content and unfiltered notices | Public course/catalog responses omit private file links and lesson content; a separate Sanctum route serves lesson content only to active enrollees; public notices are restricted to published global notices; register/login routes are rate-limited | Mobile security regression test covers public metadata, enrolled lesson access, non-enrolled denial and audience filtering |
| AI integration environment key could cross provider boundary | Environment fallback now uses the selected provider's matching key and model defaults; Admin provider selector lists only implemented providers | Source review; full regression suite |
| Visitor Pop-Up Ads edit UI hid important fields | Admin can now edit badge, description, CTA text/link, image upload or image URL, delay, frequency and active state; CTA/image URLs reject unsafe schemes; upload extension is MIME-derived | Regression test covers editable content and rejects javascript: CTA URLs |
| Public admission application endpoint lacked abuse protection | Added request throttling to the public admission application route | Included in latest full CI run |
| Class Management accepted arbitrary weekday/time strings and could store an end time before its start | Create/update now restrict weekdays to the seven valid days, require `HH:MM` values and require end time after start time | Regression test covers invalid weekday, invalid time format and reversed time range |
| Library Management did not consistently fail closed for branch managers and swallowed authorization exceptions during loan issue | Enforced active branch assignment on library reads/writes; scoped both book and loan lists; forced branch managers to their assigned branch; blocked cross-branch loan issue/return and preserved HTTP 403 responses; fixed admin form actions to avoid unresolved named-route errors | Regression test covers unassigned managers, cross-branch catalog visibility, branch override and denied cross-branch loans |


**Composer security hardening (automated and passing):** The Laravel CI now validates the Composer manifest/lockfile and invokes the named `composer security-validate` and `composer security-audit` scripts. A separate `.github/workflows/composer-security.yml` workflow runs on pushes, pull requests, manual dispatch, and every Monday at 04:17 UTC. It audits both production and development dependencies from `composer.lock` using `composer audit --locked --no-interaction`; any reported vulnerability fails the check. The last verified audit output was `No security vulnerability advisories found.` The dedicated workflow passed on commit `d0ed178b849281f59790b9fd3f68ca3c440aa2ed` in [Composer Security Audit run 43834760978](https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/43834760978): manifest/lockfile validation succeeded and audit output confirmed `No security vulnerability advisories found.` Weekly scheduled checks remain enabled.

**Latest CI:** GitHub Actions run `43834842497` succeeded on audit branch commit `22e1de3dd38afc268f01822001fbb252e1893712`. Composer manifest/lockfile validation and locked dependency security audit passed with no advisories; Laravel suite passed **71 tests (412 assertions)**; the cPanel release ZIP was built and package contents verified (**6,272 files**). Artifact: https://github.com/aec-lms/alom-s-english-center-lms-laravel/actions/runs/43834842497/artifacts/14210103924 (GitHub artifact digest: `sha256:4601019e08757e48744955a7da020f157c594955b81d35ebff9d101c13824b69`). This does not replace provider sandbox, production database, cPanel installation, or browser E2E testing.

## Critical/high items still open

- Universal Payment now binds online checkout to the authenticated student's own fee invoice and calculates the outstanding amount server-side. Online refunds are blocked rather than falsely recording an unverified refund; real provider refund adapters, provider sandbox verification of checkout/callback signatures, replay/idempotency, amount/currency matching and ledger reconciliation remain outstanding. Manual receipt review now requires a transaction/deposit reference but does not itself settle a Fee Invoice.
- bKash, Nagad, Rocket, custom card acquiring, SMS Twilio/SSL Wireless/custom adapters need real provider implementations and sandbox verification before being advertised as active.
- Notification Center now has a real queued email worker and SMTP integration. SMS/Push adapters, provider delivery callbacks, operational queue-worker verification on cPanel and end-to-end provider acceptance tests remain outstanding.
- All other critical/high findings in the individual plugin table remain open until their fixes and per-plugin tests are merged. Attendance, Storefront, AEC LMS AI Assistant, SMS/notification status, Universal Payment request binding, online-refund fail-closed handling and manual receipt reference validation, HR payroll, Mobile App API, Visitor Pop-Up Ads, admission throttling, class schedule validation and the listed Library branch-safety items have been remediated in this branch; external provider and full acceptance checks remain outstanding.
- Cross-branch isolation needs systematic negative tests for every CRUD, detail, export, search, and bulk action.
- Full module CRUD, browser E2E, production MySQL/MariaDB and cPanel install/upgrade/backup/restore rehearsals remain release gates.

## Confirmed implementation mismatches to fix first

1. Complete systematic fail-closed branch-isolation tests for every branch-owned plugin and every detail/export/bulk endpoint.
2. Add dedicated CRUD/workflow acceptance tests for all 39 plugins; the current suite is still not complete module-by-module.
3. Complete provider sandbox testing and implement the still-unavailable bKash, Nagad, Rocket and other advertised integrations before enabling them.
4. Complete Notification Center SMS/Push adapters and delivery callbacks; verify SMTP/queue operation on cPanel and never label queued/submitted as delivered.
5. Complete cPanel/MySQL install, upgrade, backup/restore, browser E2E and operational smoke tests.

## Release gate

Do not merge this audit branch as a production release and do not mark the LMS production-ready based on static review alone. Fix the critical/high findings, add plugin-specific automated tests, run the complete CI suite, test payment providers in sandbox, rehearse fresh install/upgrade/backup/restore on a clean cPanel PHP 8.3 environment, and complete browser end-to-end and operational smoke tests.
