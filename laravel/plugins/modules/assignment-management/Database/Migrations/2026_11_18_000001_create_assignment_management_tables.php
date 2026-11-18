<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('assignment_tasks')) {
            Schema::create('assignment_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                // Optional integration: intentionally no FK to the optional Course Management plugin.
                $table->unsignedBigInteger('course_id')->nullable()->index();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 180);
                $table->longText('instructions');
                $table->timestamp('due_at')->nullable()->index();
                $table->decimal('max_score', 8, 2)->default(100);
                $table->boolean('allow_late')->default(false);
                $table->string('status', 20)->default('draft')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('assignment_submissions')) {
            Schema::create('assignment_submissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assignment_id')->constrained('assignment_tasks')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->longText('answer_text')->nullable();
                $table->string('attachment_path')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->string('status', 20)->default('submitted')->index();
                $table->decimal('score', 8, 2)->nullable();
                $table->text('feedback')->nullable();
                $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('graded_at')->nullable();
                $table->timestamps();
                $table->unique(['assignment_id', 'student_id']);
                $table->index(['student_id', 'submitted_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignment_tasks');
    }
};
