# Laravel backup and restore — operator notes

**Applies to:** Laravel 12 under `/laravel` only.  
**Status:** Automated SQLite coverage exists; production-size MySQL/cPanel restore rehearsal is still required.

Do not use the legacy root-level PHP API backup scripts for the Laravel application.

## Create a backup

Sign in as an Admin with `core.backups.manage` permission and open:

```text
/admin/backups
```

Create an encrypted backup and choose whether to include private files. Backups are stored under the configured private `storage/app/backups` directory, not in the public document root.

Each encrypted archive contains a JSON database snapshot and manifest; when selected, private-storage files are included under the `private-files/` prefix. The service encrypts the archive with AES-256-CBC and authenticates it with HMAC-SHA256. A SHA-256 checksum is stored with the backup record and checked before restore.

The installer writes a separate `AEC_BACKUP_ENCRYPTION_KEY` to `.env`. Keep a secure off-host copy of the final `.env` and the application release. If the key is lost or changed, the encrypted backups cannot be decrypted.

## Restore behavior — read before using

The Admin restore action requires an explicit confirmation field. It verifies that the archive is within the configured backup directory, verifies its checksum and HMAC, decrypts the archive, and checks the Core version.

**The current restore action operates on the currently configured database. It is destructive to rows in tables included in the snapshot.** For each matching table in the archive (except the migrations and backups tables), it deletes the existing rows and inserts the snapshot rows inside a database transaction. It temporarily disables foreign-key checks for the transaction and re-enables them afterward.

Private files, when included, are staged and path-validated before being merged into the configured private storage directory. Existing files with matching names may be replaced. Files that are not present in the backup are not deleted. Database restore and filesystem restore are not one atomic transaction; a filesystem error after the database transaction may require manual recovery.

The restore action is **not** a restore-to-new-empty-database workflow and does not change the application's database connection. Do not use it against a production database as a disaster-recovery shortcut.

## Required safe rehearsal

Before production use:

1. Clone the application code and `.env` to a protected staging environment.
2. Create a disposable database with the same Core version and plugin migration state as the backup.
3. Use a separate empty private-storage directory.
4. Copy the encrypted archive and the correct backup key securely.
5. Rehearse restore on staging only. Confirm the expected users, courses, students, invoices, payments, notices, settings, audit data and private documents are present.
6. Confirm the database and private files are mutually consistent; inspect file permissions and ensure private files remain outside the web document root.
7. Test login, Admin, Student, Teacher and Guardian workflows after restore.
8. Record duration, peak PHP memory, archive size, row counts and any errors.

Do not perform this rehearsal on the live production database or live private storage.

## Current technical limitations / release blockers

- The database snapshot is serialized as JSON in PHP memory. Large production databases may exceed PHP memory or execution limits.
- Private file restoration merges files and does not remove files absent from the snapshot.
- Database restore and filesystem restore cannot currently roll back together if the filesystem phase fails.
- Restore requires matching Core version and compatible table schemas; it does not migrate the application to a different release.
- Backup files and their encryption key are not automatically copied to an off-host location.
- A full MySQL/MariaDB cPanel restore rehearsal has not yet been completed.

Until these limitations are resolved and a disposable cPanel rehearsal passes, use a hosting-level encrypted database/private-file backup procedure for production recovery and keep the Laravel Admin restore action limited to controlled staging tests.
