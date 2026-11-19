# Teacher Management — Premium-Level Upgrade

## Scope and integration principles

Teacher Management is the authoritative administrative directory and lifecycle entry point for instructor accounts. It must integrate with the existing course assignment, teacher portal, attendance, assessment, live-class, notification, branch, and referral modules rather than duplicate their ledgers or permissions.

All integrations remain optional plugin dependencies or runtime-guarded adapters. Disabling Teacher Management must not delete course, assessment, attendance, payment, or referral history.

## Changes in this candidate

- Require a strong initial password (12–128 characters) when an administrator provisions a teacher account; no inaccessible random password is silently generated.
- Fail closed if the canonical `teacher` role is missing instead of creating an account without the correct role.
- Validate portrait URL inputs as HTTPS URLs or local absolute paths; reject javascript/data/protocol-relative inputs.
- Preserve a teacher's existing branch when an update request omits `branch_id`.
- Restore the linked login account to its pre-recycle active/inactive state when a teacher profile is restored (where the recycle-state migration is present).
- Add branch-scoped directory search across name, email, employee code and specialization.
- Add active/inactive filters, specialization filtering, pagination query retention, and branch-scoped total/active/inactive KPI cards.
- Add regression coverage for password strength, duplicate email protection, unsafe social URLs branch retention, and teacher recycle/restore account-state consistency.

## Premium target capability matrix

| Capability | Integration owner | Acceptance requirements |
|---|---|---|
| Teacher onboarding and approval | Teacher Management + notifications | Invite/credential setup, profile completion, reviewer approval, immutable audit trail |
| Qualifications and credentials | Teacher Management | Multiple qualifications, institution, award date, evidence file, expiry/reminder and verification state |
| Secure document vault | Media Library / private storage | Private disk, signed/time-limited access, MIME/size validation, retention and access audit |
| Course assignment | Course Management | Explicit user-ID assignments, branch compatibility, effective dates, workload limits and history |
| Teacher portal | Core role dashboard | Assigned courses only; no name-based authorization; student counts from active enrollments |
| Attendance and workload | Attendance / Class Management | Scheduled vs delivered sessions, attendance corrections with audit history, workload caps |
| Assessment and grading | Course Assessments / Exam Management | Assigned-course-only grading, moderation, rubric, regrade history and branch isolation |
| Live teaching | Live Class | Host/co-host permissions, session attendance, incident reporting and provider capacity verification |
| Performance analytics | Reports & Analytics | Course completion, learner progress, attendance, feedback and evidence-based performance indicators |
| Compensation and referrals | Fees / Referral Income | Separate salary/contract and referral ledgers; verified settlement, approval, payout and reversal audit |
| Reviews and public profile | CMS / course catalogue | Consent-controlled public bio, verified badges, moderated reviews and profile visibility |
| Lifecycle and compliance | Core users / Recycle Bin | Suspend/reinstate, offboarding, access revocation, retention, restore consistency and audit events |

## Security invariants

1. A teacher may access only explicitly assigned courses and branch-authorized records.
2. A display name is never an authorization key.
3. Teacher profile state and login account state must be changed transactionally.
4. Removing a teacher must not cascade-delete course, student, attendance, assessment, payment or referral history.
5. Private identity/qualification documents must never be stored under the public web root.
6. Public profile fields require explicit publication/consent controls; internal HR data is never exposed publicly.
7. Salary/commission/referral balances are distinct financial concepts and must not be merged into one mutable balance.
8. Admin and branch-manager actions must be audited with actor, target, before/after state and timestamp.

## Remaining implementation and release gates

This candidate is a focused hardening and admin-directory upgrade, not completion of every premium capability above. The qualifications/document vault, onboarding approval, explicit assignment UI, performance analytics, contract/payroll ledger, teacher review workflow and full lifecycle restore tests require dedicated implementation and migrations.

Before merge, run the exact-head PHP syntax, Blade compilation, SQLite and MySQL feature suites, browser E2E, plugin lifecycle tests, and security audit. Before production, verify email/password-reset delivery, private document storage on cPanel, branch isolation with real branch fixtures, and provider-backed live-class capacity. No production deployment is authorized by this roadmap.
