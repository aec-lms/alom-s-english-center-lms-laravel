<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('visitor_popups')) {
            Schema::table('visitor_popups', function (Blueprint $table): void {
                if (!Schema::hasColumn('visitor_popups', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally retain additive recycle-bin columns during rollback.
        // They may predate this migration on customized installations or
        // contain deletion history created after the migration was applied.
        // Dropping them could destroy host-owned schema or recovery data.
    }
};
