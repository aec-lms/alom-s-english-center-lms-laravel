<?php

declare(strict_types=1);

return [
    'modules_path' => base_path('plugins/modules'),
    'explicit_test_boot' => (bool) env('AEC_TEST_EXPLICIT_PLUGIN_BOOT', false),
    'core_identifier' => 'aec-lms-core',
    'supported_core_constraint' => '^2.0',
    // JSON map of key_id => base64-encoded Ed25519 public key.
    'signature_keys' => (static function (): array {
        $decoded = json_decode((string) env('LMS_PLUGIN_SIGNATURE_PUBLIC_KEYS', '{}'), true);
        return is_array($decoded) ? $decoded : [];
    })(),
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
