<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupRequirementsCommand extends Command
{
    protected $signature = 'lms:backup:requirements';

    protected $description = 'Check PHP extensions and runtime requirements for encrypted LMS backups';

    public function handle(): int
    {
        $checks = [
            'PHP 8.3+' => PHP_VERSION_ID >= 80300,
            'OpenSSL' => extension_loaded('openssl'),
            'Sodium (required for new streaming backups)' => extension_loaded('sodium')
                && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'),
            'ZipArchive' => class_exists(\ZipArchive::class),
            'PDO MySQL (production database)' => extension_loaded('pdo_mysql'),
        ];

        foreach ($checks as $label => $passed) {
            $this->line(($passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>') . ' ' . $label);
        }

        if (in_array(false, $checks, true)) {
            $this->newLine();
            $this->error('Backup runtime requirements are not satisfied. Enable the missing PHP extensions in your hosting PHP selector, then re-run this command.');
            return self::FAILURE;
        }

        $this->info('Backup runtime requirements are satisfied.');
        return self::SUCCESS;
    }
}
