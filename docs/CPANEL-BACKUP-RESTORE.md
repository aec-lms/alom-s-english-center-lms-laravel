# cPanel encrypted backup and restore

## Prerequisites

- Run commands from the LMS project root using cPanel Terminal/SSH.
- PHP 8.3+ with PDO MySQL, zlib and `proc_open` enabled.
- The private configuration file created by the installer at `$HOME/.aec-lms/config.php`.
- `gpg`, `mysqldump` (or `mariadb-dump`), `mysql` (or `mariadb`), `tar` and `gzip` available on the hosting account.
- Private storage must remain outside `public_html`.

The one-time installer generates `AEC_BACKUP_PASSPHRASE` and saves it in the private config. Export that exact value to an approved offline password manager or other secure off-host vault before relying on backups. The passphrase is not printed to the terminal and must never be committed or pasted into chat. If an older installation does not have the setting, generate a one-time value with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and add the resulting literal string as `AEC_BACKUP_PASSPHRASE` in the private config. Do not put the PHP expression itself in the config because it would generate a different key on every request.

## Create an encrypted backup

Run:

```bash
php scripts/backup.php
```

By default, the script creates a new directory under `$HOME/.aec-lms/backups/`. You can select another absolute private path with `--output-dir=/absolute/private/path` or configure `AEC_BACKUP_DIR`.

Each backup directory contains:
- `database.sql.gz.gpg` — a consistent MySQL/MariaDB dump, gzip-compressed and encrypted with GPG symmetric AES-256.
- `private-storage.tar.gz.gpg` — the complete private storage directory, compressed and encrypted.
- `manifest.json.gpg` — encrypted metadata, migration count, table count and private-file count/size.
- `SHA256SUMS` — hashes of all encrypted artifacts.

The command verifies the encrypted artifacts and archive integrity before reporting success. It uses a temporary MySQL option file with mode 0600; credentials are not placed in process arguments. No plaintext backup files are retained in the backup directory. For gzip/tar integrity checks, the encrypted archives are temporarily decrypted under the private temporary directory with mode 0600, then deleted at completion. Temporary secrets and error files are also removed. Backups are written with private directory/file permissions.

Copy the complete encrypted backup directory off the hosting account. Keep the passphrase separately. The backup intentionally does not include `config.php`, installation locks, TLS private keys or provider credentials; keep a secure off-host copy of the private configuration separately.

## Restore rehearsal or disaster recovery

**Never restore over a non-empty production database or existing private-storage directory.** Restore only into a newly created, empty database and a new private directory. The script refuses non-empty targets and never drops existing tables.

1. In cPanel, create a new empty MySQL database and grant the intended database user all required privileges.
2. Choose a new private-storage path outside `public_html` that does not already exist.
3. Upload/copy the complete encrypted backup directory to a private location.
4. Run the command below, replacing the example values:

```bash
php scripts/restore.php \
  --backup-dir="$HOME/.aec-lms/backups/aec-lms-backup-YYYYMMDDTHHMMSSZ-xxxxxx" \
  --database="aec_lms_restore" \
  --storage="$HOME/.aec-lms/private-restore-YYYYMMDD" \
  --confirm-restore="aec_lms_restore"
```

The confirmation value must exactly match the target database name. The restore process verifies SHA-256 checksums and GPG authentication, validates archive paths/types, checks that the target database is empty, restores the SQL dump, extracts private files without preserving owner IDs or unsafe permissions, and verifies the restored table count, migration count and private-file count/size. Temporary decrypted compressed files are created only in `$HOME/.aec-lms/tmp/` with mode 0600 and removed after the run.

The script does not modify the live private config or switch the production site. After a successful rehearsal, run the support diagnostics and application smoke tests against the restored targets. Only after those pass should an administrator deliberately update the private config to point to the restored database/storage. If an import fails partway through, the target database may be partially populated; do not use it—create a fresh empty target and repeat after resolving the cause.

## Operational policy

- Keep at least one recent encrypted backup off-host and retain the matching passphrase separately.
- Perform a restore rehearsal before a production launch and after major migration changes.
- Use a maintenance window for any production restore or database cutover.
- Migrations are forward-only by default. Do not manually reverse a migration in production. Restore a verified backup into a separate target, inspect the result, and perform a deliberate cutover. A migration that fails must be diagnosed and corrected with a new forward migration; do not delete the installation lock or rerun the installer.
- The CLI scripts do not prove cPanel Apache, HTTPS, email delivery, or live HTTP access-control configuration. Complete `php scripts/preflight.php`, `php scripts/diagnose.php` and the live-host smoke tests after deployment.
