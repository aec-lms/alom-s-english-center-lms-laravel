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
