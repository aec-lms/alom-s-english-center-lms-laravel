<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'aec_lms'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
        ],
        // Optional isolated database used only by the destructive restore-rehearsal command.
        'redis' => [
            'client' => env('REDIS_CLIENT', 'phpredis'),
            'options' => [
                'cluster' => env('REDIS_CLUSTER', 'redis'),
                'prefix' => env('REDIS_PREFIX', \Illuminate\Support\Str::slug((string) env('APP_NAME', 'aec_lms'), '_') . '_database_'),
                'persistent' => env('REDIS_PERSISTENT', false),
            ],
            'default' => [
                'url' => env('REDIS_URL'),
                'host' => env('REDIS_HOST', '127.0.0.1'),
                'username' => env('REDIS_USERNAME'),
                'password' => env('REDIS_PASSWORD'),
                'port' => env('REDIS_PORT', '6379'),
                'database' => env('REDIS_DB', '0'),
            ],
            'cache' => [
                'url' => env('REDIS_CACHE_URL'),
                'host' => env('REDIS_HOST', '127.0.0.1'),
                'username' => env('REDIS_USERNAME'),
                'password' => env('REDIS_PASSWORD'),
                'port' => env('REDIS_PORT', '6379'),
                'database' => env('REDIS_CACHE_DB', '1'),
            ],
        ],
        'backup_rehearsal' => [
            'driver' => 'mysql',
            'url' => env('AEC_BACKUP_REHEARSAL_DB_URL'),
            'host' => env('AEC_BACKUP_REHEARSAL_DB_HOST', '127.0.0.1'),
            'port' => env('AEC_BACKUP_REHEARSAL_DB_PORT', '3306'),
            'database' => env('AEC_BACKUP_REHEARSAL_DB_DATABASE'),
            'username' => env('AEC_BACKUP_REHEARSAL_DB_USERNAME'),
            'password' => env('AEC_BACKUP_REHEARSAL_DB_PASSWORD'),
            'unix_socket' => env('AEC_BACKUP_REHEARSAL_DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
