<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('courses')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table): void {
            if (!Schema::hasColumn('courses', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        foreach (['course_categories', 'live_classes'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally retain additive recycle-bin columns during rollback.
        // These columns may predate this migration on customized installations,
        // and may contain deletion history created after the migration ran.
        // Dropping them would risk destroying host-owned schema or recovery data.
    }
};
