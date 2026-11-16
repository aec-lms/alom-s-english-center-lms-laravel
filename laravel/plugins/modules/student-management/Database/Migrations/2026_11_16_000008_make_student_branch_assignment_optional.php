<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('students') || !Schema::hasColumn('students', 'branch_id')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE students MODIFY branch_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // Preserve NULL branch assignments so the student plugin remains usable without branch management.
    }
};
