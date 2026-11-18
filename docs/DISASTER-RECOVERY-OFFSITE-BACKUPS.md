# Disaster recovery: automated encrypted off-site backups

## Scope and status

Laravel Core creates encrypted .zip.enc archives containing a streamed database snapshot and, for the weekly full backup, private application files. It verifies SHA-256 checksums and rejects unsafe or symlinked paths.

This change adds S3-compatible off-site transport. It uploads the already-encrypted archive over HTTPS using AWS Signature Version 4, records the remote object key and SHA-256 in the backup record, avoids re-uploading unchanged objects, and performs a real download-and-hash verification during the weekly run.

**External dependency:** off-site protection becomes operational only after the owner provisions a separate object-storage account/bucket and configures its credentials privately in .env. No provider account or secret is created by this repository change. Do not mark the DR gate passed until the first upload and remote download verification succeed.

## 1. Recovery objectives

Set and approve service-specific objectives before launch. Suggested initial targets (not a guarantee):

- RPO: 24 hours for database changes; up to 7 days for private files if relying only on the scheduled weekly full backup.
- RTO: establish by timing a full restore on the actual cPanel plan; do not promise an RTO before that drill.
- Keep at least 7 daily database backups and 4 weekly full backups off-site. Configure bucket lifecycle/retention and versioning to meet this policy.
- Keep a second independent copy/account or immutable retention where available. A bucket in the same hosting account is not an independent off-site copy.

## 2. Provision the off-site destination

Use AWS S3 or an S3-compatible provider such as a dedicated Backblaze B2 S3 endpoint or Cloudflare R2 bucket. Create:

1. A dedicated private bucket for this LMS only.
2. A least-privilege access key restricted to that bucket and object prefix. It needs object PUT and GET permissions; no public ACL, bucket listing, or delete permission is required by the uploader.
3. Bucket versioning and lifecycle retention (recommended: daily objects retained 30 days, weekly full backups retained 12 weeks). Adjust to storage budget and compliance obligations.
4. Provider-side encryption at rest as an additional layer. Application archives are already encrypted before upload.

Use the provider's HTTPS S3 endpoint and signing region. Do not use a public bucket or paste credentials into GitHub, chat, issue comments, or source code.

## 3. Configure cPanel securely

In cPanel File Manager, edit the Laravel application .env file (outside the public web document root) and set:

    AEC_BACKUP_ENCRYPTION_KEY=YOUR_LONG_RANDOM_PRIVATE_BACKUP_KEY
    AEC_BACKUP_S3_ENDPOINT=https://YOUR-S3-ENDPOINT
    AEC_BACKUP_S3_REGION=YOUR_PROVIDER_REGION
    AEC_BACKUP_S3_BUCKET=YOUR_PRIVATE_BUCKET
    AEC_BACKUP_S3_ACCESS_KEY=YOUR_LIMITED_ACCESS_KEY
    AEC_BACKUP_S3_SECRET_KEY=YOUR_LIMITED_SECRET_KEY
    AEC_BACKUP_S3_PREFIX=aec-lms-backups

Generate and store the backup encryption key separately from the storage credentials. Retain it in an approved password manager/offline emergency vault. **Losing the key makes the encrypted backups unrecoverable.** Never rotate it without preserving the old key and testing old-backup decryption. The application falls back to APP_KEY if AEC_BACKUP_ENCRYPTION_KEY is blank; explicitly setting a dedicated key is recommended so routine application-key rotation does not strand backups.

After changing .env, clear/rebuild Laravel config cache using hosting terminal/SSH if available. If terminal access is unavailable, ask the host or deployment operator to run php artisan config:clear and php artisan config:cache from the Laravel application root. Do not expose .env through a web-accessible diagnostic page.

Enable PHP 8.3+ extensions required by the backup service: sodium, openssl, zip, pdo_mysql, and curl. Check with:

    php artisan lms:backup:requirements
    php artisan lms:backup:offsite

The off-site command exits non-zero if configuration, upload, or remote checksum verification fails.

## 4. Schedule automation

Laravel schedules these operations:

| Local time | Operation |
|---|---|
| Daily 02:00 | Encrypted database-only backup |
| Sunday 02:30 | Encrypted full backup including private files |
| Daily 03:00 | Upload any not-yet-synced encrypted backups to off-site storage |
| Sunday 04:00 | Download each remote object and compare SHA-256 with the local backup |

Set the cPanel Cron Job to run Laravel's scheduler every minute. Use the actual PHP CLI path and actual private application path supplied by the host:

    * * * * * /usr/local/bin/php /home/CPANEL_USER/path-to-laravel/artisan schedule:run >> /home/CPANEL_USER/logs/lms-scheduler.log 2>&1

The path above is an example only. Confirm the PHP 8.3 CLI binary and application path with the host. Protect the log from public access and rotate it. Do not schedule the command against a production database from a staging application or vice versa.

## 5. First-run acceptance and recurring verification

After provisioning credentials:

1. Run php artisan lms:backup:requirements; every check must pass.
2. Run php artisan lms:backup-create (or wait for the scheduled backup).
3. Run php artisan lms:backup:offsite. Expect Uploaded > 0, Verified > 0, Failed = 0. The command downloads each uploaded object into a temporary file and compares SHA-256.
4. Run the command again. Unchanged objects should not be uploaded again; the weekly verification still downloads them.
5. Confirm objects exist in the provider console, bucket remains private, versioning/lifecycle are enabled, and no credentials appear in logs.
6. Review the backup catalog: each record's metadata must contain the object key, SHA-256, and upload timestamp.
7. Repeat after provider credential rotation, application updates, and any change to the backup format.

The weekly job verifies remote bytes against the local encrypted archive. It does not replace a full database-and-file restore drill.

## 6. Isolated restore drill (required before declaring DR complete)

Never restore into the live database or production private-file directory.

1. Create a separate staging subdomain/application directory, a newly created disposable MySQL/MariaDB database, and a separate private storage path.
2. Select a recent full backup. If the off-site copy is the only available copy, download it from the provider console to the private staging backup directory. Preserve its exact filename.
3. Verify its SHA-256 against the backup catalog/provider manifest. Ensure the matching historical AEC_BACKUP_ENCRYPTION_KEY is available from the emergency vault.
4. Configure staging-only DB credentials and AEC_PRIVATE_STORAGE_PATH; verify the target database name twice.
5. Import/restore only into the disposable staging database using the documented rehearsal command:

    php artisan lms:backup:rehearse BACKUP_ID --connection=backup_rehearsal --confirm-target=DISPOSABLE_DATABASE_NAME

6. Verify table/row counts, foreign keys, Admin login, student/teacher/guardian access, course enrolment, exam attempts, invoices/payment history, plugin state, and private student-file checksums. Confirm restored files remain outside the public document root.
7. Run application smoke tests and inspect Laravel logs. Record start/end time, database size, file size, PHP memory, disk use, errors, and recovery gaps.
8. Destroy the disposable staging database and temporary restored files only after the drill evidence is reviewed.

Pass criteria: remote object downloads successfully; SHA-256 matches; decryption and restore complete into staging; critical data and private files match the pre-backup manifest; no production resource is touched; elapsed time is documented. Repeat at least quarterly and after major schema/backup changes.

## 7. Incident recovery checklist

- Declare incident owner and record incident start time.
- Isolate compromised hosting credentials and preserve logs; do not delete the remote backup bucket.
- Provision a clean host and a new empty database. Patch the root cause before exposing the recovered site.
- Retrieve the newest known-good encrypted backup and its matching encryption key from the independent vault.
- Verify SHA-256 before decrypting. Reject unexpected filenames, checksum mismatch, missing manifest, or wrong core version.
- Restore to staging first, validate users/roles, student records, payments, plugin states and private files, then switch DNS/document root only after approval.
- Rotate database, SMTP, payment, storage and application credentials. Revoke compromised keys. Preserve a known-good pre-recovery copy.
- Record data-loss window (RPO), elapsed recovery time (RTO), failed steps and corrective actions.

## 8. Known limitations and safety notes

- S3-compatible endpoint implementations vary. Validate the exact provider endpoint, signing region, path-style bucket addressing and IAM permissions with the first upload/download test.
- This transport uses PHP cURL and AWS Signature Version 4. TLS certificate verification is enabled and redirects are disabled.
- The uploader never deletes remote objects. Apply retention/versioning through provider lifecycle policy, not application-side deletion.
- The local backup directory and off-site bucket must be on separate failure domains.
- Remote verification proves byte-for-byte archive integrity, not that the target database engine or cPanel plan can restore it. Only a full isolated restore drill proves that.
- Automated off-site sync is not active until the .env credentials, cPanel scheduler and provider-side bucket are configured and acceptance steps pass.
