<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attendance_batch_rosters')) {
            Schema::create('attendance_batch_rosters', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_batch_mapping_id')->constrained('attendance_batch_mappings')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['attendance_batch_mapping_id', 'student_id'], 'att_roster_batch_student_unique');
            });
        }

        if (!Schema::hasTable('attendance_gps_consents')) {
            Schema::create('attendance_gps_consents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('policy_version', 32);
                $table->timestamp('consented_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'revoked_at']);
            });
        }

        if (!Schema::hasTable('attendance_qr_sessions')) {
            Schema::create('attendance_qr_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_batch_mapping_id')->constrained('attendance_batch_mappings')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('expires_at')->index();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('attendance_qr_checkins')) {
            Schema::create('attendance_qr_checkins', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_qr_session_id')->constrained('attendance_qr_sessions')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
                $table->foreignId('gps_consent_id')->nullable()->constrained('attendance_gps_consents')->nullOnDelete();
                $table->string('source', 16)->default('qr');
                $table->timestamp('checked_in_at');
                $table->timestamps();
                $table->unique(['attendance_qr_session_id', 'student_id'], 'att_qr_session_student_unique');
            });
        }
    }

    public function down(): void
    {
        // Preserve attendance, roster and consent history on plugin rollback.
    }
};
