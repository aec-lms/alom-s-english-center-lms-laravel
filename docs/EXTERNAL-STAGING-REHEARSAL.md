# External staging rehearsal — release gates

**Status:** Prepared; execution requires isolated staging resources and provider test access. Never run these procedures against the production database, production uploads, or main domain.

## Non-negotiable safety rules
- Use a separate staging subdomain/application directory, a newly-created disposable database, and a separate private storage directory.
- Never restore a dump into the live database. Never put SQL dumps, .env, provider secrets, signing keys, backup keys, or private uploads in the public document root or Git.
- Keep a verified pre-test backup and record the release commit, package SHA-256, database engine/version, PHP version, memory limit, upload/post limits, and disk space.
- Use synthetic or properly anonymized data. Do not upload student personal data or production payment details into this repository or chat.
- Stop immediately on unexpected database/schema changes, cross-environment credentials, public exposure of private files, or unexplained data loss.

## Gate A — production-sized MySQL/MariaDB backup restore
### Inputs required
- A synthetic/anonymized .sql or .sql.gz dump representative of production size.
- Source engine/version, approximate compressed and uncompressed size, table/row counts, charset/collation, and a separate disposable target database.
- Backup encryption key retained by the operator outside the application/web root. Do not send it in chat.
### Procedure
1. Record dump SHA-256 and file size before transfer.
2. Create a new empty staging database and dedicated staging DB user; verify the target database name twice.
3. Import only into that disposable database using phpMyAdmin or the hosting provider's supported import facility. If limits are exceeded, ask the host for a temporary import path; do not switch to production.
4. Run the LMS restore rehearsal using a staging-only private storage path and the correct backup encryption key.
5. Verify schema/table counts, row counts for representative high-volume tables, foreign-key consistency, users/roles, enrollments, invoices, payments, settings, plugin state, and private-file checksums.
6. Verify the restored application can sign in and read fixture workflows; inspect Laravel logs and failed jobs.
7. Record elapsed time, peak PHP memory, peak disk use, errors, and skipped tables/files.
### Pass criteria
- Import and restore complete without writing to any production resource.
- Expected tables/rows and private-file checksums match the source manifest.
- No missing foreign-key references in checked data; auth and key workflows pass.
- Resource use remains within actual staging host limits with documented headroom.
- Restore is repeatable from retained dump and keys.
A small SQLite/MySQL CI test is not a production-sized restore.

## Gate B — clean cPanel installation
### Inputs required
- Disposable cPanel staging subdomain/account or staging document root.
- New empty MySQL/MariaDB database and DB user.
- Hosting confirmation for PHP 8.3+, required extensions, HTTPS, writable private storage, Composer/package support and Cron/PHP CLI availability.
### Procedure
1. Build the release ZIP from the exact candidate commit and record SHA-256.
2. Inspect archive contents: public/ is the document root; .env, source secrets, backups, test fixtures and private uploads must not be web-accessible or packaged.
3. Extract to a private application directory; point only the staging subdomain document root to the package public/ directory.
4. Create .env privately in the application root and enter staging-only DB credentials in cPanel File Manager. Never share the password in chat.
5. Open /install on the staging subdomain; verify preflight checks, empty-database refusal behavior, Admin creation, fresh key generation and installation lockout.
6. Verify HTTP requests cannot read .env, source files, storage, backups, logs, private payment proofs or update snapshots.
7. Test Admin/Teacher/Student/Guardian login, Plugin Center, course/enrollment/exam/payment-proof workflow, email queue/cron, HTTPS cookies, uploads and authorized private downloads.
8. Capture logs, screenshots, PHP limits and exact package checksum.
### Pass criteria
- Installation succeeds on the clean staging host without SSH if the package browser installer is supported.
- Non-empty database is refused without destructive changes; installer is locked after success.
- Private files are inaccessible over HTTP; role and branch permissions hold.
- Queue/cron and notifications are operational or explicitly blocked with documented host limitation.

## Gate C — Core update and rollback rehearsal
### Inputs required
- Separate staging installation/database/private storage.
- Exact previous and candidate release ZIPs, signed update manifest, SHA-256/signature, and encrypted pre-update backup with key retained outside web root.
### Procedure
1. Seed synthetic fixtures: course, student, enrollment, test invoice, and private document; record IDs and file checksums.
2. Verify pre-update backup checksum and that it can be read.
3. Apply signed update; capture update log, migration output, duration, PHP memory, and disk use.
4. Verify code version, fixture records, private file access, Admin login and core workflows.
5. Run rollback from Admin Update Center.
6. Verify previous code files, fixture rows, private files, update audit history, login and logs.
7. Run a separate intentionally invalid/tampered package test; it must be rejected before maintenance mode or file mutation.
8. Inspect schema and migration history. Current rollback restores rows/files but does not reverse schema migrations or drop newly introduced tables; only backward-compatible migrations may be used within the rollback window unless a separate forward-recovery plan is rehearsed.
### Pass criteria
- Signed valid update applies; invalid package is rejected without mutation.
- Rollback restores previous application code and fixture data/files and retains the update audit event.
- Do not claim schema rollback unless separately demonstrated. Database/filesystem restore is not atomic; document recovery limitations.
The automated application-level rollback test is not a substitute for this cPanel staging rehearsal.

## Gate D — payment provider sandbox acceptance
### Inputs required
- Merchant/provider sandbox account and official test credentials for each intended provider (SSLCOMMERZ, card processor, Stripe, PayPal).
- Public HTTPS staging callback URL, provider webhook signing/validation requirements, test currencies and test cards/accounts.
- Never commit credentials or send them in chat. Configure them only in private staging Admin settings/environment.
### Required scenarios per provider
- Successful checkout and verified signed callback/webhook.
- Declined, cancelled, expired and malformed transactions.
- Duplicate callback/idempotency; replayed or invalid signature.
- Wrong order ID, amount, currency, merchant identity and unknown transaction.
- Delayed callback, retry and out-of-order notification.
- Refund/reversal and ledger reconciliation where provider sandbox supports them.
- Verify payment status changes only after server-side verification; browser redirect alone must never mark an invoice paid.
- Verify no secret is returned in public API or logs.
### Pass criteria
- All positive/negative scenarios produce expected state transitions, audit records and balanced ledger entries.
- Duplicate/replayed events do not double-credit.
- Provider sandbox transaction IDs and test evidence are retained without secrets.
A local fake adapter/unit test does not establish live provider acceptance. Visa/Mastercard acceptance requires an actual acquiring processor; card brands alone do not provide a merchant API.

## Gate E — 300+ live-class capacity
### Inputs required
- Chosen conferencing provider and plan/API documentation confirming a 300+ participant room.
- Provider sandbox/test tenant or written authorization for a controlled load test.
- Staging LMS environment, synthetic accounts, and a test window. Do not load-test a public provider without authorization.
### Two distinct tests
1. LMS coordination load: at least 300 synthetic authenticated students request the LMS live-class page/join authorization concurrently. Measure p50/p95/p99 latency, error rate, PHP workers, DB connections, queue depth, CPU/memory and rate-limit behavior.
2. Actual meeting capacity: join at least 300 participants to the same provider meeting using provider-approved test clients or the provider's own load-test facility. Verify participant admission, host controls, audio/video behavior and provider limits. LMS page load alone does not prove 300 live video participants.
### Pass criteria
- 300 concurrent LMS join requests complete with agreed latency/error thresholds and no cross-student data leakage.
- Provider confirms 300 concurrent meeting participants in selected plan and authorized meeting test reaches that count.
- Record exact provider, plan, test count, duration, metrics, errors and evidence.

## Execution record
| Gate | Environment / input | Result | Evidence | Blocker |
|---|---|---|---|---|
| A — large restore | Awaiting anonymized dump + disposable DB | Not run | — | Input required |
| B — cPanel install | Awaiting staging cPanel/subdomain + empty DB | Not run | — | Host access required |
| C — update/rollback | Automated test exists; real host rehearsal pending | Partial | CI + rehearsal log | Staging environment required |
| D — payment sandbox | Awaiting provider sandbox access | Not run | — | Provider test credentials required |
| E — 300+ live class | Awaiting provider/plan + authorized load test | Not run | — | Provider capacity and load authorization required |

## Work possible without external access
- Add/maintain automated tests and CI checks against disposable SQLite/MySQL services.
- Build and inspect a cPanel release artifact from a clean checkout.
- Validate package contents and checksums.
- Exercise signed update, failure recovery and rollback code with synthetic fixtures.
- Prepare provider contract tests using mocks, clearly labelled as mocks (not sandbox acceptance).
Do not mark any external gate passed until its evidence is attached to this execution record.