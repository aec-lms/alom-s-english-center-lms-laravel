# External LMS Comparison Audit — 2026-11-18

Compared:
- Reference: https://github.com/lalchandakand-LMS/finalLMS (public, main)
- Target: https://github.com/aec-lms/alom-s-english-center-lms-laravel (main)

## Integration rule

This is a feature-gap review, not a source-code overwrite. The repositories use different Laravel layouts and plugin contracts. Port only independently verified workflows, adapting them to the target plugin lifecycle, authorization, audit logging, migrations, tests, and cPanel package. Never copy secrets, environment files, generated runtime data, or replace target files wholesale.

## Strong overlapping capabilities already present in the target

The target already has first-party modules or core workflows for:
- Course, lesson, batch, branch, student, teacher, guardian, and admission management.
- Exams, question bank, phonetics practice, assignments, attendance, live classes, certificates, and learning progress.
- Fees/invoices, universal payments, gateway adapters, storefront, digital ebooks, flash sales, and marketing.
- CMS/website, page and slider builders, media library, SEO, notices, notifications, SMS, and email.
- Mobile API/device security, student tracking/tracing, AI assistant, backup/restore, health monitoring, plugin lifecycle, and recycle bin.
- Branding + loader as one plugin; Phonetics remains independent.

These should be enhanced in place, not duplicated under new names.

## Verified useful gap and current implementation

### Expense Management — being added in PR #133

The reference repository has a working expense workflow with branch-aware filtering, category/date filters, payment methods, reference fields, totals, and finance reporting. The target had no dedicated expense table/module in its current tracked source tree.

The target implementation adds an independent `expense-management` plugin with:
- Head-office and branch expense entries.
- Category/date filters and filtered totals.
- Payment method and reference metadata.
- Admin-wide access and active-branch-only branch-manager access.
- Audit events for create/update/delete.
- Dedicated migration, routes, provider, view, manifest, and feature tests.

PR: https://github.com/aec-lms/alom-s-english-center-lms-laravel/pull/133

## Further candidates requiring source-level verification before porting

| Candidate from reference | Target-side status from initial inventory | Decision |
|---|---|---|
| Refund management | Payment refund model/provider-reference migration exist; full request/approval/reconciliation workflow not yet confirmed | Audit before extending; do not create a competing refund ledger |
| Google Drive / S3 storage manager | Media library and protected lesson storage exist; provider-backed remote storage not confirmed | Evaluate provider adapters and quota/retention controls |
| Student support tickets | No dedicated support-ticket module found in target tree | High-value candidate; implement as independent plugin if no hidden equivalent |
| Student/instructor Flutter apps | Target has mobile API and device security, but no Flutter app source tree found | Evaluate API contract parity, then port app clients/screens separately |
| Course combos, wishlist, cart/coupons | Storefront and marketing modules exist; exact course-purchase workflows need verification | Extend existing commerce modules rather than duplicate checkout |
| Richer mobile instructor workflows | Mobile API exists; exact assignment/assessment/lesson/attendance endpoint parity needs audit | Add missing endpoints with authorization and replay tests |
| Guardian portal | Guardian management and student tracking exist; portal experience needs verification | Extend guardian-facing views with verified-child scoping |

## Reference repository maturity note

The reference README explicitly describes the project as actively developed and not yet a fully validated commercial release. It lists remaining work including full Bangla translation coverage, richer page templates, deeper branch workflows, official payment integrations, scheduled off-site backups, update-center rollback, and real cPanel staging validation. Therefore, a feature appearing in the reference repository is not automatically considered production-ready.

## Acceptance requirements for every port

- Match the target's Laravel/plugin architecture and supported PHP/Laravel versions.
- Include manifest, permissions, provider, routes, views, migrations, audit events, and lifecycle behavior as appropriate.
- Enforce branch/role/ownership boundaries server-side for web and API routes.
- Preserve existing tables, route names, URLs, and data; avoid parallel/duplicate workflows.
- Add positive, negative, isolation, lifecycle, and migration tests.
- Pass MySQL integration, full Laravel suite, browser E2E, security audit, and cPanel package verification.
- Keep real provider credentials, staging installation, and production activation as separate release gates.
