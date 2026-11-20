<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\TeacherManagement\Models\Teacher;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\CourseCategory;
use Plugins\Modules\CourseManagement\Models\LiveClass;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\BatchManagement\Models\StudyBatch;
use Plugins\Modules\ClassManagement\Models\AcademicClass;
use Tests\TestCase;

class RecycleBinTest extends TestCase
{
    public function test_student_delete_preserves_history_and_restore_keeps_account_inactive(): void
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
            ->assertSee('RECYCLE-001')
            ->assertSee('Permanently Delete');

        $this->actingAs($admin)->post('/admin/recycle-bin/students/' . $student->id . '/restore')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null, 'deleted_user_was_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $learner->id, 'is_active' => false]);

        $permanentLearner = $this->createUserWithRole('student');
        $permanentStudent = Student::query()->create([
            'user_id' => $permanentLearner->id,
            'student_code' => 'RECYCLE-PERM-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $this->actingAs($admin)->delete('/admin/students/' . $permanentStudent->id)->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/students/' . $permanentStudent->id . '/permanent-delete', [
            'confirm_permanent_delete' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('students', ['id' => $permanentStudent->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recycle_bin.permanently_deleted',
            'entity_type' => 'student',
            'entity_id' => (string) $permanentStudent->id,
        ]);
    }


    public function test_teacher_restore_does_not_reactivate_account_after_later_security_deactivation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');
        $instructor = $this->createUserWithRole('teacher');

        $teacher = Teacher::query()->create([
            'user_id' => $instructor->id,
            'employee_code' => 'RECYCLE-TEACHER-001',
            'specialization' => 'English',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->delete('/admin/teachers/' . $teacher->id)->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $instructor->id, 'is_active' => false]);

        // A separate security action while the profile is trashed must remain
        // authoritative after profile restoration.
        $instructor->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->post('/admin/recycle-bin/teachers/' . $teacher->id . '/restore')
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Instructor profile restored. Account access remains disabled; an administrator must explicitly reactivate it after a fresh security check.');

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'deleted_at' => null, 'deleted_user_was_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $instructor->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recycle_bin.teacher_restored',
            'entity_type' => 'teacher',
            'entity_id' => (string) $teacher->id,
        ]);
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
            'description' => 'Course retained for restore testing.',
            'status' => 'published',
        ]);
        $category = CourseCategory::query()->create([
            'name' => 'Recycle Bin Category',
            'slug' => 'recycle-bin-category',
            'description' => 'Category retained for restore testing.',
            'color' => 'blue',
            'is_active' => true,
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'title' => 'Recycle Bin Live Class',
            'instructor_name' => 'Test Instructor',
            'platform' => 'google_meet',
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
            'starts_at' => now()->addDay(),
            'status' => 'scheduled',
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
            'status' => 'upcoming',
        ]);
        $academicClass = AcademicClass::query()->create([
            'code' => 'RB-CLASS-01',
            'name' => 'Recycle Bin Class',
            'section' => 'A',
            'subject' => 'English',
            'day_of_week' => 'Sunday',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $this->actingAs($admin)->delete('/admin/courses/' . $course->id)->assertRedirect();
        $this->actingAs($admin)->delete('/admin/courses/categories/' . $category->id)->assertRedirect();
        $this->actingAs($admin)->delete('/admin/courses/live-classes/' . $liveClass->id)->assertRedirect();
        $this->actingAs($admin)->delete('/admin/exams/' . $exam->id)->assertRedirect();
        $this->actingAs($admin)->delete('/admin/batches/' . $batch->id)->assertRedirect();
        $this->actingAs($admin)->delete('/admin/classes/' . $academicClass->id)->assertRedirect();

        $this->assertTrue(Course::withTrashed()->findOrFail($course->id)->trashed());
        $this->assertTrue(CourseCategory::withTrashed()->findOrFail($category->id)->trashed());
        $this->assertTrue(LiveClass::withTrashed()->findOrFail($liveClass->id)->trashed());
        $this->assertTrue(Exam::withTrashed()->findOrFail($exam->id)->trashed());
        $this->assertTrue(StudyBatch::withTrashed()->findOrFail($batch->id)->trashed());
        $this->assertTrue(AcademicClass::withTrashed()->findOrFail($academicClass->id)->trashed());

        $this->actingAs($admin)->get('/admin/recycle-bin')
            ->assertOk()
            ->assertSee('Recycle Bin Course')
            ->assertSee('Recycle Bin Category')
            ->assertSee('Recycle Bin Live Class')
            ->assertSee('Recycle Bin Exam')
            ->assertSee('Recycle Bin Batch')
            ->assertSee('Recycle Bin Class');

        $this->actingAs($admin)->post('/admin/recycle-bin/courses/' . $course->id . '/restore')->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/course-categories/' . $category->id . '/restore')->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/live-classes/' . $liveClass->id . '/restore')->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/exams/' . $exam->id . '/restore')->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/batches/' . $batch->id . '/restore')->assertRedirect();
        $this->actingAs($admin)->post('/admin/recycle-bin/classes/' . $academicClass->id . '/restore')->assertRedirect();

        $this->assertFalse(Course::query()->findOrFail($course->id)->trashed());
        $this->assertFalse(CourseCategory::query()->findOrFail($category->id)->trashed());
        $this->assertFalse(LiveClass::query()->findOrFail($liveClass->id)->trashed());
        $this->assertFalse(Exam::query()->findOrFail($exam->id)->trashed());
        $this->assertFalse(StudyBatch::query()->findOrFail($batch->id)->trashed());
        $this->assertFalse(AcademicClass::query()->findOrFail($academicClass->id)->trashed());
    }

    public function test_all_plugin_entities_with_delete_actions_use_soft_deletes(): void
    {
        $models = [
            \Plugins\Modules\AdminBoosting\Models\BoostCampaign::class,
            \Plugins\Modules\BranchManagement\Models\Branch::class,
            \Plugins\Modules\CarouselBuilder\Models\CarouselItem::class,
            \Plugins\Modules\CmsWebsite\Models\CmsCategory::class,
            \Plugins\Modules\CmsWebsite\Models\CmsPost::class,
            \Plugins\Modules\PageBuilder\Models\BuilderPage::class,
            \Plugins\Modules\SliderBuilder\Models\HeroSlider::class,
            \Plugins\Modules\SmsGateway\Models\SmsCampaign::class,
            \Plugins\Modules\VisitorPopupAds\Models\VisitorPopup::class,
        ];

        foreach ($models as $model) {
            $this->assertContains(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), $model . ' must support soft deletes.');
        }
    }


    public function test_admin_can_permanently_delete_a_trashed_student_only_after_confirmation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');
        $learner = $this->createUserWithRole('student');

        $student = Student::query()->create([
            'user_id' => $learner->id,
            'student_code' => 'RECYCLE-PURGE-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)->delete('/admin/students/' . $student->id)->assertRedirect();
        $this->assertSoftDeleted('students', ['id' => $student->id]);

        $this->actingAs($admin)->get('/admin/recycle-bin')
            ->assertOk()
            ->assertSee('Permanently Delete');

        $this->actingAs($admin)->post('/admin/recycle-bin/students/' . $student->id . '/permanent-delete')
            ->assertSessionHasErrors('confirm_permanent_delete');
        $this->assertSoftDeleted('students', ['id' => $student->id]);

        $this->actingAs($admin)->post('/admin/recycle-bin/students/' . $student->id . '/permanent-delete', [
            'confirm_permanent_delete' => '1',
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertDatabaseHas('users', ['id' => $learner->id]);
    }

    public function test_non_admin_cannot_view_or_restore_recycle_bin_records(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)->get('/admin/recycle-bin')->assertForbidden();
        $this->actingAs($student)->post('/admin/recycle-bin/students/1/restore')->assertForbidden();
        $this->actingAs($student)->post('/admin/recycle-bin/students/1/permanent-delete', [
            'confirm_permanent_delete' => '1',
        ])->assertForbidden();
    }

    public function test_student_restore_locks_linked_user_before_student_profile(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management']);
        $admin = $this->createUserWithRole('admin');
        $learner = $this->createUserWithRole('student');
        $student = Student::query()->create([
            'user_id' => $learner->id,
            'student_code' => 'RECYCLE-LOCK-ORDER-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)->delete('/admin/students/' . $student->id)->assertRedirect();

        $lockOrder = [];
        DB::listen(function ($query) use (&$lockOrder): void {
            $sql = strtolower($query->sql);
            if (!str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, 'users')) {
                $lockOrder[] = 'user';
            } elseif (str_contains($sql, 'students')) {
                $lockOrder[] = 'student';
            }
        });

        $this->actingAs($admin)->post('/admin/recycle-bin/students/' . $student->id . '/restore')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        if (DB::connection()->getDriverName() === 'mysql') {
            $userLock = array_search('user', $lockOrder, true);
            $profileLock = array_search('student', $lockOrder, true);
            $this->assertNotFalse($userLock, 'Restore must lock the linked user row.');
            $this->assertNotFalse($profileLock, 'Restore must lock the student profile row.');
            $this->assertLessThan($profileLock, $userLock, 'User row must be locked before student profile to match Live Class access.');
        }

        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $learner->id, 'is_active' => false]);
    }



    public function test_teacher_restore_locks_linked_user_before_teacher_profile(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');
        $instructor = $this->createUserWithRole('teacher');
        $teacher = Teacher::query()->create([
            'user_id' => $instructor->id,
            'employee_code' => 'RECYCLE-TEACHER-LOCK-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'specialization' => 'English',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->delete('/admin/teachers/' . $teacher->id)->assertRedirect();

        $lockOrder = [];
        DB::listen(function ($query) use (&$lockOrder): void {
            $sql = strtolower($query->sql);
            if (!str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, 'users')) {
                $lockOrder[] = 'user';
            } elseif (str_contains($sql, 'teachers')) {
                $lockOrder[] = 'teacher';
            }
        });

        $this->actingAs($admin)->post('/admin/recycle-bin/teachers/' . $teacher->id . '/restore')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        if (DB::connection()->getDriverName() === 'mysql') {
            $userLock = array_search('user', $lockOrder, true);
            $profileLock = array_search('teacher', $lockOrder, true);
            $this->assertNotFalse($userLock, 'Restore must lock the linked user row.');
            $this->assertNotFalse($profileLock, 'Restore must lock the teacher profile row.');
            $this->assertLessThan($profileLock, $userLock, 'User row must be locked before teacher profile to match Live Class access.');
        }

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $instructor->id, 'is_active' => false]);
    }

}
