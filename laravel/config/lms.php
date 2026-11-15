<?php

declare(strict_types=1);

return [
    'core_version' => env('APP_VERSION', '2.0.0'),
    'install_lock_path' => storage_path('app/installed.lock'),
    'private_storage_path' => base_path((string) env('AEC_PRIVATE_STORAGE_PATH', 'storage/app/private')),
    'backup_path' => storage_path('app/backups'),
    'update_staging_path' => storage_path('app/updates'),
    'plugin_signing_key' => env('AEC_PLUGIN_SIGNING_KEY'),
    'backup_encryption_key' => env('AEC_BACKUP_ENCRYPTION_KEY'),
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
