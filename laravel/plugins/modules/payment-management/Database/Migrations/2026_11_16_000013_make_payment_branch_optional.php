<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payment_transactions') || !Schema::hasColumn('payment_transactions', 'branch_id')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payment_transactions MODIFY branch_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // Keep branch_id nullable for safe operation without branch management.
    }
};
