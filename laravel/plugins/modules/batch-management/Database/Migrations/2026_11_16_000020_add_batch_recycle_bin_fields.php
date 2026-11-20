<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('study_batches')) {
            return;
        }

        Schema::table('study_batches', function (Blueprint $table): void {
            if (!Schema::hasColumn('study_batches', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        // This migration conditionally adds soft-delete fields but does not record
        // ownership of pre-existing host columns. Retain the fields on rollback
        // rather than risk deleting host schema or Recycle Bin history.
    }
};
