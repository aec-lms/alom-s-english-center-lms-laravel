# Laravel 12 — cPanel deployment guide

**Application:** AEC LMS under `/laravel`  
**Runtime:** PHP 8.3+, Laravel 12, Composer 2, MySQL/MariaDB  
**Web document root:** `laravel/public`

This guide applies only to the Laravel application. The repository-root Google AI Studio/React prototype and older root-level PHP API are not the production LMS.

> **Release status: NOT PRODUCTION-READY.** The automated Laravel CI and package checks are being expanded. This guide is an operator runbook, not evidence that the package has passed a live cPanel rehearsal. Do not replace the production domain or database until the release-readiness audit is closed.

## 1. Hosting prerequisites

Confirm the cPanel account provides:

- PHP 8.3 or later for both the domain's PHP-FPM/Apache handler and CLI.
- Composer 2 available in cPanel Terminal.
- MySQL 8 or a supported MariaDB version, with InnoDB.
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`, `zip`, `fileinfo`, `curl`, `ctype`, `dom`, `xml`, `tokenizer`, `bcmath`.
- Writable Laravel `storage/` and `bootstrap/cache/` directories.
- HTTPS with a valid certificate and Apache rewrite support.
- Enough PHP memory and upload limits for the expected student files and backup size.

Use a dedicated cPanel database and database user. Grant only the privileges required on that database. Do not import any old/demo database.

## 2. Build the release package

Build from a clean checkout with the committed Composer lock:

```bash
cd laravel
bash scripts/build-cpanel-package.sh
```

The script creates:

- `laravel/dist/aec-lms-cpanel-release.zip`
- `laravel/dist/aec-lms-cpanel-release.zip.sha256`

The package builder requires Composer and `composer.lock`. It installs production dependencies and excludes local `.env`, backups, installation locks, private uploads, temporary plugin/update staging and development files. Inspect the ZIP and checksum before transferring it.

A successful build is only a package-content check. It does not install Laravel on the target host or validate the host's Apache, HTTPS, mail, database, permissions or cron configuration.

## 3. Configure cPanel document root and application files

Recommended layout:

```text
/home/ACCOUNT/
  apps/aec-lms/              # extracted Laravel application root
    app/
    bootstrap/
    config/
    database/
    plugins/
    public/                  # cPanel domain document root
    resources/
    routes/
    storage/
    vendor/
    .env                     # private; never web-accessible
```

Set the domain's document root to the Laravel application's `public/` directory. Keep the application root and all of `app/`, `bootstrap/`, `config/`, `database/`, `plugins/`, `resources/`, `routes/`, `storage/`, `vendor/` and `.env` outside the public document root.

If the hosting plan cannot point the domain at `laravel/public`, do not simply copy the whole Laravel project into `public_html`. That exposes source and private runtime files if access controls are misconfigured. Use a hosting plan that supports a separate application directory/document root, or have the host configure a secure public entry point.

Upload and extract the ZIP into the private application root. Do not upload the repository-root React prototype as the LMS.

## 4. Prepare environment and permissions

From cPanel Terminal:

```bash
cd /home/ACCOUNT/apps/aec-lms
cp .env.example .env
chmod 600 .env
mkdir -p storage/app/private storage/app/backups
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views
mkdir -p storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan key:generate
```

Edit `.env` with the actual production values:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://YOUR-DOMAIN`
- `DB_CONNECTION=mysql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- SMTP settings under `MAIL_*`
- `SESSION_DRIVER=file` and `CACHE_STORE=file` for the initial empty-database install (the provided `.env.example` uses these safe pre-install defaults). Switch to database-backed sessions/cache only after the Core migrations have completed.
- `SESSION_SECURE_COOKIE=true`
- `AEC_PRIVATE_STORAGE_PATH` set to an absolute private path outside the public document root (recommended), or keep the Laravel private storage path only if the entire application root is outside the document root.

Keep `.env` mode 0600 and never commit, email, paste or expose it. Run `php artisan key:generate` once before opening the browser installer so Laravel can encrypt its initial session/CSRF cookies. The installer then rotates this bootstrap key and generates fresh final `APP_KEY`, `AEC_PLUGIN_SIGNING_KEY` and `AEC_BACKUP_ENCRYPTION_KEY`, writes them to `.env`, and updates the running Laravel configuration. Preserve a secure off-host copy of the final `.env` as part of disaster recovery; encrypted backups cannot be decrypted if the backup key is lost.

## 5. Install on a brand-new empty database only

Create a **new, completely empty** database in cPanel. The installer refuses to proceed if any table already exists. It never drops or truncates existing tables and never imports legacy records.

CLI installer (recommended):

```bash
php artisan lms:install \
  --app-name="Alom's English Center" \
  --app-url="https://YOUR-DOMAIN" \
  --admin-name="Administrator" \
  --admin-email="ADMIN@YOUR-DOMAIN"
```

The command prompts for the administrator password using hidden terminal input. Do not pass the password as a command-line argument because shell history/process listings may expose it.

A browser installer is also available at `/install` while the system is not installed. It is automatically locked and returns 404 after a successful installation. Use one installer path only; do not run the CLI and browser installer concurrently.

After installation:

- The permanent lock is `storage/app/installed.lock`.
- The initial administrator is created with the Admin role.
- Legacy/demo data import is explicitly disabled.
- Generated encryption/signing keys are stored in `.env`; they are not displayed by the installer.

Do not delete the lock or rerun the installer over an existing database.

## 6. First login and post-install checks

1. Open `https://YOUR-DOMAIN/login` and sign in with the new Admin account.
2. Confirm the Admin dashboard and Plugin Center load.
3. Confirm the installer URL `/install` returns 404.
4. Confirm `/.env`, `/vendor/`, `/app/`, `/database/`, `/plugins/`, `/storage/` and `/bootstrap/` are not directly downloadable over HTTP.
5. Confirm HTTPS redirects and secure session cookies.
6. Test one record create/read/update flow in each enabled module before entering real student or financial data.
7. Test SMTP delivery with a disposable account; a queued message is not proof of inbox delivery.
8. Verify PHP/CLI versions match and that storage/cache permissions are writable by the PHP process.

Use a staging domain and disposable records for smoke tests.

## 7. Queue and scheduled work

The example environment uses the database queue. If queued notifications are enabled, configure a cPanel cron job to run a bounded worker from the Laravel application root, for example:

```bash
* * * * * cd /home/ACCOUNT/apps/aec-lms && php artisan queue:work --stop-when-empty --tries=3 --timeout=90 >> /dev/null 2>&1
```

Only add this after confirming the queue tables and the specific queued workflows are enabled. Do not create multiple long-running workers on shared hosting without checking resource limits.

## 8. Build/upgrade policy

- Take and verify a pre-upgrade backup before changing code or schema.
- Use signed update packages only. Do not upload arbitrary ZIPs or unreviewed PHP files.
- Never edit an applied migration; add a new forward migration.
- Test updates on a staging copy first.
- Keep the previous release ZIP and a verified backup available for recovery.
- Do not merge or deploy the feature branch solely because CI is green.

## 9. Current known limitations

The current Laravel automated suite validates the workflows that exist in the repository; it is not complete acceptance coverage for all 39 catalogued plugin modules. Some plugins are foundations or partial workflows. Payment providers still require provider-specific sandbox credentials and end-to-end callback/reconciliation tests. The Laravel backup service now encrypts database snapshots and can restore included private files, but a production-size MySQL backup/restore and a cPanel restore rehearsal have not been completed. The restore operation replaces rows in matching existing tables and merges files into private storage; it is not a safe substitute for restoring into a separate disposable database.

Do not call the LMS production-ready until the full module matrix, payment verification, security/E2E testing, a fresh cPanel install, backup/restore rehearsal and live-host smoke checks pass. See [RELEASE-READINESS-AUDIT.md](RELEASE-READINESS-AUDIT.md).
