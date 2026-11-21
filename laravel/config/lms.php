<?php

declare(strict_types=1);

$privateStoragePath = (string) env('AEC_PRIVATE_STORAGE_PATH', 'storage/app/private');
$privateStoragePath = str_starts_with($privateStoragePath, DIRECTORY_SEPARATOR)
    ? rtrim($privateStoragePath, DIRECTORY_SEPARATOR)
    : base_path($privateStoragePath);

return [
    'core_version' => env('APP_VERSION', '2.0.0'),
    'install_lock_path' => (string) env('AEC_INSTALL_LOCK_PATH', storage_path('app/installed.lock')),
    'env_path' => (string) env('AEC_ENV_FILE_PATH', base_path('.env')),
    'private_storage_path' => $privateStoragePath,
    'backup_path' => (string) env('AEC_BACKUP_PATH', storage_path('app/backups')),
    'health_alert_email' => env('AEC_HEALTH_ALERT_EMAIL'),
    'health_min_free_disk_bytes' => (int) env('AEC_HEALTH_MIN_FREE_DISK_BYTES', 268435456),
    'health_queue_backlog_limit' => (int) env('AEC_HEALTH_QUEUE_BACKLOG_LIMIT', 1000),
    'health_slow_check_ms' => (int) env('AEC_HEALTH_SLOW_CHECK_MS', 2000),
    'update_staging_path' => storage_path('app/updates'),
    'plugin_signing_key' => env('AEC_PLUGIN_SIGNING_KEY'),
    'backup_encryption_key' => env('AEC_BACKUP_ENCRYPTION_KEY'),
    'backup_offsite' => [
        'endpoint' => env('AEC_BACKUP_S3_ENDPOINT'),
        'region' => env('AEC_BACKUP_S3_REGION'),
        'bucket' => env('AEC_BACKUP_S3_BUCKET'),
        'access_key' => env('AEC_BACKUP_S3_ACCESS_KEY'),
        'secret_key' => env('AEC_BACKUP_S3_SECRET_KEY'),
        'prefix' => env('AEC_BACKUP_S3_PREFIX', 'aec-lms-backups'),
    ],
    'fresh_install_policy' => [
        'require_empty_database' => true,
        'forbid_legacy_import' => true,
        'forbid_auto_drop_or_truncate' => true,
    ],
    'protected_core_paths' => [
        'app/Core',
        'app/Models',
        'app/Providers',
        'bootstrap',
        'config',
        'database/migrations',
    ],
];
