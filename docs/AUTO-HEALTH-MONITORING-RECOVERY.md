# AEC LMS — Auto Health Monitoring & Safe Recovery

## Included in this release candidate

- php artisan lms:health-check performs a read-only health report.
- php artisan lms:health-check --recover additionally attempts narrowly scoped recovery: recreate missing Laravel runtime directories (never follow symlinks or change permissions) and reconnect the database once.
- Admin Health Monitor is available at /admin/health for users with core.dashboard.view.
- Checks cover database query, cache read/write, Laravel runtime storage, private storage, backup storage, available disk space and PHP runtime.
- Failed checks are written to the protected Laravel log. If AEC_HEALTH_ALERT_EMAIL is configured, one alert per 15-minute cooldown is sent to that address.
- Health snapshots are cached for ten minutes. The scheduled command runs every minute with overlap protection.

## cPanel Cron requirement

Add this Cron Job in cPanel (replace the path with the actual account path):

    * * * * * cd /home/CPANEL_USER/path-to-laravel && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1

Use the PHP 8.3 CLI binary provided by the hosting company. The exact executable path varies by host. If the host does not allow cron jobs every minute, monitoring will be less frequent. The scheduler cannot run while the hosting account or PHP runtime is unavailable.

Optional .env settings:

    AEC_HEALTH_ALERT_EMAIL=admin@example.com
    AEC_HEALTH_MIN_FREE_DISK_BYTES=268435456

Configure a real administrator mailbox and working SMTP transport. Do not put credentials in source control.

## What this does not claim

This is application-level monitoring and safe recovery, not infrastructure high availability. On shared cPanel hosting the application cannot restart the web server, PHP-FPM, MySQL service or host machine; cannot provision a second server; and cannot guarantee uptime during a provider outage. These require provider monitoring/API support or a VPS/load-balanced deployment.

The LMS never automatically chmods files, deletes cache/data indiscriminately, runs migrations, truncates tables, restores a production database, or rolls back code without a verified compatible backup. Such operations can cause data loss or prolong downtime and require a reviewed recovery plan.

## Required production acceptance

1. Configure the cPanel cron and verify repeated scheduler execution.
2. Configure SMTP alert delivery and test an alert on staging.
3. Simulate a missing runtime directory on staging and confirm safe recreation.
4. Simulate database/cache failure and confirm alerts without exposing secrets.
5. Verify the Admin page is denied to non-admin roles.
6. Confirm logs, backups and private files remain outside the public web root.
7. Separately rehearse restore, release rollback and 300+ VU load test on authorized staging infrastructure.
