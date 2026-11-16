# Laravel backend architecture and release gates

## Application architecture

- Laravel 12 application running on PHP 8.3+ with Composer and MySQL/MariaDB.
- Protected Core under `laravel/app/Core/` for authentication, RBAC, installation, plugin lifecycle, audit logging, backup and update services.
- Independent feature plugins under `laravel/plugins/modules/`; each module owns its routes, migrations, services, permissions and administration screens within the plugin contract.
- Laravel web/API routes, middleware, validation, policies and database transactions enforce authorization and data integrity on the server.
- Blade/Livewire application interface with server-backed state; no browser-only data store is authoritative for LMS records.
- Automated feature and regression tests under `laravel/tests/`.
- cPanel release package built from the Laravel application and its committed Composer lock.

## Implemented workflow foundations

The repository currently contains plugin manifests for academic, student, teacher, guardian, admission, course, class, batch, exam, attendance, certificate, library, HR/staff, fees, payments, notification, reporting, SEO, marketing, website CMS, page builder, slider builder, carousel builder, storefront and mobile API capabilities.

Some modules are foundations or partial workflows rather than complete production features. The release audit identifies the outstanding acceptance work. A manifest is not evidence that a workflow is complete.

## Important release limitations

Complete business workflows, persistent CRUD, authorization, branch scoping, integration and end-to-end coverage must be verified independently for every enabled module. Payment-provider sandbox callbacks, signature validation, settlement/refund reconciliation, general notification delivery, private course-media delivery, large MySQL backup/restore, browser E2E, security review and a clean cPanel rehearsal remain release gates.

## Production release gates

1. Complete and test every module's business workflows, permissions and branch scoping.
2. Verify all data mutations use validated Laravel requests, policies and database transactions where needed.
3. Complete provider-specific payment verification, immutable ledger entries and reconciliation; never trust client-submitted payment status.
4. Complete private upload validation, ownership checks, authenticated delivery and direct-access denial tests.
5. Verify installer, non-destructive migrations, plugin install/update/disable/uninstall rollback and installation locking.
6. Run PHP syntax/static analysis, feature tests, dependency/security scans and browser E2E tests.
7. Rehearse clean cPanel installation, upgrade, backup and restore on disposable MySQL/MariaDB data.
8. Verify privacy, retention, auditability, performance, accessibility, monitoring and operational recovery before release.
