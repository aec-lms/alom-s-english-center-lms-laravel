# finalLMS vs ALOMS — implementation-quality audit (2026-11-18)

## Snapshot and method

- Reference repository: https://github.com/lalchandakand-LMS/finalLMS
- Reference snapshot: `4220bdd31028487685a1d44ce709550dd93af6cf`
- Target repository: https://github.com/aec-lms/alom-s-english-center-lms-laravel
- Target snapshot: `fc9c899a092a20b2a38a18bd620dceb994816964`
- Inventory: recursive Git trees and selected controllers, models, migrations, routes, views and feature tests.
- Inventory size at these snapshots: reference 851 tracked files; ALOMS 799 tracked files. File count is not a quality metric.
- This is a source-level comparison, not a claim that either application was deployed or tested against production services.

## Decision rule

An existing ALOMS feature is never excluded from review merely because it exists. Compare actual behavior, data integrity, authorization, branch isolation, UX, concurrency, observability, test coverage and deployment safety. If the reference implementation has a better pattern, improve the existing ALOMS feature in place. Add a new module only when there is a genuinely distinct capability. Do not transplant controllers, migrations, route definitions or models blindly across the two architectures.

## Feature-by-feature findings

| Capability | ALOMS implementation observed | finalLMS implementation observed | Quality gap / decision |
|---|---|---|---|
| Exams | Separate exam-management plugin; exam attempts, duration, max attempts, branch-aware admin paths and audit logging | Course question sets, timed attempts, per-course attempt policies, autosave, immutable attempt-question snapshots and teacher grading | ALOMS has exams but not the same course-linked reusable question-set workflow. Integrate a reusable assessment workflow with existing Question Bank/Exam plugins; avoid a parallel exam module. |
| Question bank | Dedicated BCS/Bank Job/Admission bank; MCQ and written questions; paper snapshots and print workflow | QuestionSet builder imports reusable bank questions and adds short-answer/MCQ/true-false question types | Keep ALOMS bank as canonical source; add course-linked assessment sets and snapshot semantics where missing. Validate branch ownership on source question selection. |
| Student assessment | Existing exam attempt model stores submitted answers and scores | Separate attempt and attempt-question records; partial autosave, restore, timed expiry, attempt history and manual grading | Review ALOMS attempt lifecycle for autosave, resume, tamper-resistant timing, per-question snapshots, written grading and history. Extend existing exam domain rather than copy reference models verbatim. |
| Assignment | ALOMS assignment-management plugin with Assignment and AssignmentSubmission models, student/teacher views and tests | Assignment controller/models, attachments, late-submission policy, student/instructor API and extensive mobile assignment tests | Same broad feature exists. Compare attachment validation, late policy, teacher grading, branch scope, API parity and race conditions; improve existing plugin only where a concrete gap is found. |
| Attendance | Canonical attendance entries, batch mapping/roster, offline sync UUIDs, QR sessions, GPS consent, branch checks and batch-level serialization; browser E2E | Attendance records, QR, offline instructor queue, stale-location review queue and audit/sync-review admin screens | ALOMS backend already has substantial protections. Evaluate review queue and auditable stale/offline conflict workflow; do not replace its canonical mapping or concurrency locks. |
| Support tickets | Support Center plugin: student-owned ticket access, branch-scoped admin list, replies, status and mobile API endpoints | Student/admin ticket controllers and feature tests | Existing feature on both sides. Compare ticket attachment support, assignment/priority/SLA, notification delivery, pagination, read receipts and branch authorization before deciding enhancements. |
| Course bundles | Dedicated course-bundles plugin with bundle/order/item models, catalog and order views | CourseCombo controller/model, combo payment snapshots and storefront views | Same capability. Compare immutable price/batch snapshots, refund behavior, inventory/capacity locking, coupon stacking and branch scope. Retain ALOMS plugin contract. |
| Course wishlist | Dedicated ALOMS course-wishlist plugin | Reference CourseWishlist controller/model/mobile tests | Same feature. Compare duplicate prevention, course visibility, mobile API and enrollment state behavior. |
| Expenses | ALOMS expense-management plugin with Expense model, finance and index views | Expense controller/model and FinanceReportController | ALOMS has expense entry; report aggregation and export dimensions need direct parity review. |
| Finance reports | Finance view in expense-management exists; payment/fee/universal-payment modules and settlement service exist | Dedicated FinanceReportController and finance report tests | Compare ledger-based reconciliation, date/branch filters, refunds, outstanding balances and totals against source transactions. Never calculate financial truth from presentation-only aggregates. |
| Payment gateways | Payment Management + Universal Payment, gateway orchestrator, settlement service, refund records and branch controls | PaymentGatewayManager, gateway contract, bKash service/controller, unique transaction references and payment security tests | Compare adapter contracts, callback signature verification, replay protection, idempotency, amount/currency verification and refund reconciliation. Do not enable a gateway without credentials/sandbox evidence. |
| Google Drive / storage | ALOMS has media library, backup and storage-related infrastructure; no matching Google Drive OAuth/quota integration was established in the scanned plugin tree | GoogleDriveConnection, quota service with refresh-token flow, quota endpoint and tests | Candidate addition only after secure OAuth state/PKCE, encrypted token storage, revocation, account isolation, timeout/retry and private-storage fallback are verified. Quota display alone is not a complete Drive storage backend. |
| Quiz contest | No equivalent contest plugin was found in the ALOMS tree | QuizContest, entries, winners, admin/student views, score/time ranking and lottery tests | Distinct optional engagement feature. Consider only after contest fairness, anti-automation, deterministic draw auditability, privacy and prize/legal rules are specified. |
| Mobile API | Mobile App API plugin, Sanctum device tokens, config, security and compatibility tests; API prefix differs from reference | API v1 controllers and separate Flutter student/instructor apps; device approval/MFA, assignment, lesson, live-class and quiz endpoints | Do not copy Flutter clients unchanged. Map each screen to ALOMS API contracts, preserve existing /api/mobile compatibility, add versioned adapters and contract tests. |
| Flutter apps | No Flutter application directory in ALOMS snapshot | Student and instructor Flutter app foundations, offline attendance queue and QR page | Valuable separate workstream. Verify app completeness before promising releases; add API integration, secure token storage, device approval, offline conflict handling, Flutter CI and Android/iOS build signing. |
| Guardian | Guardian management and guardian/student tracking authorization exist | Guardian relationships and read-only attendance/privacy tests; guardian portal manifest is present | Improve the existing guardian experience based on explicit child-link authorization. Do not assume a manifest proves a complete portal. |
| Theme / CMS | ThemeManager supports global and plugin-scoped colors, typography, width, radius and density; theme package manager, website builders and media library | WebsiteBuilder controller, preview, CMS/page builder and appearance-related views | ALOMS already offers no-code design controls. Compare component/block editing, reusable templates, preview/publish workflow, responsive controls, revisions and sanitization; do not create a second CMS. |
| Device sessions | Central admin session inventory, hashed browser session IDs, revoke enforcement, audit trail and feature tests; merged PR #134 | DeviceSession model/controller/middleware and access-change tests | Same security capability. ALOMS current implementation passed its MySQL/Laravel pipeline in run 44259762747; review additional reference device-change/MFA cases as targeted regression tests. |
| Plugin lifecycle | PluginManager, manifest validation, signed/integrity checks, resource access, protected archive install/recovery and extensive tests | Plugin runtime, archive staging, manifest validation, signature verification and plugin tests | Both have mature mechanisms. Compare exact failure modes and add targeted ALOMS regression tests; never replace its runtime wholesale. |
| Branch isolation | Cross-plugin branch scoping and tests across payments, fees, HR, exams, students, courses, attendance and support | Reference role/branch checks exist but implementation differs | ALOMS branch isolation is a non-negotiable invariant. Any port must include cross-branch negative tests and fail-closed behavior. |

## Confirmed ALOMS work already present — do not duplicate

- Question Bank for BCS, Bank Job and Admission, MCQ/Written and paper snapshots.
- Exam Management with attempts, duration and attempt limits.
- Assignment Management.
- Attendance online/offline sync, QR and roster-aware mapping.
- Support Center / ticket threads.
- Course Bundles and Course Wishlist.
- Expense Management, Fees, Payment Management and Universal Payment.
- Guardian Management and Student Tracking.
- Mobile App API.
- Theme color/design controls, Theme Package Manager, CMS/website builders and Media Library.
- Device Session Management (PR #134 merged).
- Plugin lifecycle/security, backup, content access controls and branch isolation.

## Prioritized follow-up work

1. **Course-linked assessment lifecycle (highest functional gap):** integrate reusable Question Bank questions with existing Exam Management; course attachment, availability window, max attempts, random question selection, immutable question snapshots, timed attempts, partial autosave/resume, attempt history and written-answer grading. Use the existing plugin permissions and branch rules. Add SQLite + MySQL tests and browser E2E.
2. **Finance reconciliation parity:** compare existing ALOMS finance view with reference FinanceReportController; derive reports from canonical payments, fees, refunds and expenses; enforce branch/date filters and reconciliation invariants.
3. **Google Drive storage integration assessment:** inspect ALOMS storage/backup contracts and reference OAuth connection flow; only implement if it adds a real private-storage provider, not just a quota widget.
4. **Attendance sync review parity:** compare stale/offline conflict review, reason capture, audit log and duplicate detection. Preserve ALOMS's batch mapping and row locks.
5. **Flutter applications:** build a contract map from each reference app call to ALOMS API, then port in separate app work with secure auth, offline queues, QR and CI.
6. **Support Center and Assignment quality review:** targeted source/test comparison, then small fixes to the existing plugins.
7. **CMS/theme UX parity:** compare editor workflow and safe content rendering, not just visual feature names.
8. **Optional Quiz Contest:** product decision and security/fairness specification before implementation.

## Safety and validation gates

- Every database change must be additive/backward-compatible and tested on SQLite and MySQL 8.
- Every student, teacher, guardian, branch-manager and admin path needs explicit authorization tests.
- Preserve audit logging, idempotency, concurrency locks and private file storage.
- Run PHP syntax lint, Blade compilation, Laravel tests, Composer audit, browser E2E and production package verification on the exact PR head.
- No production deployment, real payment, Google OAuth, SMS/email or provider test is claimed without authorized staging credentials.
- Do not produce the final install ZIP until all comparison-driven implementation work is merged and green.
