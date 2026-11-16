<?php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Core\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;
class CreateScheduledBackupCommand extends Command
{
    protected $signature = 'lms:backup-create {--without-files : Back up the database only}';
    protected $description = 'Create an encrypted scheduled LMS backup.';
    public function handle(BackupService $backups): int
    {
        if (!Schema::hasTable('backups')) {
            $this->warn('Backup table is not installed; scheduled backup skipped.');
            return self::SUCCESS;
        }
        try {
            $record = $backups->createEncryptedBackup(null, !$this->option('without-files'));
            $this->info('Encrypted backup created and recorded: ' . $record->filename);
            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Scheduled backup failed. Inspect the protected Laravel log.');
            return self::FAILURE;
        }
    }
}
