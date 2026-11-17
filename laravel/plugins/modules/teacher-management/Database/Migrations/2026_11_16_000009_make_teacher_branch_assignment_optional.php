<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('teachers') || !Schema::hasColumn('teachers', 'branch_id')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE teachers MODIFY branch_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // Preserve NULL branch assignments for standalone teacher profiles.
    }
};
