<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fee_invoices')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' && Schema::hasColumn('fee_invoices', 'student_id')) {
            // Existing SQLite installations need the same nullable relationship
            // as fresh installs; Laravel rebuilds the table for SQLite column changes.
            Schema::table('fee_invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('student_id')->nullable()->change();
            });
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $foreignKey = DB::selectOne(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'fee_invoices'
                   AND COLUMN_NAME = 'student_id'
                   AND REFERENCED_TABLE_NAME = 'students'
                 LIMIT 1"
            );

            if ($foreignKey && !empty($foreignKey->CONSTRAINT_NAME)) {
                $constraint = preg_replace('/[^A-Za-z0-9_]/', '', (string) $foreignKey->CONSTRAINT_NAME);
                DB::statement('ALTER TABLE fee_invoices DROP FOREIGN KEY ' . $constraint);
            }

            DB::statement('ALTER TABLE fee_invoices MODIFY student_id BIGINT UNSIGNED NULL, MODIFY branch_id BIGINT UNSIGNED NULL');
        }

        Schema::table('fee_invoices', function (Blueprint $table): void {
            if (!Schema::hasColumn('fee_invoices', 'payer_name')) {
                $table->string('payer_name', 160)->nullable();
            }
            if (!Schema::hasColumn('fee_invoices', 'payer_email')) {
                $table->string('payer_email', 180)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Preserve standalone invoice payer data and optional student linkage.
    }
};
