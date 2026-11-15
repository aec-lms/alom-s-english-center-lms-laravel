# AEC LMS — Alom's English Center (Laravel 12 Modular Plugin Platform)

Complete Laravel 12 (`PHP 8.3+`) modular Learning Management System located under `/laravel/`.

## Architecture Overview

### Protected Core (`/laravel/app/Core/`)
The Core is permanently protected against plugin disabling or uninstallation:
- **Authentication & RBAC**: `User`, `Role`, `Permission`, `RolePermissionMiddleware`, Sanctum API tokens (`personal_access_tokens`).
- **Fresh Empty-Database Installer**: `FreshInstallerService`, `/install` wizard, and `php artisan aec:install-fresh`. Refuses installation if the database already contains any tables, never drops/truncates databases, and never imports legacy records (`legacy_records_imported = false`).
- **Universal Plugin Lifecycle Engine**: `PluginManager`, `PluginManifest`, `BasePluginServiceProvider`, `EnsurePluginEnabled` middleware, ZIP path-traversal guard, `checksums.json` SHA-256 package integrity verification, dependency & active-dependent enforcement, pre-update snapshot & automatic rollback (`plugin-rollbacks`), `PluginLifecycleEvent` broadcasts, encrypted settings via Laravel `Crypt`, and data-preserving uninstall (`onUninstall`).
- **Security & Governance**: `AuditLogger`, encrypted ZIP+AES-256-CBC+HMAC-SHA256 `BackupService`, and SHA-256 + HMAC-SHA256 signed `UniversalUpdateService`.

### Independent Modular Plugins (`/laravel/plugins/modules/` — 33 Modules)
1. `branch-management` — Campus branches & branch isolation foundation
2. `student-management` — Branch-scoped admissions & student profiles
3. `teacher-management` — Instructor profiles & specializations
4. `guardian-management` — Guardian profiles & student-guardian links
5. `admission-management` — Online & campus admission applications + approval workflow
6. `course-management` — Academic sessions, courses, lessons, batches, enrollments
7. `class-management` — Independent academic classes, sections, classrooms & weekly routines
8. `batch-management` — Independent intake batches, seat capacities & schedules
9. `exam-management` — Exams, question bank, server-side graded attempts
10. `attendance-management` — Daily batch attendance registers
11. `certificate-management` — Digital certificates, `/result` verification codes & revocation
12. `library-management` — Printed catalog inventory, borrow/return & overdue fine ledger
13. `hr-staff-management` — Staff directory, leave status & monthly payroll runs
14. `fees-management` — Fee categories, student invoices, discounts
15. `payment-management` — Immutable ledger, offline desk settlements, atomic over-refund-safe refunds
16. `gateway-sslcommerz` — Dedicated SSLCOMMERZ Hosted Checkout + IPN hash verification
17. `gateway-card` — Dedicated Visa/Mastercard Tokenized Checkout + HMAC webhook (rejects raw PAN/CVV)
18. `gateway-stripe` — Dedicated Stripe Tokenized Checkout + `Stripe-Signature` timestamped HMAC webhook
19. `gateway-paypal` — Dedicated PayPal Hosted Order + `X-PayPal-Signature` HMAC webhook
20. `universal-payment` — Unified multi-gateway routing & fee checkout orchestrator
21. `sms-gateway` — SMS notifications & encrypted gateway settings
22. `email-notifications` — Reusable email templates, encrypted SMTP config & dispatch logs
23. `notice-board` — Role-targeted & pinned notices
24. `notification-center` — In-app notifications & dispatch hub
25. `reports-analytics` — Operational metrics & CSV audit exports
26. `seo-manager` — Sitemap, meta tags, OpenGraph & schema configuration
27. `marketing-management` — Campaigns, UTM tracking & promo coupon codes
28. `gmail-marketing` — Gmail campaign workflows
29. `meta-pixel` — Facebook Meta Pixel event tracking
30. `google-console` — Google Search Console & GA4 telemetry configuration
31. `admin-boosting` — Promotional ad boost tracking
32. `visitor-popup-ads` — Conversion pop-up banner builder
33. `cms-website` — Blog posts, homepage customizer & contact inquiries (separated cleanly from Builders)
34. `page-builder` — Standalone custom landing page block builder (`/page/{slug}`)
35. `slider-builder` — Standalone homepage hero slider manager
36. `carousel-builder` — Standalone testimonial & showcase carousel manager
37. `storefront-catalog` — Physical books, flashcards & merchandise storefront
38. `mobile-app-api` — Sanctum-authenticated learner API endpoints (`/api/mobile/*`)
39. `role-ai-assistant` — Optional server-side Role-Based AI Assistant with encrypted keys & role allowlists

## Local Setup & Verification (PHP 8.3+ & Composer Required)

```bash
cd laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan aec:install-fresh --name="Admin" --email="admin@alomsenglish.com" --password="StrongPassword!2026"
php artisan aec:sync-plugins
php artisan test
```

## cPanel Clean Production Package

```bash
bash scripts/build-cpanel-package.sh
# Outputs clean production archive: laravel/dist/aec-lms-cpanel-release.zip
```
