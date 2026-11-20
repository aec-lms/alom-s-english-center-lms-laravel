<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('academic_classes')) {
            return;
        }

        Schema::table('academic_classes', function (Blueprint $table): void {
            if (!Schema::hasColumn('academic_classes', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        // Intentionally retain additive recycle-bin columns during rollback.
        // These columns may predate this migration on customized installations,
        // and may contain deletion history created after the migration ran.
        // Dropping them would risk destroying host-owned schema or recovery data.
    }
};
