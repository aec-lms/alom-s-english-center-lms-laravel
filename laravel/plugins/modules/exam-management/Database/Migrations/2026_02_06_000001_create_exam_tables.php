<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('exams')) {
            Schema::create('exams', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('title', 180);
                $table->unsignedInteger('duration_minutes')->default(30);
                $table->unsignedInteger('pass_marks')->default(40);
                // Optional Learn & Earn integration columns are part of the base schema so either plugin can be installed first.
                $table->string('learn_earn_type', 12)->default('free');
                $table->decimal('access_price', 10, 2)->default(0);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_published')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('exam_questions')) {
            Schema::create('exam_questions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
                $table->text('prompt');
                $table->json('options');
                $table->unsignedInteger('correct_option_index');
                $table->decimal('marks', 8, 2)->default(1);
                $table->decimal('negative_marks', 8, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('exam_attempts')) {
            Schema::create('exam_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->json('submitted_answers');
                $table->unsignedInteger('score_obtained')->default(0);
                $table->unsignedInteger('total_marks')->default(0);
                $table->boolean('passed')->default(false);
                $table->timestamp('submitted_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
