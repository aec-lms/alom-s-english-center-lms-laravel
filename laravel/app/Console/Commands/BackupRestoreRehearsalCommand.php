<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Backup\BackupService;
use App\Models\BackupRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class BackupRestoreRehearsalCommand extends Command
{
    protected $signature = 'lms:backup:rehearse
        {backup : Backup record ID from the production backup catalog}
        {--connection=backup_rehearsal : A separately provisioned MySQL connection}
        {--confirm-target= : Must exactly match the rehearsal database name}';

    protected $description = 'Destructively restore a backup into a separately provisioned rehearsal database';

    public function handle(BackupService $backups): int
    {
        $sourceConnection = (string) config('database.default');
        $targetConnection = (string) $this->option('connection');
        $backup = BackupRecord::query()->find($this->argument('backup'));

        if (!$backup) {
            $this->error('Backup record was not found in the source database.');
            return self::FAILURE;
        }
        if ($targetConnection === '' || $targetConnection === $sourceConnection) {
            $this->error('The rehearsal connection must be different from the live default connection.');
            return self::FAILURE;
        }

        $targetConfig = config("database.connections.{$targetConnection}");
        if (!is_array($targetConfig) || ($targetConfig['driver'] ?? null) !== 'mysql'
            || empty($targetConfig['database']) || empty($targetConfig['username'])) {
            $this->error('The rehearsal MySQL connection is not fully configured.');
            return self::FAILURE;
        }

        try {
            $sourceDatabase = (string) DB::connection($sourceConnection)->getDatabaseName();
            $target = DB::connection($targetConnection);
            $targetDatabase = (string) $target->getDatabaseName();

            if ($targetDatabase === '' || $targetDatabase === $sourceDatabase) {
                throw new RuntimeException('Refusing to rehearse against the live production database.');
            }
            if (!hash_equals($targetDatabase, (string) $this->option('confirm-target'))) {
                throw new RuntimeException('Pass --confirm-target with the exact rehearsal database name. This command overwrites its application tables.');
            }
            if (!$backups->verifyBackupIntegrity($backup)) {
                throw new RuntimeException('Backup checksum verification failed.');
            }
            if (!Schema::connection($targetConnection)->hasTable('migrations')
                || !Schema::connection($targetConnection)->hasTable('users')) {
                throw new RuntimeException('Rehearsal database must be provisioned with the current LMS schema before restore.');
            }
        } catch (Throwable $exception) {
            $this->error('Cannot use rehearsal database: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->warn("This will REPLACE application tables in rehearsal database '{$targetDatabase}'.");
        $this->line('The live production database remains the source and will not be restored into.');

        $rehearsalPrivatePath = storage_path('app/restore-rehearsal-private/' . bin2hex(random_bytes(8)));

        try {
            // Never let a rehearsal restore overwrite the live application's private uploads.
            config([
                'database.default' => $targetConnection,
                'lms.private_storage_path' => $rehearsalPrivatePath,
            ]);
            DB::purge($targetConnection);
            DB::reconnect($targetConnection);

            $backups->restoreEncryptedBackup($backup);

            $this->newLine();
            $this->info('Backup restore rehearsal completed successfully.');
            $this->line('Target database: ' . $targetDatabase);
            $this->line('Backup: ' . $backup->filename);
            if ($backup->includes_files) $this->line('Private files staged under: ' . $rehearsalPrivatePath);
            foreach ((array) ($backup->metadata['tables'] ?? []) as $table) {
                if (Schema::hasTable($table)) {
                    $this->line($table . ': ' . number_format(DB::table($table)->count()) . ' rows');
                }
            }
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Restore rehearsal failed: ' . $exception->getMessage());
            return self::FAILURE;
        } finally {
            config(['database.default' => $sourceConnection]);
            DB::purge($targetConnection);
            DB::purge($sourceConnection);
            DB::reconnect($sourceConnection);
        }
    }
}
