<?php

declare(strict_types=1);

namespace Database\\Seeders;

use App\\Models\\Role;
use App\\Models\\User;
use Illuminate\\Database\\Seeder;
use Illuminate\\Support\\Facades\\Hash;

class E2EBrowserUserSeeder extends Seeder
{
    public function run(): void
    {
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
    }
}
