<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lms_notifications')) {
            return;
        }

        Schema::table('lms_notifications', function (Blueprint $table): void {
            if (!Schema::hasColumn('lms_notifications', 'attempt_count')) {
                $table->unsignedSmallInteger('attempt_count')->default(0);
            }
            if (!Schema::hasColumn('lms_notifications', 'last_error')) {
                $table->text('last_error')->nullable();
            }
            if (!Schema::hasColumn('lms_notifications', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Preserve delivery audit history on rollback.
    }
};
