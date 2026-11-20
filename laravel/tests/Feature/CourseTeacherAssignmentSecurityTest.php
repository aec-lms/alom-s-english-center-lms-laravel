<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\CourseManagement\Models\Course;
use Tests\TestCase;

final class CourseTeacherAssignmentSecurityTest extends TestCase
{
    public function test_inactive_teacher_is_not_offered_or_assignable_to_a_course(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'teacher-management']);

        $admin = $this->createUserWithRole('admin');
        $inactiveTeacher = $this->createUserWithRole('teacher');
        $inactiveTeacher->forceFill(['is_active' => false])->save();

        $course = Course::query()->create([
            'code' => 'INACTIVE-TEACHER-COURSE',
            'title' => 'Inactive Teacher Assignment Course',
            'slug' => 'inactive-teacher-assignment-course',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);

        $this->actingAs($admin)->get('/admin/courses/teacher-assignments')
            ->assertOk()
            ->assertDontSee($inactiveTeacher->email);

        $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $inactiveTeacher->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('course_teacher_assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $inactiveTeacher->id,
        ]);
    }

    public function test_active_teacher_can_still_be_assigned_to_a_course(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'teacher-management']);

        $admin = $this->createUserWithRole('admin');
        $teacher = $this->createUserWithRole('teacher');
        $course = Course::query()->create([
            'code' => 'ACTIVE-TEACHER-COURSE',
            'title' => 'Active Teacher Assignment Course',
            'slug' => 'active-teacher-assignment-course',
            'price' => 0,
            'status' => 'draft',
            'branch_id' => null,
        ]);

        $this->actingAs($admin)->get('/admin/courses/teacher-assignments')
            ->assertOk()
            ->assertSee($teacher->email);

        $this->actingAs($admin)->post('/admin/courses/teacher-assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_teacher_assignments', [
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
            'assigned_by' => $admin->id,
        ]);
    }
}
