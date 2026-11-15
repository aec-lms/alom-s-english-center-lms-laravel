# Legacy PHP API backup and restore runbook — superseded

This document describes the earlier root-level PHP API implementation and its GPG command-line backup scripts. It does **not** describe the Laravel backup service and must not be used to back up or restore the Laravel LMS.

The production application is Laravel 12 under `/laravel`. For current Laravel backup behavior, limitations and operator precautions, use [LARAVEL-BACKUP-RESTORE.md](LARAVEL-BACKUP-RESTORE.md).

Do not run the legacy root-level `scripts/backup.php` or `scripts/restore.php` against the Laravel database. Do not mix backup archives between the legacy PHP API and Laravel applications.

The Laravel backup/restore path has automated SQLite CI coverage, but still requires a disposable MySQL/MariaDB and cPanel restore rehearsal before production use.
