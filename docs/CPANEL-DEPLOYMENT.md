# cPanel deployment notes

This guide describes the current PHP 8.3 / MySQL / React deployment layout. It is not proof that the LMS has passed a clean production deployment rehearsal. Keep the release-readiness audit open until the exact package has been installed and tested on a clean cPanel account.

## 1. Hosting prerequisites

Confirm the hosting account provides:

- PHP 8.3 selected for the domain.
- PHP extensions **pdo_mysql**, **mbstring**, **zip**, **zlib** and **openssl** (the Plugin Updater requires ZIP support; encrypted backup streaming requires zlib).
- PHP CLI `proc_open` enabled and command-line tools `gpg`, `mysqldump` or `mariadb-dump`, `mysql` or `mariadb`, `tar` and `gzip` for encrypted backup/restore.
- MySQL 8 or compatible MariaDB with InnoDB and JSON support.
- Apache **mod_rewrite** and .htaccess overrides enabled.
- HTTPS with a valid certificate.
- cPanel Terminal/SSH access for CLI migration and administrator setup.

Do not use PHP's built-in development server for production.

## 2. Build the frontend

Build from a clean checkout using the committed lockfile:

```bash
bun install --frozen-lockfile
bun run lint
bun run build
```

Upload the **contents of the dist directory** to the domain document root (commonly public_html). Do not upload the source index.html in place of the built output; it references the development entry point.

The build copies public/.htaccess into dist so React routes can fall back to the built index.html.

## 3. Upload the PHP API and protected deployment files

Alongside the built frontend, upload these directories from the same release commit:

- api/
- database/
- scripts/
Do not deploy private uploads into the public document root. The API creates a separate private storage directory outside the document root.

Keep the root .htaccess from the release. It allows the API and built assets to resolve while blocking direct web access to source code, SQL migrations, documentation, scripts, environment files and VCS metadata. Keep api/.htaccess and scripts/.htaccess in place. Do not use 0777 permissions.

Do not upload node_modules, .git, local environment files, test dumps, or development-only files.

## 4. Fresh install using the one-time installer (recommended)

For a brand-new LMS installation, upload the verified release package to the domain document root, then open cPanel Terminal and change to that project root. Create a dedicated MySQL database and user in cPanel and grant privileges on that database only. Do not import a demo database.

Run:

```bash
php scripts/install.php
```

The installer is CLI-only; there is deliberately no public browser setup wizard. It prompts for the cPanel database connection, canonical HTTPS URL, sender mailbox, private storage path and first Admin details. It verifies the database connection and refuses to install if any table already exists. It never drops, truncates or overwrites existing tables.

The installer writes a PHP configuration file with mode 0600 under `$HOME/.aec-lms/config.php`, outside the document root, creates private storage outside the document root, generates a 256-bit plugin signing key and a separate random backup encryption passphrase, applies every checked-in SQL migration with SHA-256 checksums, and creates the first Admin. Export the backup passphrase from the private config to an approved offline password manager before relying on backups. The API and CLI migration/preflight tools load this private configuration automatically. Server environment variables override values from the private config.

A filesystem lock serializes concurrent installer runs. After successful installation, `$HOME/.aec-lms/installation.json` is atomically written as a permanent completion lock; subsequent installer runs refuse to make changes. If a migration fails after installation starts, a failed-state lock is written and automatic retries are refused because MySQL DDL may have partially committed. Inspect the database and restore/reset it deliberately before any approved fresh-install retry. Do not delete either lock to bypass this protection.

Keep `$HOME/.aec-lms/` private (directory mode 0700, config/lock files mode 0600). Never move it into `public_html`, commit it, or send its contents in chat. The installer is for an empty database only; for an existing LMS use the checksum-aware upgrade procedure below and a verified backup.

After installation, run:

```bash
php scripts/preflight.php
php scripts/migrate.php --status
```

Then complete every manual HTTP, email, private-storage and login smoke check in section 9. The installer does not prove the target host's Apache/HTTPS/mail configuration and does not mark the whole LMS production-ready.

## 5. Manual environment configuration (advanced / existing deployments)

In cPanel:

1. Create a new MySQL database.
2. Create a dedicated database user with a strong unique password.
3. Grant that user the privileges needed by the LMS on that database.
4. Configure these values as private PHP/server environment variables:

- AEC_DB_HOST
- AEC_DB_NAME
- AEC_DB_USER
- AEC_DB_PASS
- Optional: AEC_DB_PORT (defaults to 3306)
- AEC_APP_URL (required canonical HTTPS URL of the public site; used in email verification and password-reset links)
- AEC_MAIL_FROM (required valid sender mailbox for account verification and recovery emails)
- AEC_PRIVATE_STORAGE_DIR (optional absolute path outside the public document root; defaults to aec-private-storage under the hosting account directory, outside the document root)

Use the hosting provider's supported PHP environment configuration. If the host requires SetEnv entries in .htaccess, keep the credentials out of Git and confirm with the host that PHP can read them through getenv(). Never put database credentials in VITE_* variables, frontend files, or a public JavaScript bundle. Confirm PHP mail() is enabled by the host and that the sender mailbox is authorized to send mail; the current account email transport uses PHP mail(), not SMTP credentials.

## 5. Run the deployment preflight

Before applying migrations or creating the first administrator, run the read-only preflight from the deployed project root:

```bash
php scripts/preflight.php
```

It checks PHP 8.3+, `pdo_mysql` and `mbstring`, database connectivity, HTTPS-form `AEC_APP_URL`, valid `AEC_MAIL_FROM`, private-storage path/writable-parent safety, and the presence of root/API access-control rules. Configure `AEC_DOCUMENT_ROOT` when the hosting environment does not expose the correct document root to CLI PHP.

The command never creates an administrator, changes the database schema or creates private storage. A successful exit means only that the CLI-visible checks passed. The `[MANUAL]` checks are mandatory: verify HTTPS and redirects, Apache `mod_rewrite`/AllowOverride, actual HTTP denial of protected paths, PHP mail delivery, and private-file authorization on the live cPanel host. Do not treat this command as a substitute for a clean install or restore rehearsal.

## 6. Apply the database migrations

From cPanel Terminal, change to the deployed project/document-root directory and inspect the migration plan:

```bash
php scripts/migrate.php --status
php scripts/migrate.php --dry-run
```

If the database and credentials are correct, apply the migrations:

```bash
php scripts/migrate.php
```

The CLI runner uses a MySQL advisory lock, records applied migration checksums in aec_schema_migrations, skips migrations already applied, and refuses to continue if an applied migration file has been changed or removed. Current migrations include 021_private_ebook_files.sql for protected e-book PDFs and 022_private_media_library.sql for protected course media. Never edit an applied migration; add a new numbered migration instead.

MySQL DDL may auto-commit. The runner does not provide transactional rollback. Back up the database before upgrades and inspect any failed migration before retrying.

## 7. Back up and restore safely

Follow [CPANEL-BACKUP-RESTORE.md](CPANEL-BACKUP-RESTORE.md) for the operator procedure to create GnuPG AES-256 encrypted database/private-file backups, verify checksums and archive integrity, and rehearse a restore to a disposable target. Confirm GnuPG is available and keep the decryption secret separately from the backup set. The procedure is manual and has not been rehearsed on a live cPanel account. Do not proceed with a production restore without an approved recovery plan and a verified pre-restore backup.

## 8. Create the first administrator

After the migrations succeed, run:

```bash
php scripts/create-admin.php
```

Enter the administrator name, email and a password of at least 12 characters twice when prompted. Password input is hidden when the terminal supports `stty`; otherwise the script warns that terminal input may be visible. The CLI-only script acquires a MySQL advisory lock, refuses to proceed if any `super_admin` already exists, checks for duplicate email addresses, and commits the new account in a transaction. It never resets or modifies an existing account. After creating the first administrator, remove `scripts/create-admin.php` from the hosting account and keep the scripts directory blocked from HTTP access. The advisory lock protects concurrent executions of this script; it does not replace review of other administrator-creation paths.

## 9. Smoke checks

Before inviting students:

- Confirm the homepage and a nested React route load over HTTPS.
- Confirm /api/auth.php?action=me returns an unauthenticated response before login, not a PHP warning or HTML page.
- Sign in with the new administrator.
- Confirm the course catalogue, CMS settings and admin dashboard load from the database.
- Submit a test admission inquiry and verify it appears in the admin admission inbox.
- Register a disposable student account, receive and use its email verification link, then test forgot-password and password reset email delivery.
- Confirm an unverified student cannot sign in and a successful password reset invalidates the student's previous session.
- Confirm a student cannot access a paid lesson without an active/completed enrollment.
- Confirm /scripts/migrate.php, /database/migrations/, /docs/, /.env.example and /api/_bootstrap.php are not downloadable through HTTP.
- Upload a test premium e-book PDF from the admin catalog, verify a student without a verified purchase receives HTTP 403, and verify the purchaser can download it after staff verification.
- Create a test lesson and upload a PDF/image/audio/video asset through the Content Library; verify MIME restrictions, the 20 MB limit, authorized download and anonymous denial.
- Confirm AEC_PRIVATE_STORAGE_DIR resolves outside the document root, is writable by PHP (directory mode 0750; files 0640), and that no private upload is created under public_html or another web-served directory.

Use a disposable test student and remove test records before opening the site publicly.

## 10. Current release limitations

This guide does not close the remaining production gates. Public admission remains an inquiry workflow, not self-service registration or payment. Student registration, email verification and account recovery were merged and CI-validated in PR #42; production email delivery and the account lifecycle still require a real cPanel smoke test. Live payment-provider callbacks and automated refunds, full branch isolation, cPanel verification of the new private course-media storage, other operations modules, backup/restore, browser E2E/security checks and a clean cPanel rehearsal remain tracked in RELEASE-READINESS-AUDIT.md. Premium e-book PDFs use the private storage directory and entitlement-checked download API, but must be smoke-tested on the target host.

Do not label the repository or any ZIP as a ready-to-use full LMS until those gates have been completed and the exact package has passed a fresh cPanel installation and upgrade rehearsal.

## Private e-book PDF storage (migration 021)

- Apply all migrations through `021_private_ebook_files.sql` before enabling the e-book upload screen.
- Configure `AEC_PRIVATE_STORAGE_DIR` as an absolute writable directory outside the public document root, or use the default `aec-private-storage` directory under the hosting account directory, outside the document root.
- PHP must be able to create directories with mode `0750`; uploaded PDFs are stored with mode `0640`.
- Set PHP `upload_max_filesize` and `post_max_size` to at least `20M` (and ensure the web server request-body limit is also at least 20 MB).
- Upload a test PDF from the Admin → Storefront Catalog page, verify that the student cannot download it before payment verification, then verify the payment and test the authenticated download.

## Private course media storage (migration 022)

- Apply all migrations through `022_private_media_library.sql` before using file uploads in the reusable Content Library.
- Course media uses the same `AEC_PRIVATE_STORAGE_DIR` root, in its `media/` subdirectory; e-book PDFs use `ebooks/`.
- PHP must be able to create directories with mode `0750`; uploaded files are stored with mode `0640`.
- Set PHP `upload_max_filesize` and `post_max_size` to at least `20M`; ensure the web server request-body limit is also at least 20 MB.
- The API currently allows JPG, PNG, WebP, GIF, PDF, MP3, M4A, OGG, MP4 and WebM. Download delivery requires an authenticated content manager or an enrolled student with access to the linked course.
- Never move private uploads under the document root or rely solely on `.htaccess` to protect them.



## Marketing, email and SMS provider configuration

Marketing/SEO IDs and metadata are managed in Admin → Marketing & SEO Suite and are stored in the LMS database. Meta Pixel, GA4 and GTM scripts are not loaded until a visitor accepts optional analytics/marketing cookies. Google Search Console verification is served as a meta tag; add the property in Search Console and complete Google's verification there, then submit `https://YOUR-DOMAIN/sitemap.xml` manually. The sitemap and robots rules are generated by the server.

Email verification and password-recovery messages for supported accounts are stored in an AES-256-GCM encrypted outbox. The one-time installer generates a dedicated `AEC_MAIL_ENCRYPTION_KEY`; keep it in the private config and never rotate it while queued messages exist. The CLI worker hands messages to PHP `mail()` and retries transient failures with bounded backoff. The hosting provider must have outbound mail configured and the sender mailbox authorized. Set the following additional keys in the private `$HOME/.aec-lms/config.php` return array (do not put them in the repository, public_html, browser storage or chat):

```php
return [
    // Keep the existing AEC_DB_* and AEC_PRIVATE_STORAGE_DIR entries.
    'AEC_APP_URL' => 'https://YOUR-DOMAIN',
    'AEC_MAIL_FROM' => 'no-reply@YOUR-DOMAIN',
    // Generated automatically by the installer; do not regenerate on an existing installation.
    'AEC_MAIL_ENCRYPTION_KEY' => 'BASE64_32_BYTE_KEY_FROM_PRIVATE_INSTALLER_CONFIG',
    // Optional SMS gateway. The endpoint must accept JSON fields to, message and sender_id.
    'AEC_SMS_API_URL' => 'https://YOUR-SMS-PROVIDER.example/api/send',
    'AEC_SMS_API_KEY' => 'YOUR_PROVIDER_SECRET',
    'AEC_SMS_SENDER_ID' => 'AEC',
];
```

For an installation created before the email outbox migration, generate one key once with `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` and add it as `AEC_MAIL_ENCRYPTION_KEY` in the private config. Do not regenerate it during routine deployments; queued messages require the original key to decrypt.\n\nThe SMS adapter sends a JSON POST with a Bearer authorization header and TLS certificate verification enabled. A provider with a different API contract needs a provider-specific adapter; do not paste provider secrets into Admin settings. After configuring it, use a test account to request and verify an OTP, check provider delivery logs, confirm the five-minute expiry and rate limits, and verify that a changed phone number becomes unverified again. For email, configure a cPanel Cron entry to run `php /absolute/path/to/lms/scripts/process-email-queue.php --limit=50` every five minutes. Review Admin → Marketing & SEO → Email delivery queue. Test a verification request for each supported role and confirm the link is single-use. The `sent` state means PHP mail() accepted the message; it does not confirm inbox delivery or bounces. Do not call provider delivery production-ready until a real cPanel smoke test has succeeded.

## 11. Build and verify the exact cPanel release ZIP

Create a versioned deployment package from a clean checkout (the script installs the locked frontend dependencies, type-checks, builds the frontend, stages the PHP API/migrations/CLI tools and verifies the archive):

```bash
bash scripts/build-release-package.sh 1.0.0-rc1
bash scripts/verify-release-package.sh release-artifacts/aec-lms-1.0.0-rc1.zip
(cd release-artifacts && sha256sum -c aec-lms-1.0.0-rc1.zip.sha256)
```

The package contains the built site at its root, plus `api/`, `database/`, `scripts/`, and this deployment guide under `docs/`. The verifier rejects unsafe archive paths, missing deployment-critical files, local secrets, VCS/dependency directories and database/archive backup files; it then checks every staged file against `RELEASE-MANIFEST.sha256`. Preserve the ZIP, sidecar checksum and manifest together. Verify the sidecar again after transferring the ZIP to the hosting account.

A release build requires the cPanel preflight script, encrypted backup/restore runbook, and a first-admin bootstrap protected by a MySQL advisory lock and an existing-super-admin check. The `REQUIRE_CPANEL_GATES` setting defaults to `true`; the pull-request CI run disables only these merge-order gates so the packaging code can be tested before PRs #18–#20 are merged. Version-tag and manual release runs enforce the gates and fail closed if any prerequisite is missing. Do not tag a release until PRs #18–#20 are merged and reviewed.

A Git tag beginning with `v` triggers the GitHub Actions cPanel release-package workflow. A manual workflow dispatch also accepts a version. The workflow artifact is retained for 30 days; download and independently verify the ZIP and checksum before deployment. A successful package workflow verifies the artifact's contents and integrity only—it does not install the package on cPanel, verify host configuration, or replace the disposable backup/restore rehearsal.


## 12. Failed-upgrade recovery policy

The migration runner is forward-only. MySQL DDL may auto-commit, so a failed migration can leave some statements applied even though its checksum was not recorded. There is no automatic SQL rollback. Never assume that rerunning the same migration is safe, and never edit an already-applied migration file.

If an upgrade fails:

1. Keep the site in maintenance/read-only mode and stop scheduled jobs and other writers. Preserve the full CLI output, timestamp, release commit and the exact migration filename; do not expose database credentials in logs.
2. Do not rerun the migration, delete rows from `aec_schema_migrations`, or manually mark the migration applied. Inspect the failed statement and actual schema with the database administrator.
3. Identify the last known-good release ZIP and the encrypted pre-upgrade database/private-file backup pair. Verify both checksums and decrypt/archive integrity using the backup runbook.
4. Rehearse recovery into a disposable database and a new private-storage directory first. Restore the complete database and matching private-file snapshot together; do not combine files from different recovery points.
5. For production recovery, obtain change approval, take a separate snapshot of the failed state, restore the verified pre-upgrade database and private files to the approved targets, and deploy the exact previous release ZIP. Keep the failed state and backup untouched until recovery sign-off.
6. Run `php scripts/migrate.php --status`, authentication, course/enrollment, payment-ledger and private-file authorization smoke checks against the restored release. Re-enable writes only after data and file consistency are confirmed.
7. If the data is consistent and the defect can be corrected safely without restoring, create a new forward migration after review; never rewrite an applied migration. Test the exact recovery/fix on a disposable copy before another production attempt.

If no verified backup pair exists, or the restore rehearsal fails, do not reopen the application or improvise a partial rollback. Escalate to the database/hosting operator and preserve the failed environment for diagnosis. This is the documented recovery policy; a real cPanel failure-and-restore rehearsal is still required before it can be called operationally tested.


## Encrypted backup and restore

After installation, run `php scripts/preflight.php` and review the backup requirements. Create an encrypted database/private-storage backup with `php scripts/backup.php`. For restore rehearsal, create a new empty database and a new private-storage path, then use `php scripts/restore.php` with the exact `--confirm-restore=DATABASE_NAME` argument. Never restore over a non-empty production database or an existing private-storage directory.

See [CPANEL-BACKUP-RESTORE.md](CPANEL-BACKUP-RESTORE.md) for the complete backup, restore, integrity verification, recovery and migration policy.
