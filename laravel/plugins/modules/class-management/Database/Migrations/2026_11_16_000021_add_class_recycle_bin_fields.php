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
        // Intentionally non-destructive: these conditional additive columns may
        // pre-date this migration on customer installations. Without durable
        // per-install ownership evidence, dropping them could destroy customer
        // schema or soft-delete history. Retain the nullable columns on rollback.
    }
};
