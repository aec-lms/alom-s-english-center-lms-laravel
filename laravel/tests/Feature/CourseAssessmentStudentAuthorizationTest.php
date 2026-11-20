<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\CourseAssessments\Models\AssessmentAttempt;
use Plugins\Modules\CourseAssessments\Models\AssessmentSet;
use Tests\TestCase;

class CourseAssessmentStudentAuthorizationTest extends TestCase
{
    public function test_web_attempt_and_history_endpoints_deny_users_after_student_role_revocation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);

        $student = $this->createUserWithRole('student');
        $set = AssessmentSet::query()->create([
            'title' => 'Role revocation assessment',
            'status' => 'published',
            'created_by' => $student->id,
        ]);
        $attempt = AssessmentAttempt::query()->create([
            'assessment_set_id' => $set->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'max_score' => 0,
        ]);

        $this->actingAs($student)
            ->get('/my-learning/assessments/attempts/' . $attempt->id)
            ->assertOk();

        $student->roles()->detach();

        $this->actingAs($student)
            ->get('/my-learning/assessments/attempts/' . $attempt->id)
            ->assertForbidden();

        $this->actingAs($student)
            ->post('/my-learning/assessments/attempts/' . $attempt->id . '/submit')
            ->assertForbidden();

        $this->actingAs($student)
            ->get('/my-learning/assessments/history')
            ->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attempt->id,
            'student_id' => $student->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_student_cannot_open_another_students_assessment_attempt(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);

        $owner = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');
        $set = AssessmentSet::query()->create([
            'title' => 'Attempt ownership assessment',
            'status' => 'published',
            'created_by' => $owner->id,
        ]);
        $attempt = AssessmentAttempt::query()->create([
            'assessment_set_id' => $set->id,
            'student_id' => $owner->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'max_score' => 0,
        ]);

        $this->actingAs($otherStudent)
            ->get('/my-learning/assessments/attempts/' . $attempt->id)
            ->assertNotFound();
    }
}
