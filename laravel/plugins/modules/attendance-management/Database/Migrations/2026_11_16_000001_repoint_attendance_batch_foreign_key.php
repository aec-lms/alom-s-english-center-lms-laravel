<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attendance_records') || !Schema::hasTable('study_batches') || !Schema::hasColumn('attendance_records', 'batch_id')) {
            return;
        }

        // SQLite fresh installs use the corrected original migration. MySQL/MariaDB
        // installations may already have the old FK pointing at the legacy batches table.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $foreignKey = DB::selectOne(
            "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'attendance_records'
               AND COLUMN_NAME = 'batch_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1"
        );

        if ($foreignKey && strtolower((string) $foreignKey->REFERENCED_TABLE_NAME) !== 'study_batches') {
            $constraint = str_replace(chr(96), chr(96) . chr(96), (string) $foreignKey->CONSTRAINT_NAME);
            DB::statement('ALTER TABLE attendance_records DROP FOREIGN KEY ' . chr(96) . $constraint . chr(96));
            $foreignKey = null;
        }

        if (!$foreignKey) {
            Schema::table('attendance_records', function (Blueprint $table): void {
                $table->foreign('batch_id')
                    ->references('id')
                    ->on('study_batches')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Keep attendance history and its corrected batch relationship on rollback.
    }
};
