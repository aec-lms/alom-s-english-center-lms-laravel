# Support diagnostics and safe recovery

## Collect a redacted report

From cPanel Terminal, run in the LMS project root:

```bash
php scripts/diagnose.php
php scripts/diagnose.php --json
```

The command is read-only. It checks the PHP runtime and required extensions, private config/installation marker presence, access-control files, private storage placement/writability, database connectivity, table counts and migration checksums. It does not change files, permissions, database rows or schema. Exit code is 0 when no failures are found and 1 when a failure is detected.

The JSON report intentionally omits credentials, signing keys, database host/name/user, email addresses, SQL data, sessions and raw exception details. Review the report before sharing it with the LMS maintainer. Do not share `$HOME/.aec-lms/config.php`, `installation.json`, database dumps, private uploads, browser cookies, API tokens or full server logs.

## What to do when a check fails

1. Keep the site and current files intact. Do not repeatedly run the installer or delete installation locks.
2. Save the diagnostic output and note the UTC time and the exact user-visible symptom.
3. Run the read-only checks:
   ```bash
   php scripts/preflight.php
   php scripts/migrate.php --status
   ```
4. Share the redacted diagnostics and the failing command's output with the LMS maintainer. Remove any path or personal detail you do not want to disclose.
5. Take a verified database and file backup before any repair or migration.

## Recovery principles

- Diagnostics are read-only; they never attempt speculative repairs.
- Do not auto-run migrations, change permissions recursively, reset passwords, delete locks, drop tables or overwrite application files as an automated “fix”.
- For a failed migration, inspect the exact migration and database state, take a backup, then prepare a reviewed repair or restore plan. MySQL DDL can partially commit.
- For a broken release, restore the last known-good application package and matching verified database backup. A code rollback alone may not reverse schema changes.
- For suspected compromise, preserve evidence, rotate secrets through cPanel, and restore from a known-clean backup.

## How remote assistance works

The diagnostics report gives the maintainer actionable, redacted facts to prepare a targeted patch or recovery command. It does not create remote server access. The maintainer cannot inspect this cPanel account or execute commands on the hosting server unless the owner separately provides a secure, explicitly authorized hosting integration. Never paste passwords, private keys, signing keys, session cookies or full database credentials into chat.

## Production follow-up

After any repair, rerun diagnostics, preflight, migration status and the browser/login smoke checks. Confirm HTTPS, mail delivery, private file access and backups on the live host.
