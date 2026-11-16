# Laravel 12 — cPanel deployment guide

**Application:** ALOMS ENGLISH CENTER LMS  
**Runtime:** PHP 8.3+, Laravel 12, Composer 2, MySQL/MariaDB  
**Web document root:** `laravel/public`

> **Release status: NOT PRODUCTION-READY.** The automated Laravel CI and package checks are not a substitute for a live cPanel rehearsal. Do not replace the production domain or database until the release-readiness audit is closed.

## 1. Hosting prerequisites

Confirm the cPanel account provides PHP 8.3+, Composer 2, MySQL 8 or supported MariaDB, required PHP extensions, writable Laravel `storage/` and `bootstrap/cache/`, HTTPS and Apache rewrite support.

Use a dedicated database and database user. The fresh installer requires a completely empty database and will refuse existing tables.

## 2. Build the release package

From a clean checkout:

```bash
cd laravel
bash scripts/build-cpanel-package.sh
```

The script creates `dist/aec-lms-cpanel-release.zip` and its SHA-256 sidecar. It installs locked production dependencies and excludes environment secrets, backups, private uploads, installation locks and development files. Inspect the ZIP and checksum before transferring it.

## 3. Configure the application

Extract the package into a private application directory outside the public document root. Point the domain document root to that application's `public/` directory. Keep `.env`, source directories, `storage/`, `vendor/`, backups and private uploads outside public access.

From cPanel Terminal, copy `.env.example` to `.env`, set production database, URL and mail values, protect the file with mode 0600, and prepare writable Laravel directories. Install locked dependencies with Composer from the application root.

## 4. Install on a new empty database

Run the Laravel installer from the application root:

```bash
php artisan lms:install \\
  --app-name="Alom's English Center" \\
  --app-url="https://YOUR-DOMAIN" \\
  --admin-name="Administrator" \\
  --admin-email="ADMIN@YOUR-DOMAIN"
```

Enter the administrator password only when prompted. Never put passwords in shell history or command-line arguments. The installer creates the initial Admin account, persists generated keys privately and writes the permanent installation lock. Do not remove the lock or rerun the installer on an installed database.

## 5. Post-install verification

- Sign in with the initial Admin account.
- Confirm the Plugin Center and enabled modules load.
- Confirm `/install` is unavailable after installation.
- Verify `.env`, source, storage, backups and private uploads cannot be downloaded over HTTP.
- Test authentication, authorization, branch isolation and one complete workflow in every enabled module.
- Verify HTTPS, secure cookies, SMTP, queues/cron and private file authorization.
- For database queues, configure a cPanel Cron Job to run every minute: `cd /home/CPANEL-USER/private-app && /usr/local/bin/php artisan queue:work database --stop-when-empty --tries=3 --timeout=90 >> storage/logs/queue-worker.log 2>&1`. Replace the path and PHP binary with the values shown by the hosting provider. Confirm the `jobs`, `job_batches` and `failed_jobs` tables exist and monitor failed jobs. Do not expose the log publicly.
- Take a backup and rehearse restore into a separate disposable database and private directory.

## 6. Upgrade and recovery policy

Take and verify a pre-upgrade backup. Test signed updates on staging. Never edit an applied migration. Keep the previous release package and verified backup available. Do not deploy solely because CI is green.

## 7. Release blockers

The current automated suite is not complete acceptance coverage for every plugin. Provider sandbox callbacks, complete module workflows, cross-branch negative tests, large MySQL backup/restore, browser E2E, security review and a clean cPanel installation/restore rehearsal remain mandatory. See [RELEASE-READINESS-AUDIT.md](RELEASE-READINESS-AUDIT.md).
