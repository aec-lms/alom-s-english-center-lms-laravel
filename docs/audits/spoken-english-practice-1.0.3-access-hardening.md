# Spoken English Practice 1.0.3 — AEC Access-Control Hardening

Date: 2026-11-18

## Scope

The original user-supplied Spoken English Practice Laravel plugin ZIP was reviewed against the current AEC LMS `main` branch. This follow-up addresses the host-course authorization gap identified during the 1.0.2 compatibility review.

## Host LMS behavior confirmed

- AEC `course-management` stores course enrollments in `enrollments`.
- Paid course enrollment is created as `pending` and linked to `fee_invoices.invoice_id`.
- `PaymentSettlementService` marks the invoice `paid` only after the full net amount is settled, then activates the linked enrollment.
- Enrollment states include `active` and `completed`.
- AEC courses and enrollments expose `branch_id`; the student profile can also expose `branch_id`.

## Changes in reviewed package 1.0.3

The updated handoff package applies these changes in `CourseAccessService` and plugin config:

1. A linked AEC course always uses host-LMS enrollment checks, including free courses; local plugin enrollment or guest-free settings cannot bypass host enrollment.
2. Both `active` and `completed` host enrollments are recognized.
3. A paid host course requires a linked invoice with status `paid` and `paid_amount >= net_amount`.
4. Course, enrollment, and student branch IDs are compared when the relevant host schema columns exist.
5. Fallback admin role names are limited to `super_admin` and `admin`; teachers, instructors, and branch managers need the explicit `spoken-english.manage` permission.

## Validation

- All 24 PHP and Blade files passed PHP 8.4.24 syntax lint.
- 27 package files have SHA-256 entries; all checksums verified.
- ZIP integrity test passed (28 regular files).
- Composer validation, Laravel boot, MySQL migrations, PHPUnit, and end-to-end enrollment/payment tests were not run because no AEC runtime/database is attached to this review environment.

## Important integration status

This branch contains this audit document only. The reviewed 1.0.3 plugin package is a separate handoff artifact; its source files have **not** yet been committed under `laravel/plugins/modules/spoken-english-practice/`, and this PR must not be treated as source integration or production approval. The next integration step is to add the complete package source, then run migrations and free/paid/branch-scoped acceptance tests against a disposable copy of the AEC MySQL schema.
