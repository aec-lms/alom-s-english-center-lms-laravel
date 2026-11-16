<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Tests\TestCase;

class CourseOptionalIntegrationsTest extends TestCase
{
    public function test_course_enrollment_lists_work_without_fees_and_student_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);
        $student = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'FREE-OPT-001',
            'title' => 'Free Optional Integration Course',
            'slug' => 'free-optional-integration-course',
            'description' => 'Course data remains available without finance integrations.',
            'price' => 0,
            'status' => 'published',
        ]);
        $this->actingAs($student)->post('/courses/' . $course->id . '/enroll')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'branch_id' => null,
            'invoice_id' => null,
            'status' => 'active',
        ]);

        $this->actingAs($student)->get('/my-learning/enrollments')->assertOk();
    }
}
