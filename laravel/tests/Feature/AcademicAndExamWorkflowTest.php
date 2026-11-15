<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamAttempt;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;
use Tests\TestCase;

class AcademicAndExamWorkflowTest extends TestCase
{
    public function test_branch_isolation_blocks_cross_branch_student_creation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management']);

        $branchA = DB::table('branches')->insertGetId(['code' => 'BR-A', 'name' => 'Branch A', 'created_at' => now(), 'updated_at' => now()]);
        $branchB = DB::table('branches')->insertGetId(['code' => 'BR-B', 'name' => 'Branch B', 'created_at' => now(), 'updated_at' => now()]);

        $managerA = $this->createUserWithRole('branch_manager', $branchA);
        $managerA->roles()->first()->permissions()->syncWithoutDetaching([
            \App\Models\Permission::query()->where('slug', 'students.manage')->value('id'),
        ]);

        $this->actingAs($managerA)->post('/admin/students', [
            'name' => 'Cross Branch Attempt',
            'email' => 'cross@aec.test',
            'password' => 'StrongPassword!2026',
            'branch_id' => $branchB,
            'student_code' => 'STU-999',
        ])->assertForbidden();
    }

    public function test_exam_engine_grades_attempts_authoritatively_on_server(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'exam-management']);

        $course = Course::query()->create([
            'code' => 'IELTS-101',
            'title' => 'IELTS Masterclass',
            'slug' => 'ielts-masterclass',
            'price' => 4500,
            'status' => 'published',
        ]);

        $exam = Exam::query()->create([
            'course_id' => $course->id,
            'title' => 'Grammar Diagnostic',
            'duration_minutes' => 30,
            'pass_marks' => 5,
            'is_published' => true,
        ]);

        $q1 = ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'Select the correct tense.',
            'options' => ['had went', 'had gone', 'has went'],
            'correct_option_index' => 1,
            'marks' => 5,
        ]);

        $student = $this->createUserWithRole('student');
        $student->roles()->first()->permissions()->syncWithoutDetaching([
            \App\Models\Permission::query()->where('slug', 'exams.attempt')->value('id'),
        ]);

        // Even if client sends tampered score=999, server ignores it and computes exact 5/5.
        $this->actingAs($student)->post("/admin/exams/{$exam->id}/attempt", [
            'score_obtained' => 999,
            'answers' => [(string) $q1->id => 1],
        ])->assertRedirect();

        $attempt = ExamAttempt::query()->firstOrFail();
        $this->assertSame(5, $attempt->score_obtained);
        $this->assertSame(5, $attempt->total_marks);
        $this->assertTrue($attempt->passed);
    }
}
