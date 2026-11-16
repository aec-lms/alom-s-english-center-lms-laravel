<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use App\Models\Permission;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\TeacherManagement\Models\Teacher;
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


    public function test_branch_manager_cannot_delete_another_branch_student_or_operate_without_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management']);

        $branchA = DB::table('branches')->insertGetId(['code' => 'DEL-A', 'name' => 'Delete Branch A', 'created_at' => now(), 'updated_at' => now()]);
        $branchB = DB::table('branches')->insertGetId(['code' => 'DEL-B', 'name' => 'Delete Branch B', 'created_at' => now(), 'updated_at' => now()]);

        $managerA = $this->createUserWithRole('branch_manager', $branchA);
        $permissionId = Permission::query()->where('slug', 'students.manage')->value('id');
        $managerA->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $studentUser = $this->createUserWithRole('student', $branchB);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchB,
            'student_code' => 'STU-BR-B-01',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($managerA)
            ->delete('/admin/students/' . $student->id)
            ->assertForbidden();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'branch_id' => $branchB]);
        $this->assertTrue((bool) $studentUser->fresh()->is_active);

        $unassignedManager = $this->createUserWithRole('branch_manager');
        $unassignedManager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);
        $this->actingAs($unassignedManager)->get('/admin/students')->assertForbidden();
    }


    public function test_branch_manager_cannot_create_or_delete_teachers_outside_their_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'teacher-management']);

        $branchA = DB::table('branches')->insertGetId(['code' => 'TCH-A', 'name' => 'Teacher Branch A', 'created_at' => now(), 'updated_at' => now()]);
        $branchB = DB::table('branches')->insertGetId(['code' => 'TCH-B', 'name' => 'Teacher Branch B', 'created_at' => now(), 'updated_at' => now()]);

        $managerA = $this->createUserWithRole('branch_manager', $branchA);
        $permissionId = Permission::query()->where('slug', 'teachers.manage')->value('id');
        $managerA->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $teacherUser = $this->createUserWithRole('teacher', $branchB);
        $teacher = Teacher::query()->create([
            'user_id' => $teacherUser->id,
            'branch_id' => $branchB,
            'employee_code' => 'TCH-B-001',
            'specialization' => 'IELTS',
            'is_active' => true,
        ]);

        $this->actingAs($managerA)->delete('/admin/teachers/' . $teacher->id)->assertForbidden();
        $this->actingAs($managerA)->post('/admin/teachers', [
            'name' => 'Cross Branch Teacher',
            'email' => 'cross.teacher@aec.test',
            'password' => 'StrongPassword!2026',
            'branch_id' => $branchB,
            'employee_code' => 'TCH-X-001',
            'specialization' => 'IELTS',
        ])->assertForbidden();

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'branch_id' => $branchB]);
        $this->assertDatabaseMissing('users', ['email' => 'cross.teacher@aec.test']);
    }

    public function test_attendance_rejects_student_and_batch_from_different_branches(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'attendance-management']);

        $branchA = DB::table('branches')->insertGetId(['code' => 'ATT-A', 'name' => 'Attendance A', 'created_at' => now(), 'updated_at' => now()]);
        $branchB = DB::table('branches')->insertGetId(['code' => 'ATT-B', 'name' => 'Attendance B', 'created_at' => now(), 'updated_at' => now()]);

        $managerA = $this->createUserWithRole('branch_manager', $branchA);
        $permissionId = Permission::query()->where('slug', 'attendance.manage')->value('id');
        $managerA->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $studentUser = $this->createUserWithRole('student', $branchA);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchA,
            'student_code' => 'ATT-STU-A',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $course = Course::query()->create([
            'code' => 'ATT-COURSE-B',
            'title' => 'Branch B Course',
            'slug' => 'branch-b-course',
            'price' => 0,
            'status' => 'published',
            'branch_id' => $branchB,
        ]);
        $batchId = DB::table('batches')->insertGetId([
            'course_id' => $course->id,
            'branch_id' => $branchB,
            'name' => 'Branch B Batch',
            'capacity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($managerA)->post('/admin/attendance', [
            'batch_id' => $batchId,
            'student_id' => $student->id,
            'branch_id' => $branchA,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
        ])->assertStatus(422);

        $this->assertDatabaseCount('attendance_records', 0);
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

        $this->actingAs($student)->post("/admin/exams/{$exam->id}/attempt", [
            'answers' => [(string) $q1->id => 99],
        ])->assertStatus(422);
    }
    public function test_only_students_can_attempt_exams_and_exam_time_window_is_enforced(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'exam-management']);

        $course = Course::query()->create([
            'code' => 'EXAM-WINDOW-01',
            'title' => 'Exam Window Course',
            'slug' => 'exam-window-course',
            'price' => 0,
            'status' => 'published',
        ]);
        $exam = Exam::query()->create([
            'course_id' => $course->id,
            'title' => 'Scheduled Diagnostic',
            'duration_minutes' => 30,
            'pass_marks' => 1,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'is_published' => true,
        ]);
        ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'Choose the correct answer.',
            'options' => ['A', 'B'],
            'correct_option_index' => 0,
            'marks' => 1,
        ]);

        $teacher = $this->createUserWithRole('teacher');
        $attemptPermission = Permission::query()->where('slug', 'exams.attempt')->value('id');
        $teacher->roles()->first()->permissions()->syncWithoutDetaching([$attemptPermission]);

        $this->actingAs($teacher)->post('/admin/exams/' . $exam->id . '/attempt', [
            'answers' => [],
        ])->assertForbidden();

        $student = $this->createUserWithRole('student');
        $this->actingAs($student)->post('/admin/exams/' . $exam->id . '/attempt', [
            'answers' => [],
        ])->assertForbidden();

        $exam->update(['starts_at' => null, 'ends_at' => now()->subMinute()]);
        $this->actingAs($student)->post('/admin/exams/' . $exam->id . '/attempt', [
            'answers' => [],
        ])->assertForbidden();
    }

    public function test_paid_course_enrollment_is_activated_only_after_verified_invoice_settlement(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'fees-management',
            'payment-management',
            'course-management',
        ]);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'ENR-A',
            'name' => 'Enrollment Branch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentUser = $this->createUserWithRole('student', $branchId);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
            'student_code' => 'ENR-STU-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $course = Course::query()->create([
            'code' => 'ENR-PAID-001',
            'title' => 'Paid IELTS Course',
            'slug' => 'paid-ielts-course',
            'price' => 1500,
            'status' => 'published',
            'branch_id' => $branchId,
        ]);

        $this->actingAs($studentUser)->post('/courses/' . $course->id . '/enroll')->assertSessionHasNoErrors();

        $enrollment = Enrollment::query()->where('user_id', $studentUser->id)->where('course_id', $course->id)->firstOrFail();
        $invoice = FeeInvoice::query()->findOrFail($enrollment->invoice_id);
        $this->assertSame('pending', $enrollment->status);
        $this->assertSame('unpaid', $invoice->status);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->patch('/admin/enrollments/' . $enrollment->id . '/status', [
            'status' => 'active',
        ])->assertStatus(422);

        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'ENR-TXN-001',
            'invoice_id' => $invoice->id,
            'branch_id' => $branchId,
            'gateway_slug' => 'offline',
            'amount' => 1500,
            'currency' => 'BDT',
            'status' => 'initiated',
        ]);

        app(PaymentSettlementService::class)->settleTransaction($transaction, 'ENR-RECEIPT-001');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $enrollment->fresh()->status);
    }

}
