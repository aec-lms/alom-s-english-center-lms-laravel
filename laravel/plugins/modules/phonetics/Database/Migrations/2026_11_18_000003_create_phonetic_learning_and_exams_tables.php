<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('phonetic_learning_assignments')) {
            Schema::create('phonetic_learning_assignments', function (Blueprint $table): void {
                $table->id();
                $table->string('lms_driver', 40)->default('aec');
                $table->string('course_ref', 128)->nullable()->index();
                $table->string('course_category_ref', 128)->nullable()->index();
                $table->string('lesson_ref', 128)->nullable()->index();
                $table->string('batch_ref', 128)->nullable()->index();
                $table->foreignId('word_id')->constrained('alom_english_center_phonetic_entries')->cascadeOnDelete();
                $table->string('audience', 16)->default('both'); // both, free, paid
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_required')->default(true);
                $table->timestamp('available_from')->nullable();
                $table->timestamp('available_until')->nullable();
                $table->timestamps();
                $table->index(['lms_driver', 'course_ref', 'lesson_ref', 'batch_ref'], 'phonetic_assignment_scope_idx');
            });
        }

        if (!Schema::hasTable('phonetic_exams')) {
            Schema::create('phonetic_exams', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 180);
                $table->string('slug', 200)->unique();
                $table->text('description')->nullable();
                $table->string('lms_driver', 40)->default('aec');
                $table->string('course_ref', 128)->nullable()->index();
                $table->string('course_category_ref', 128)->nullable()->index();
                $table->string('lesson_ref', 128)->nullable()->index();
                $table->string('batch_ref', 128)->nullable()->index();
                $table->string('audience', 16)->default('both'); // both, free, paid
                $table->unsignedInteger('duration_minutes')->default(0); // zero means untimed
                $table->unsignedSmallInteger('pass_percentage')->default(60);
                $table->unsignedSmallInteger('max_attempts')->default(0); // zero means unlimited
                $table->unsignedSmallInteger('question_count')->default(10);
                $table->boolean('shuffle_questions')->default(true);
                $table->boolean('shuffle_options')->default(true);
                $table->boolean('show_result_immediately')->default(true);
                $table->timestamp('available_from')->nullable();
                $table->timestamp('available_until')->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('phonetic_exam_questions')) {
            Schema::create('phonetic_exam_questions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('exam_id')->constrained('phonetic_exams')->cascadeOnDelete();
                $table->foreignId('word_id')->nullable()->constrained('alom_english_center_phonetic_entries')->nullOnDelete();
                $table->string('question_type', 32)->default('identify-ipa');
                $table->text('prompt');
                $table->json('options');
                $table->string('answer_key', 255);
                $table->unsignedInteger('marks')->default(1);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['exam_id', 'sort_order']);
            });
        }

        if (!Schema::hasTable('phonetic_exam_attempts')) {
            Schema::create('phonetic_exam_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('exam_id')->constrained('phonetic_exams')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 20)->default('in_progress');
                $table->json('question_snapshot')->nullable();
                $table->json('answers')->nullable();
                $table->unsignedInteger('score')->default(0);
                $table->unsignedInteger('total_marks')->default(0);
                $table->decimal('percentage', 5, 2)->default(0);
                $table->boolean('passed')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
                $table->index(['exam_id', 'user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phonetic_exam_attempts');
        Schema::dropIfExists('phonetic_exam_questions');
        Schema::dropIfExists('phonetic_exams');
        Schema::dropIfExists('phonetic_learning_assignments');
    }
};
