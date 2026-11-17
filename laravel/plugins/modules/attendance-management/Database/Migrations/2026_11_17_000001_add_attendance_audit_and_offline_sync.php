<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendance_records')) {
            $addSyncUuid = !Schema::hasColumn('attendance_records', 'sync_uuid');
            $addSource = !Schema::hasColumn('attendance_records', 'source');
            $addEditedReason = !Schema::hasColumn('attendance_records', 'edited_reason');

            if ($addSyncUuid || $addSource || $addEditedReason) {
                Schema::table('attendance_records', function (Blueprint $table) use ($addSyncUuid, $addSource, $addEditedReason): void {
                    if ($addSyncUuid) {
                        $table->uuid('sync_uuid')->nullable()->unique();
                    }
                    if ($addSource) {
                        $table->string('source', 20)->default('online');
                    }
                    if ($addEditedReason) {
                        $table->string('edited_reason', 255)->nullable();
                    }
                });
            }
        }

        if (!Schema::hasTable('attendance_audit_logs')) {
            Schema::create('attendance_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('attendance_record_id')->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('event', 30);
                $table->string('old_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->string('reason', 255)->nullable();
                $table->string('source', 20)->default('online');
                $table->timestamp('occurred_at')->useCurrent();
                $table->index(['attendance_record_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        // Audit history is intentionally retained.
    }
};
