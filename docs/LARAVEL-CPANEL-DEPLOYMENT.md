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

## 3. Configure the application (cPanel File Manager)

1. Extract the verified ZIP into a private application directory. Do not expose the Laravel application root as the website document root.
2. Set the domain document root to the extracted application's `public/` directory.
3. Copy `.env.example` to `.env` in the application root (not inside `public/`) using File Manager.
4. Edit `.env` with the cPanel MySQL host, database name, database username and password, plus the HTTPS application URL. Do not send database passwords or keys in chat.
5. Ensure `storage/` and `bootstrap/cache/` are writable by the PHP process. Keep backups and private uploads outside the public document root.

Manual Universal Payment receipt proofs are stored on Laravel's private `local` disk under `storage/app/private/universal-payment-proofs/` (not in `public/storage`). Do not create a public symlink for this directory. The authenticated proof-download route checks finance permission and branch ownership. Include this private directory in encrypted backups and verify restore access during the cPanel rehearsal; the release ZIP intentionally excludes uploaded proofs.

The release ZIP includes the locked Composer dependencies, so a Composer install command is not needed for the packaged build. The initial setup is available through the browser installer; cPanel Terminal/SSH is not required for this initial installation.

## 4. Install on a new empty database

Open `https://YOUR-DOMAIN/install` in a browser. The installer checks PHP 8.3+, required extensions, writable paths and the database connection. It refuses any database that already contains tables; create a completely empty database and database user in cPanel first.

Enter the application name, HTTPS URL, initial Admin name/email and a strong password in the installer form. The installer generates and privately persists the application, plugin-signing and backup-encryption keys, runs the core migrations, creates the Admin account and writes a permanent installation lock. After installation, `/install` is locked. Never remove the lock or run a fresh install against an existing database.

## 4.1 Interrupted fresh-install recovery

The installer uses a persistent OS-managed mutex file (`installed.lock.mutex`) and an atomic progress marker (`installed.lock.in-progress`). The mutex is held through migrations, core seeding, Admin creation, settings persistence and the final installed marker. The operating system releases the `flock()` lock automatically if a PHP worker exits; the mutex file itself may remain and is safe to reuse.

The progress marker records the last installation phase. It is diagnostic state, not proof that the LMS is installed:

- **Database still empty:** if an attempt stopped before any tables were created, the next installer request rechecks the database while holding the mutex, clears the stale progress marker and safely retries.
- **Database contains tables but no final installed marker:** the installer refuses an in-place retry and retains the progress marker. This can happen when MySQL has committed some DDL before a migration or seeder failure. Do not delete the marker, drop/truncate tables, or repeatedly submit the installer form.
- **Safe operator recovery:** first take and retain a backup of the partial staging database and any relevant private files/logs. Create a separate, completely empty database and dedicated DB user in cPanel, update the private application `.env` to point to that new database, and retry installation. Keep the original partial database untouched until its contents have been reviewed and any needed data has been recovered. The installer intentionally does not attempt destructive automatic cleanup or resumable seeding against an unknown partial schema.
- **Final installed marker exists:** the installation is complete and `/install` must remain locked. Never remove `installed.lock` to force a fresh install on an existing database.

Do not expose, manually edit, or place the mutex/progress marker in the public document root. If recovery is unclear, stop and preserve the database and logs for maintainer review.

## 5. Post-install verification

- Sign in with the initial Admin account.
- Confirm the Plugin Center and enabled modules load.
- Confirm `/install` is unavailable after installation.
- Verify `.env`, source, storage, backups and private uploads cannot be downloaded over HTTP.
- Test authentication, authorization, branch isolation and one complete workflow in every enabled module.
- Verify HTTPS, secure cookies, SMTP settings, private file authorization and the actual notification delivery status.
- Notification Center email uses the database queue. In cPanel → Cron Jobs, add a job to run every minute (replace the home path and PHP binary with the values shown by your hosting provider):
  ```bash
  cd /home/CPANEL-USER/private-app && /usr/local/bin/php artisan queue:work database --stop-when-empty --tries=3 --timeout=90 >> storage/logs/queue-worker.log 2>&1
  ```
  The queue migration creates `jobs`, `job_batches` and `failed_jobs` if they do not already exist. Check the queue log and failed-jobs table during staging. Do not place the log under the public document root.
- Greenweb SMS remains a synchronous provider submission. A provider acceptance response is recorded as `submitted`, not as confirmed handset delivery.
- If the hosting plan does not permit PHP CLI/Cron Jobs, queued email cannot be treated as operational; keep this as a release blocker until a supported worker is configured and tested.
- Take a backup and rehearse restore into a separate disposable database and private directory.

## 6. Upgrade and recovery policy

Take and verify a pre-upgrade backup. Test signed updates on staging. Never edit an applied migration. Keep the previous release package and verified backup available. Do not deploy solely because CI is green.

## 7. Release blockers

The current automated suite is not complete acceptance coverage for every plugin. Provider sandbox callbacks, complete module workflows, cross-branch negative tests, large MySQL backup/restore, browser E2E, security review and a clean cPanel installation/restore rehearsal remain mandatory. See [RELEASE-READINESS-AUDIT.md](RELEASE-READINESS-AUDIT.md).
