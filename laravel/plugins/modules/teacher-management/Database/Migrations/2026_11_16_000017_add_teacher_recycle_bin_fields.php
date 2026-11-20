<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table): void {
            if (!Schema::hasColumn('teachers', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('teachers', 'deleted_user_was_active')) {
                $table->boolean('deleted_user_was_active')->nullable()->after('deleted_at');
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: these conditional additive columns may
        // pre-date this migration on customer installations. Without durable
        // per-install ownership evidence, dropping them could destroy customer
        // schema or soft-delete history. Retain the nullable columns on rollback.
    }
};
