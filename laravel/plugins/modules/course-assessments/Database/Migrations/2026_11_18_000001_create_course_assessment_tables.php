<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_assessment_sets')) {
            Schema::create('course_assessment_sets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('course_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('title', 180);
                $table->text('description')->nullable();
                $table->string('status', 16)->default('draft')->index();
                $table->unsignedSmallInteger('time_limit_minutes')->nullable();
                $table->unsignedSmallInteger('max_attempts')->nullable();
                $table->unsignedSmallInteger('questions_per_attempt')->nullable();
                $table->boolean('randomize_questions')->default(true);
                $table->timestamp('available_from')->nullable();
                $table->timestamp('available_until')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['course_id', 'status', 'available_from']);
            });
        }

        if (!Schema::hasTable('course_assessment_questions')) {
            Schema::create('course_assessment_questions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assessment_set_id')->constrained('course_assessment_sets')->cascadeOnDelete();
                $table->unsignedBigInteger('source_question_id')->nullable()->index();
                $table->string('type', 20);
                $table->longText('question');
                $table->json('options')->nullable();
                $table->unsignedTinyInteger('correct_option')->nullable();
                $table->longText('correct_answer')->nullable();
                $table->longText('explanation')->nullable();
                $table->string('difficulty', 16)->default('medium');
                $table->json('tags')->nullable();
                $table->decimal('marks', 8, 2)->default(1);
                $table->unsignedInteger('position')->default(10);
                $table->timestamps();
                $table->index(['assessment_set_id', 'position']);
            });
        }

        if (!Schema::hasTable('course_assessment_attempts')) {
            Schema::create('course_assessment_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assessment_set_id')->constrained('course_assessment_sets')->cascadeOnDelete();
                $table->unsignedBigInteger('course_id')->nullable()->index();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedSmallInteger('attempt_number');
                $table->string('status', 20)->default('in_progress')->index();
                $table->timestamp('started_at');
                $table->timestamp('deadline_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->decimal('auto_score', 10, 2)->default(0);
                $table->decimal('manual_score', 10, 2)->default(0);
                $table->decimal('max_score', 10, 2)->default(0);
                $table->decimal('final_score', 10, 2)->nullable();
                $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('graded_at')->nullable();
                $table->text('teacher_feedback')->nullable();
                $table->timestamps();
                $table->unique(['assessment_set_id', 'student_id', 'attempt_number'], 'course_assessment_attempt_unique');
                $table->index(['student_id', 'status']);
            });
        }

        if (!Schema::hasTable('course_assessment_attempt_questions')) {
            Schema::create('course_assessment_attempt_questions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attempt_id')->constrained('course_assessment_attempts')->cascadeOnDelete();
                $unsigned = $table->unsignedBigInteger('source_question_id')->nullable()->index();
                $table->string('type', 20);
                $table->longText('question');
                $table->json('options')->nullable();
                $table->unsignedTinyInteger('correct_option')->nullable();
                $table->longText('correct_answer')->nullable();
                $table->longText('explanation')->nullable();
                $table->decimal('marks', 8, 2)->default(1);
                $table->unsignedInteger('position')->default(10);
                $table->unsignedTinyInteger('selected_option')->nullable();
                $table->longText('answer_text')->nullable();
                $table->boolean('is_correct')->nullable();
                $table->decimal('marks_awarded', 8, 2)->default(0);
                $table->text('manual_feedback')->nullable();
                $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('graded_at')->nullable();
                $table->timestamps();
                $table->index(['attempt_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        // Assessment history and student work are retained on disable/uninstall.
    }
};
