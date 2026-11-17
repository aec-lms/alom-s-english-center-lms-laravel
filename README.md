# AEC LMS — Alom's English Center

**Production application:** Laravel 12 in `/laravel` (PHP 8.3+, Composer, Blade/Livewire, MySQL/MariaDB).

This repository contains the complete production application for ALOMS ENGLISH CENTER. The Laravel application is the sole supported runtime for the LMS. All authentication, data, business workflows, plugin lifecycle, installation, administration, deployment and maintenance must be implemented and operated through Laravel.

## Laravel application

- Application root: `laravel/`
- Web document root: `laravel/public/`
- Core: `laravel/app/Core/`
- Independent modules: `laravel/plugins/modules/`
- Routes: `laravel/routes/`
- Automated tests: `laravel/tests/`
- Composer lock: `laravel/composer.lock`

The Core owns authentication, RBAC, plugin lifecycle, installation locks, audit logging, backup and update services. Optional modules are independently registered plugins. Page Builder, Slider Builder, Carousel Builder, Visa/Mastercard, SSLCOMMERZ, Stripe and PayPal have separate plugin identities.

## Local setup

Requirements: PHP 8.3+, Composer 2, and the PHP extensions required by Laravel and the selected database driver.

```bash
cd laravel
composer install
cp .env.example .env
php artisan key:generate
```

Configure a **new, empty** MySQL/MariaDB database in `laravel/.env`, then install:

```bash
php artisan lms:install --app-name="Alom's English Center" --app-url="https://YOUR-DOMAIN" --admin-name="Administrator" --admin-email="ADMIN@YOUR-DOMAIN"
php artisan test
```

The installer refuses any database containing existing tables. It does not drop/truncate an existing database or import legacy demo data. Keep `.env`, `storage/`, backups and private uploads outside public access. Never commit secrets.

## Build the cPanel package

From the repository root:

```bash
bash laravel/scripts/build-cpanel-package.sh
```

The archive is written to `laravel/dist/aec-lms-cpanel-release.zip`. The package builder requires Composer to install locked production dependencies. Inspect the package before deployment; the script is not a substitute for a cPanel rehearsal.

## CI

GitHub Actions tests the Laravel application using PHP 8.3 and the committed `laravel/composer.lock`. The feature branch `fix/laravel-ci-php83` contains the current CI/Core hardening work. Do not deploy or merge it as a production release until the full module acceptance tests, security review, fresh cPanel installation, backup/restore rehearsal and live-host smoke checks pass.

## Release status

**Not yet production-ready.** A passing CI suite proves only the tests currently in the repository. Several plugin modules still need complete business workflows and acceptance coverage; payment-provider sandbox/live callbacks, cPanel deployment, full restore verification, browser E2E and operational smoke checks remain release gates. See `docs/RELEASE-READINESS-AUDIT.md` and `docs/LARAVEL-CPANEL-DEPLOYMENT.md`.


## Production backup and restore rehearsal

See [Production backup requirements and restore rehearsal](docs/PRODUCTION-BACKUP-RESTORE.md) for cPanel PHP extension setup, runtime verification, and the isolated restore rehearsal procedure.
