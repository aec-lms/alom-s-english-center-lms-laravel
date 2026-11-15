<?php

declare(strict_types=1);

return [
    'modules_path' => base_path('plugins/modules'),
    'core_identifier' => 'aec-lms-core',
    'supported_core_constraint' => '^2.0',
    'protected_tables' => [
        'users',
        'roles',
        'permissions',
        'role_user',
        'permission_role',
        'plugins',
        'plugin_settings',
        'system_settings',
        'audit_logs',
        'backups',
        'update_logs',
        'migrations',
    ],
];
