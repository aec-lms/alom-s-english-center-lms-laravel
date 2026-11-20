<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            if (!Schema::hasColumn('students', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('students', 'deleted_user_was_active')) {
                $table->boolean('deleted_user_was_active')->nullable()->after('deleted_at');
            }
        });
    }

    public function down(): void
    {
        // Intentionally retain additive recycle-bin columns during rollback.
        // They may predate this migration on customized installations or
        // contain deletion history created after the migration was applied.
        // Dropping them could destroy host-owned schema or recovery data.
    }
};
