# cPanel deployment — Laravel production application

The production LMS application is Laravel 12 under `/laravel`. The repository-root Google AI Studio / React prototype and the earlier root-level PHP API are retained as legacy/reference material; **do not deploy them as the LMS**.

Use the current Laravel-specific guide:

- [Laravel cPanel deployment](LARAVEL-CPANEL-DEPLOYMENT.md)
- [Laravel backup and restore](LARAVEL-BACKUP-RESTORE.md)
- [Release readiness audit](RELEASE-READINESS-AUDIT.md)

The Laravel app's web document root must be `laravel/public`. Keep the application root, `.env`, `vendor`, `storage`, backup archives and private uploads outside the public document root.

**Production status: not ready for deployment yet.** CI currently validates the existing automated test suite and release package checks, but a clean cPanel install/restore rehearsal, provider callbacks, full module acceptance, and live-host security smoke tests remain mandatory. Do not replace the live domain's files or database on the basis of a green CI run.
