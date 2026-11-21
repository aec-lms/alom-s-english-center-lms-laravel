<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('learning_paths')) {
            Schema::create('learning_paths', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 180);
                $table->string('slug', 200)->unique();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('learning_path_courses')) {
            Schema::create('learning_path_courses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('learning_path_id')->constrained('learning_paths')->cascadeOnDelete();
                // Course Management is optional; keep this plugin independently installable.
                $table->unsignedBigInteger('course_id')->index();
                $table->string('course_title', 180);
                $table->unsignedInteger('position');
                $table->timestamps();
                $table->unique(['learning_path_id', 'course_id']);
                $table->unique(['learning_path_id', 'position']);
            });
        }

        if (!Schema::hasTable('learning_path_enrollments')) {
            Schema::create('learning_path_enrollments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('learning_path_id')->constrained('learning_paths')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('status', 20)->default('active')->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['learning_path_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        // Learning-path history is retained by the LMS data-preservation policy.
    }
};
