<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Installer\FreshInstallerService;
use Illuminate\Console\Command;

class FreshInstallCommand extends Command
{
    protected $signature = 'lms:install
        {--app-name=AEC LMS : Application name}
        {--app-url=https://alomsenglishcenter.com : Public URL}
        {--admin-name= : Initial administrator name}
        {--admin-email= : Initial administrator email}
        {--admin-password= : Initial administrator password (min 12 chars)}';

    protected $description = 'Install AEC LMS on a strictly empty fresh database without importing legacy records.';

    public function handle(FreshInstallerService $installer): int
    {
        if ($installer->isInstalled()) {
            $this->error('Installation refused: AEC LMS is already installed and locked.');
            return self::FAILURE;
        }

        $adminName = (string) ($this->option('admin-name') ?: $this->ask('Administrator name'));
        $adminEmail = (string) ($this->option('admin-email') ?: $this->ask('Administrator email'));
        $adminPassword = (string) ($this->option('admin-password') ?: $this->secret('Administrator password (min 12 chars)'));

        if (trim($adminName) === '') {
            $this->error('Administrator name is required.');
            return self::FAILURE;
        }
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A valid administrator email address is required.');
            return self::FAILURE;
        }
        if (strlen($adminPassword) < 12) {
            $this->error('Administrator password must be at least 12 characters.');
            return self::FAILURE;
        }

        $result = $installer->installFresh([
            'app_name' => $this->option('app-name'),
            'app_url' => $this->option('app-url'),
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
        ]);

        $this->info('AEC LMS installed cleanly on a fresh database. Legacy records imported: NO.');
        $this->line('Lock file created: ' . $result['lock_path']);

        return self::SUCCESS;
    }
}
