# Production backup requirements and restore rehearsal

## 1. Enable and verify PHP extensions on cPanel

The hosting account owner must enable extensions for the exact PHP version assigned to the LMS domain:

1. Open **cPanel → Software → Select PHP Version** (some hosts label this **PHP Selector** or **MultiPHP INI Editor**).
2. Select PHP **8.3** for the LMS domain.
3. Enable **sodium**, **openssl**, **zip**, **pdo_mysql**, **mbstring**, **fileinfo**, **xml**, and the other Laravel-required extensions.
4. Save/apply the selection. If the host does not expose Sodium, ask the hosting provider to enable the PHP sodium extension for that domain; it cannot be safely enabled by an application-level `.user.ini` setting.
5. From the Laravel application root run:

```bash
php artisan lms:backup:requirements
```

All checks must show PASS. Also confirm the cPanel web PHP version matches the CLI PHP version used for Artisan; hosts can configure these separately. The new encrypted backup format requires Sodium secretstream. Existing AES backups can be decrypted without Sodium, but the application still requires the other PHP/Laravel extensions.

## 2. Configure a separate rehearsal database

Create a new, empty MySQL database and a dedicated database user in cPanel. Do not use the production database credentials unless they are separately restricted to the rehearsal database. Provision the rehearsal database with the exact same LMS schema/version as production (use a tested schema-only dump or the normal migrations against this empty rehearsal database).

Set these values in the production `.env` file, keeping the password private:

```dotenv
AEC_BACKUP_REHEARSAL_DB_HOST=127.0.0.1
AEC_BACKUP_REHEARSAL_DB_PORT=3306
AEC_BACKUP_REHEARSAL_DB_DATABASE=aec_lms_restore_rehearsal
AEC_BACKUP_REHEARSAL_DB_USERNAME=aec_lms_rehearsal
AEC_BACKUP_REHEARSAL_DB_PASSWORD=REPLACE_WITH_PRIVATE_PASSWORD
AEC_BACKUP_REHEARSAL_DB_SOCKET=
```

Never point these values at the live production database. Clear Laravel config cache after changing environment values:

```bash
php artisan config:clear
php artisan config:cache
```

## 3. Run a production-backup restore rehearsal

1. Create a fresh encrypted backup from the production LMS.
2. Confirm its integrity in the admin backup screen or with the existing backup verification flow.
3. Ensure the rehearsal database is isolated, disposable, and has the current LMS schema. The rehearsal command replaces its application tables.
4. Find the backup record ID in the backup catalog.
5. Run:

```bash
php artisan lms:backup:rehearse BACKUP_ID --connection=backup_rehearsal --confirm-target=aec_lms_restore_rehearsal
```

The command refuses to run unless the connection is separate from the configured default database and the exact target database name is confirmed. It restores only into the rehearsal database and prints row counts for the restored tables. If the backup includes private files, they are restored under a separate `storage/app/restore-rehearsal-private/` directory—not into the live LMS private-upload directory. Review the output and application health on a separate staging URL before approving a production recovery procedure. Remove the rehearsal private-file directory after review.

## 4. Operational safety

- Schedule backups before major releases and keep encrypted copies outside the hosting account.
- Keep the encryption key and `.env` separate from the backup archive.
- Do not test restore against the live database.
- A successful rehearsal proves that this backup can be restored into the matching schema in the tested environment; it does not guarantee the live host has adequate disk space, execution time, or PHP memory. Measure those separately.
- Preserve the rehearsal database until the result is reviewed, then remove it using cPanel when no longer needed.
