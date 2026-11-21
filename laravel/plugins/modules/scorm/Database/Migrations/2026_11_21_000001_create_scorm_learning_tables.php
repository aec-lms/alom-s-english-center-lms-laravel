<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('scorm_packages')) {
            Schema::create('scorm_packages', function (Blueprint $table): void {
                $table->id();
                // Course and branch are optional integrations; keep the plugin independently installable.
                $table->unsignedBigInteger('course_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('replaces_package_id')->nullable()->constrained('scorm_packages')->nullOnDelete();
                $table->unsignedInteger('version_number')->default(1);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title', 255);
                $table->string('identifier', 191)->nullable();
                $table->string('version', 20)->default('1.2');
                $table->unsignedInteger('revision_number')->default(1);
                $table->foreignId('replaces_package_id')->nullable()->constrained('scorm_packages')->nullOnDelete();
                $table->string('storage_directory', 64)->unique();
                $table->string('launch_file', 512);
                $table->string('manifest_file', 512)->default('imsmanifest.xml');
                $table->char('package_sha256', 64);
                $table->unsignedInteger('file_count')->default(0);
                $table->unsignedBigInteger('expanded_bytes')->default(0);
                $table->string('status', 20)->default('draft')->index();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('scorm_attempts')) {
            Schema::create('scorm_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scorm_package_id')->constrained('scorm_packages')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // Enrollment is an optional Course Management integration.
                $table->unsignedBigInteger('enrollment_id')->nullable()->index();
                $table->unsignedInteger('attempt_number');
                $table->unsignedInteger('state_version')->default(0);
                $table->string('lesson_status', 20)->default('not attempted');
                $table->string('entry_mode', 10)->default('ab-initio');
                $table->string('lesson_location', 255)->default('');
                $table->longText('suspend_data')->nullable();
                $table->decimal('score_raw', 8, 3)->nullable();
                $table->decimal('score_min', 8, 3)->nullable();
                $table->decimal('score_max', 8, 3)->nullable();
                $table->unsignedInteger('total_time_seconds')->default(0);
                $table->unsignedInteger('session_time_seconds')->default(0);
                $table->longText('cmi_data');
                $table->string('completion_state', 20)->default('in_progress')->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('last_commit_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->unique(['scorm_package_id', 'user_id', 'attempt_number'], 'scorm_attempt_unique_number');
                $table->index(['user_id', 'completion_state']);
            });
        }

        if (!Schema::hasTable('scorm_attempt_sessions')) {
            Schema::create('scorm_attempt_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scorm_attempt_id')->constrained('scorm_attempts')->cascadeOnDelete();
                $table->char('token_sha256', 64)->unique();
                $table->timestamp('expires_at')->index();
                $table->unsignedInteger('last_session_seconds')->default(0);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('scorm_runtime_requests')) {
            Schema::create('scorm_runtime_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scorm_attempt_session_id')->constrained('scorm_attempt_sessions')->cascadeOnDelete();
                $table->uuid('request_id');
                $table->char('payload_sha256', 64);
                $table->longText('response_body');
                $table->timestamps();
                $table->unique(['scorm_attempt_session_id', 'request_id'], 'scorm_runtime_request_unique');
            });
        }
    }

    public function down(): void
    {
        // SCORM learner history is academic evidence; plugin uninstall/rollback must not erase it.
        // Destructive purge is intentionally not performed by a migration rollback.
    }
};
