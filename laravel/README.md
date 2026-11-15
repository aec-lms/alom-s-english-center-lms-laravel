# AEC LMS — Laravel 12 modular plugin platform

**Development status: not yet production-ready.** The Laravel application is located in this directory and is the intended production backend/frontend. The root-level Google AI Studio/React prototype is retained for reference and is not the LMS runtime.

## Application layout

- `app/Core/` — protected Laravel Core, authentication, RBAC, installer, plugin lifecycle, audit, backup and update services.
- `plugins/modules/` — independent plugin modules.
- `routes/` — Core web/API routes.
- `database/migrations/` and `database/seeders/` — Core schema and initial permissions.
- `resources/views/` — Laravel Blade UI.
- `tests/Feature/` — automated feature/regression tests.
- `scripts/build-cpanel-package.sh` — creates a Laravel-only cPanel package.

## Plugin catalog

There are currently **39 plugin manifests** in the repository. A manifest/catalog entry does not mean the module is fully implemented or production accepted; some workflows are foundations or partial implementations and still need full business logic, UI, authorization, branch scoping, integration and end-to-end tests.

The modules are independently identified under `plugins/modules/`:

1. Branch Management
2. Student Management
3. Teacher Management
4. Guardian Management
5. Admission Management
6. Course Management
7. Class Management
8. Batch Management
9. Exam Management
10. Attendance Management
11. Certificate Management
12. Library Management
13. HR & Staff Management
14. Fees Management
15. Payment Management
16. Visa/Mastercard Gateway
17. SSLCOMMERZ Gateway
18. Stripe Gateway
19. PayPal Gateway
20. Universal Payment Orchestrator
21. SMS Gateway
22. Email Notifications
23. Notice Board
24. Notification Center
25. Reports & Analytics
26. SEO Manager
27. Marketing Management
28. Gmail Marketing
29. Meta Pixel
30. Google Search Console
31. Admin Boosting
32. Visitor Popup Ads
33. Website CMS
34. Page Builder
35. Slider Builder
36. Carousel Builder
37. Storefront Catalog
38. Mobile App API
39. Role-based AI Assistant

Page Builder, Slider Builder, Carousel Builder and each payment gateway are separate plugins; gateway credentials and provider callbacks must remain isolated in their own plugin settings/services.

## Local setup

Requirements: PHP 8.3+, Composer 2 and the PHP extensions needed by Laravel and the selected database driver.

```bash
cp .env.example .env
composer install
```

Configure a **new, empty** MySQL/MariaDB database in `.env`, then run the installer:

```bash
php artisan lms:install \
  --app-name="Alom's English Center" \
  --app-url="https://YOUR-DOMAIN" \
  --admin-name="Administrator" \
  --admin-email="ADMIN@YOUR-DOMAIN"
```

The command prompts for the administrator password using hidden terminal input. Do not pass passwords on the command line. The installer refuses any database containing existing tables and never drops/truncates a database or imports legacy demo data.

Synchronize the plugin catalog and run tests:

```bash
php artisan lms:plugins:sync
php artisan test
```

## cPanel package

From this Laravel application directory:

```bash
bash scripts/build-cpanel-package.sh
```

Output: `dist/aec-lms-cpanel-release.zip` and its SHA-256 sidecar. The production document root must point to this application's `public/` directory. Keep `.env`, `storage/`, backups and private uploads outside the web document root.

See [../docs/LARAVEL-CPANEL-DEPLOYMENT.md](../docs/LARAVEL-CPANEL-DEPLOYMENT.md) and [../docs/LARAVEL-BACKUP-RESTORE.md](../docs/LARAVEL-BACKUP-RESTORE.md).

## CI and release gates

GitHub Actions runs the current Laravel feature suite on PHP 8.3 and builds/verifies the cPanel package. The test suite is not yet complete acceptance coverage for all 39 plugins. Provider sandbox callbacks, branch-isolation coverage across every module, large MySQL backup/restore, browser E2E, security review and a clean cPanel installation/restore rehearsal remain release blockers.

Do not merge or deploy as a production LMS until the release-readiness audit is closed.
