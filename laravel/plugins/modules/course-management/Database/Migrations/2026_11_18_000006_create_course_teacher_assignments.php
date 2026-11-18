<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_teacher_assignments')) {
            Schema::create('course_teacher_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('teacher_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['course_id', 'teacher_user_id'], 'course_teacher_assignment_unique');
                $table->index(['teacher_user_id', 'course_id']);
            });
        }
    }

    public function down(): void
    {
        // Keep teaching assignment history with course-management data.
    }
};
