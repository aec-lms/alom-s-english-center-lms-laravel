<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attendance_entries')) {
            Schema::create('attendance_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_batch_mapping_id')->constrained('attendance_batch_mappings')->cascadeOnDelete();
                $table->unsignedBigInteger('student_id')->index();
                $table->unsignedBigInteger('branch_id')->index();
                $table->date('attendance_date');
                $table->string('status', 20)->default('present');
                $table->string('remarks', 255)->nullable();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('sync_uuid')->nullable()->unique();
                $table->string('source', 20)->default('online');
                $table->string('edited_reason', 255)->nullable();
                $table->timestamps();
                $table->unique(
                    ['attendance_batch_mapping_id', 'student_id', 'attendance_date'],
                    'att_entry_batch_student_date_unique'
                );
            });
        }

        if (Schema::hasTable('attendance_records')) {
            DB::table('attendance_records')->orderBy('id')->chunkById(500, function ($records): void {
                foreach ($records as $record) {
                    $mappingId = DB::table('attendance_batch_mappings')
                        ->where('source_type', 'course_batch')
                        ->where('source_id', (int) $record->batch_id)
                        ->value('id');

                    if ($mappingId === null) {
                        continue;
                    }

                    DB::table('attendance_entries')->insertOrIgnore([
                        'attendance_batch_mapping_id' => $mappingId,
                        'student_id' => (int) $record->student_id,
                        'branch_id' => (int) $record->branch_id,
                        'attendance_date' => $record->attendance_date,
                        'status' => $record->status,
                        'remarks' => $record->remarks,
                        'marked_by' => $record->marked_by,
                        'sync_uuid' => $record->sync_uuid ?? null,
                        'source' => $record->source ?? 'online',
                        'edited_reason' => $record->edited_reason ?? null,
                        'created_at' => $record->created_at ?? now(),
                        'updated_at' => $record->updated_at ?? now(),
                    ]);
                }
            });
        }


        if (!Schema::hasTable('attendance_entry_audit_logs')) {
            Schema::create('attendance_entry_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_entry_id')->constrained('attendance_entries')->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 30);
                $table->string('old_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->string('reason', 255)->nullable();
                $table->string('source', 20)->default('online');
                $table->timestamp('occurred_at')->useCurrent();
                $table->index(['attendance_entry_id', 'occurred_at'], 'att_entry_audit_time_idx');
            });
        }

        if (Schema::hasTable('attendance_qr_checkins')
            && !Schema::hasColumn('attendance_qr_checkins', 'attendance_entry_id')) {
            Schema::table('attendance_qr_checkins', function (Blueprint $table): void {
                $table->foreignId('attendance_entry_id')->nullable()
                    ->constrained('attendance_entries')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Attendance history is retained during rollback and plugin removal.
    }
};
