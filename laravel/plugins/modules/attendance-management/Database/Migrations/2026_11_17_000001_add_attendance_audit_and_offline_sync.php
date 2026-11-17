<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendance_records')) {
            Schema::table('attendance_records', function (Blueprint $table): void {
                if (!Schema::hasColumn('attendance_records', 'sync_uuid')) {
                    $table->uuid('sync_uuid')->nullable()->unique();
                }
                if (!Schema::hasColumn('attendance_records', 'source')) {
                    $table->string('source', 20)->default('online');
                }
                if (!Schema::hasColumn('attendance_records', 'edited_reason')) {
                    $table->string('edited_reason', 255)->nullable();
                }
            });
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
        // Attendance history is retained when the plugin is rolled back or removed.
    }
};
