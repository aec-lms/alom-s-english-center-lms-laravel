<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\BatchManagement\Models\StudyBatch;
use Plugins\Modules\ClassManagement\Models\AcademicClass;
use Tests\TestCase;

class RecycleBinTest extends TestCase
{
    public function test_student_delete_preserves_history_and_admin_can_restore_previous_account_state(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');
        $learner = $this->createUserWithRole('student');

        $student = Student::query()->create([
            'user_id' => $learner->id,
            'student_code' => 'RECYCLE-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)->delete('/admin/students/' . $student->id)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('students', ['id' => $student->id]);
        $this->assertDatabaseHas('users', ['id' => $learner->id, 'is_active' => false]);

        $this->actingAs($admin)->get('/admin/recycle-bin')
            ->assertOk()
            ->assertSee('RECYCLE-001');

        $this->actingAs($admin)->post('/admin/recycle-bin/students/' . $student->id . '/restore')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $learner->id, 'is_active' => true]);
    }

    public function test_course_exam_batch_and_class_records_can_be_trashed_and_restored(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'exam-management', 'batch-management', 'class-management']);
        $admin = $this->createUserWithRole('admin');

        $course = Course::query()->create([
            'code' => 'RB-COURSE-01',
            'title' => 'Recycle Bin Course',
            'slug' => 'recycle-bin-course',
            'price' => 0,
            'status' => 'draft',
        ]);
        $exam = Exam::query()->create([
            'title' => 'Recycle Bin Exam',
            'duration_minutes' => 30,
            'pass_marks' => 10,
            'is_published' => false,
        ]);
        $batch = StudyBatch::query()->create([
            'course_title' => 'Recycle Bin Course',
            'batch_code' => 'RB-BATCH-01',
            'name' => 'Recycle Bin Batch',
            'schedule' => 'Mon 10:00-11:00',
            'max_capacity' => 20,
        ]);
        $academicClass = AcademicClass::query()->create([
            'code' => 'RB-CLASS-01',
            'name' => 'Recycle Bin Class',
        ]);

        $course->delete();
        $exam->delete();
        $batch->delete();
        $academicClass->delete();

        $this->assertTrue(Course::withTrashed()->findOrFail($course->id)->trashed());
        $this->assertTrue(Exam::withTrashed()->findOrFail($exam->id)->trashed());
        $this->assertTrue(StudyBatch::withTrashed()->findOrFail($batch->id)->trashed());
        $this->assertTrue(AcademicClass::withTrashed()->findOrFail($academicClass->id)->trashed());

        $this->actingAs($admin)->get('/admin/recycle-bin')
            ->assertOk()
            ->assertSee('Recycle Bin Course')
            ->assertSee('Recycle Bin Exam')
            ->assertSee('Recycle Bin Batch')
            ->assertSee('Recycle Bin Class');

        $this->actingAs($admin)->post('/admin/recycle-bin/courses/' . $course->id . '/restore')->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/exams/' . $exam->id . '/restore')->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/batches/' . $batch->id . '/restore')->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/classes/' . $academicClass->id . '/restore')->assertRedirect();

        $this->assertFalse(Course::query()->findOrFail($course->id)->trashed());
        $this->assertFalse(Exam::query()->findOrFail($exam->id)->trashed());
        $this->assertFalse(StudyBatch::query()->findOrFail($batch->id)->trashed());
        $this->assertFalse(AcademicClass::query()->findOrFail($academicClass->id)->trashed());
    }

    public function test_non_admin_cannot_view_or_restore_recycle_bin_records(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->get('/admin/recycle-bin')->assertForbidden();
        $this->actingAs($student)->post('/admin/recycle-bin/students/1/restore')->assertForbidden();
    }
}
