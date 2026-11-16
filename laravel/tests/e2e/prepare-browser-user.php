<?php

declare(strict_types=1);

use App\\Models\\Role;
use App\\Models\\User;
use Illuminate\\Support\\Facades\\Hash;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

$role = Role::query()->where('slug', 'admin')->firstOrFail();
$user = User::query()->updateOrCreate(
    ['email' => 'e2e-admin@aec.test'],
    [
        'name' => 'E2E Administrator',
        'password' => Hash::make('E2E-Strong-Password-2026!'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]
);
$user->roles()->syncWithoutDetaching([$role->id]);

$lock = config('lms.install_lock_path');
if (!is_dir(dirname($lock))) {
    mkdir(dirname($lock), 0775, true);
}
file_put_contents($lock, json_encode([
    'installed' => true,
    'legacy_records_imported' => false,
    'installed_at' => now()->toIso8601String(),
], JSON_THROW_ON_ERROR));

echo "E2E admin and installed lock prepared.\\n";
