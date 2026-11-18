<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('live_classes')) {
            Schema::table('live_classes', function (Blueprint $table): void {
                if (!Schema::hasColumn('live_classes', 'description')) $table->text('description')->nullable();
                if (!Schema::hasColumn('live_classes', 'ends_at')) $table->dateTime('ends_at')->nullable();
                if (!Schema::hasColumn('live_classes', 'recording_notes')) $table->text('recording_notes')->nullable();
                if (!Schema::hasColumn('live_classes', 'created_by')) $table->unsignedBigInteger('created_by')->nullable()->index();
                if (!Schema::hasColumn('live_classes', 'recording_published_at')) $table->timestamp('recording_published_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Preserve live class history and recordings on plugin updates.
    }
};
