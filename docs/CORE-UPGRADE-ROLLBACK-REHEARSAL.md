# Core upgrade and rollback rehearsal

**Scope:** Laravel 12 Core updater in `laravel/app/Core/Updates/UniversalUpdateService.php`.

## Safety rules

- Never rehearse against the production database, live uploads, or main domain.
- Use a disposable staging copy with a separate database and private storage directory.
- Keep a verified pre-upgrade encrypted backup, the exact signed ZIP, SHA-256, signature, and previous release ZIP.
- Keep the previous release and backup outside the web document root.
- Do not edit an applied migration. Database schema changes are forward-only unless a separately reviewed, tested down-migration strategy exists.

## Automated rehearsal coverage

The feature test `BackupAndUpdateTest::test_core_update_can_restore_changed_code_and_database_snapshot` creates a signed Core ZIP containing a fixture file, applies the update, checks the updated file, invokes rollback, and checks that the original file and update audit row are restored/preserved.

The updater snapshots only files included in the update payload. This avoids recursively copying the application root into `storage/app/update-snapshots` during a Core update. The snapshot manifest records whether each target file existed before the update; rollback restores overwritten files and removes newly introduced files. Snapshot paths are checked to remain under the private update-snapshot directory and target paths remain under the application root.

The backup restore path used by updater rollback permits the pre-upgrade Core version and preserves the `update_logs` audit table, so the rollback event is not erased by the restored database snapshot.

## Staging rehearsal checklist

1. Create a disposable staging subdomain/application directory and a new, separate MySQL database.
2. Install the exact candidate release ZIP; confirm Admin, Student, Teacher and Guardian sign-in.
3. Create a small known fixture set (one course, one student, one enrollment, one invoice in test mode, and one private test document).
4. Create an encrypted pre-upgrade backup. Verify its checksum and retain its encryption key securely outside the web root.
5. Apply a signed Core update package. Record start/end time, PHP memory, free disk space, update log, and migration output.
6. Verify the application is healthy and fixture records/files remain accessible.
7. Invoke rollback from the Admin Update Center. Verify previous code files, fixture database rows, private-file access, login, and update audit history.
8. Re-test all relevant workflows and inspect Laravel logs, failed jobs, database schema and migration history.
9. Repeat with an intentionally invalid update package in staging to verify that validation rejects it before maintenance mode or deployment.
10. Record the exact release SHA, ZIP checksum, backup filename/checksum, test outcome and operator.

## Important limits

- The automated test is an isolated application-level rehearsal, not proof of a real cPanel upgrade.
- Database restore replaces rows in existing tables but deliberately does not restore the `migrations` table or reverse schema changes. Core migrations must therefore be backward-compatible with the previous code during the rollback window. If a migration is not backward-compatible, do not deploy that Core release until a separately tested forward-recovery plan exists.
- Private file restoration and database restoration are not one atomic transaction. A filesystem failure after database restoration requires manual recovery from the retained staging backup.
- The real cPanel rehearsal, production-sized MySQL restore, queue/cron check and provider integration checks remain release gates.
