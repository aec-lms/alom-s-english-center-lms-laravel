<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('student_activity_events')) {
            return;
        }

        if (!Schema::hasColumn('student_activity_events', 'user_id')) {
            Schema::table('student_activity_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            });
        } else {
            Schema::table('student_activity_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }

        if (!Schema::hasColumn('student_activity_events', 'student_id')) {
            Schema::table('student_activity_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('student_id')->nullable()->index();
            });
        } else {
            Schema::table('student_activity_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('student_id')->nullable()->change();
            });
        }

        if (!Schema::hasColumn('student_activity_events', 'branch_id')) {
            Schema::table('student_activity_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('branch_id')->nullable()->index();
            });
        }

        if (!Schema::hasColumn('student_activity_events', 'subject_type')) {
            Schema::table('student_activity_events', function (Blueprint $table): void {
                $table->string('subject_type', 60)->nullable();
            });
        }

        if (!Schema::hasColumn('student_activity_events', 'subject_id')) {
            Schema::table('student_activity_events', function (Blueprint $table): void {
                $table->string('subject_id', 100)->nullable();
            });
        }

        if (!Schema::hasColumn('student_activity_events', 'duration_seconds')) {
            Schema::table('student_activity_events', function (Blueprint $table): void {
                $table->unsignedInteger('duration_seconds')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Shared student activity history is preserved when either plugin is removed.
    }
};
