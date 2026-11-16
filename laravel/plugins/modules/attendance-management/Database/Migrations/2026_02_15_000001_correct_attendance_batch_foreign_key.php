<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Throwable;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attendance_records') || !Schema::hasTable('study_batches')) {
            return;
        }

        try {
            Schema::table('attendance_records', function (Blueprint $table): void {
                $table->dropForeign(['batch_id']);
            });
        } catch (Throwable) {
            // Fresh installs already use study_batches; no legacy foreign key exists to replace.
        }

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->foreign('batch_id', 'attendance_records_batch_id_study_batches_foreign')
                ->references('id')
                ->on('study_batches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Preserve attendance records. The previous incorrect batches relation is not restored.
    }
};
