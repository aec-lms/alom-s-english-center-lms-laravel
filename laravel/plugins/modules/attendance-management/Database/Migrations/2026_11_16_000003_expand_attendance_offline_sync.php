<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attendance_records') && Schema::hasTable('study_batches') && Schema::hasTable('students')) {
            Schema::create('attendance_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('batch_id')->constrained('study_batches')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->index();
                $table->date('attendance_date');
                $table->string('status', 20)->default('present');
                $table->string('remarks', 255)->nullable();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['batch_id', 'student_id', 'attendance_date']);
            });
        }

        if (!Schema::hasTable('study_batch_students')) {
            Schema::create('study_batch_students', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('study_batch_id')->constrained('study_batches')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['study_batch_id', 'student_id']);
            });
        }

        if (Schema::hasTable('study_batches') && !Schema::hasColumn('study_batches', 'academic_level')) {
            Schema::table('study_batches', function (Blueprint $table): void {
                $table->string('academic_level', 80)->nullable()->after('course_title');
            });
        }

        if (Schema::hasTable('attendance_records')) {
            Schema::table('attendance_records', function (Blueprint $table): void {
                if (!Schema::hasColumn('attendance_records', 'client_uuid')) {
                    $table->uuid('client_uuid')->nullable()->unique();
                }
                if (!Schema::hasColumn('attendance_records', 'source')) {
                    $table->string('source', 16)->default('online');
                }
                if (!Schema::hasColumn('attendance_records', 'sync_status')) {
                    $table->string('sync_status', 16)->default('synced');
                }
                if (!Schema::hasColumn('attendance_records', 'latitude')) {
                    $table->decimal('latitude', 10, 7)->nullable();
                }
                if (!Schema::hasColumn('attendance_records', 'longitude')) {
                    $table->decimal('longitude', 10, 7)->nullable();
                }
                if (!Schema::hasColumn('attendance_records', 'location_accuracy')) {
                    $table->decimal('location_accuracy', 8, 2)->nullable();
                }
                if (!Schema::hasColumn('attendance_records', 'location_captured_at')) {
                    $table->timestamp('location_captured_at')->nullable();
                }
                if (!Schema::hasColumn('attendance_records', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('attendance_records', 'update_reason')) {
                    $table->string('update_reason', 500)->nullable();
                }
                if (!Schema::hasColumn('attendance_records', 'qr_session_id')) {
                    $table->unsignedBigInteger('qr_session_id')->nullable()->index();
                }
            });
        }

        if (!Schema::hasTable('attendance_record_history')) {
            Schema::create('attendance_record_history', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_record_id')->constrained('attendance_records')->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 24);
                $table->string('old_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['attendance_record_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('attendance_qr_sessions')) {
            Schema::create('attendance_qr_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('batch_id')->constrained('study_batches')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at')->index();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserve attendance and audit records when the plugin is disabled or uninstalled.
    }
};
