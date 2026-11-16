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

The release ZIP includes the locked Composer dependencies, so a Composer install command is not needed for the packaged build. The initial setup is available through the browser installer; cPanel Terminal/SSH is not required for this initial installation.

## 4. Install on a new empty database

Open `https://YOUR-DOMAIN/install` in a browser. The installer checks PHP 8.3+, required extensions, writable paths and the database connection. It refuses any database that already contains tables; create a completely empty database and database user in cPanel first.

Enter the application name, HTTPS URL, initial Admin name/email and a strong password in the installer form. The installer generates and privately persists the application, plugin-signing and backup-encryption keys, runs the core migrations, creates the Admin account and writes a permanent installation lock. After installation, `/install` is locked. Never remove the lock or run a fresh install against an existing database.

## 5. Post-install verification

- Sign in with the initial Admin account.
- Confirm the Plugin Center and enabled modules load.
- Confirm `/install` is unavailable after installation.
- Verify `.env`, source, storage, backups and private uploads cannot be downloaded over HTTP.
- Test authentication, authorization, branch isolation and one complete workflow in every enabled module.
- Verify HTTPS, secure cookies, SMTP delivery, private file authorization and the actual notification delivery status.
- Current Notification Center email and Greenweb SMS sends are synchronous; a background queue worker is not part of this release candidate. Do not configure a database queue worker unless a future verified release explicitly enables one.
- Take a backup and rehearse restore into a separate disposable database and private directory.

## 6. Upgrade and recovery policy

Take and verify a pre-upgrade backup. Test signed updates on staging. Never edit an applied migration. Keep the previous release package and verified backup available. Do not deploy solely because CI is green.

## 7. Release blockers

The current automated suite is not complete acceptance coverage for every plugin. Provider sandbox callbacks, complete module workflows, cross-branch negative tests, large MySQL backup/restore, browser E2E, security review and a clean cPanel installation/restore rehearsal remain mandatory. See [RELEASE-READINESS-AUDIT.md](RELEASE-READINESS-AUDIT.md).
