<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Backup\OffsiteBackupService;
use Illuminate\Console\Command;
use Throwable;

class OffsiteBackupCommand extends Command
{
    protected $signature = 'lms:backup:offsite {--no-download-verify : Upload without downloading each object for checksum verification}';

    protected $description = 'Upload encrypted LMS backups to private S3-compatible off-site storage and verify remote copies';

    public function handle(OffsiteBackupService $backups): int
    {
        try {
            $result = $backups->sync(!$this->option('no-download-verify'));
            $this->table(['Uploaded', 'Downloaded + SHA-256 verified', 'Failed'], [[
                $result['uploaded'], $result['verified'], $result['failed'],
            ]]);

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
