<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Plugins\Modules\CourseManagement\Models\LiveClass;
use Tests\TestCase;

final class ClassSessionSettlementTest extends TestCase
{
    public function test_completing_a_class_creates_teacher_payable_and_student_fee_invoices_once(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'student-management', 'fees-management']);

        $admin = $this->createUserWithRole('admin');
        $permissionId = Permission::query()->where('slug', 'courses.manage')->value('id');
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $teacher = $this->createUserWithRole('teacher');
        $course = Course::query()->create([
            'code' => 'CLS-SETTLE-01',
            'title' => 'Class Settlement Test',
            'slug' => 'class-settlement-test',
            'price' => 0,
            'status' => 'published',
        ]);
        DB::table('course_teacher_assignments')->insert([
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
            'assigned_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id,
            'teacher_user_id' => $teacher->id,
            'teacher_rate_per_class' => 1000,
            'student_fee_per_class' => 250,
            'title' => 'Completed Class',
            'instructor_name' => $teacher->name,
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subHour(),
            'status' => 'live',
            'capacity' => 50,
        ]);

        $students = [];
        foreach (['CLS-STU-001', 'CLS-STU-002'] as $code) {
            $user = $this->createUserWithRole('student');
            DB::table('students')->insert([
                'user_id' => $user->id,
                'branch_id' => null,
                'student_code' => $code,
                'admission_date' => now()->toDateString(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Enrollment::query()->create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'status' => 'active',
            ]);
            $students[] = $user;
        }

        $this->actingAs($admin)
            ->post("/admin/courses/live-classes/{$liveClass->id}/complete-session")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('class_session_records', [
            'live_class_id' => $liveClass->id,
            'teacher_user_id' => $teacher->id,
            'teacher_rate' => '1000.00',
            'student_fee_rate' => '250.00',
            'enrolled_student_count' => 2,
            'student_fees_total' => '500.00',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('teacher_class_payments', [
            'teacher_user_id' => $teacher->id,
            'amount' => '1000.00',
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('class_session_student_charges', 2);
        $this->assertDatabaseCount('fee_invoices', 2);
        $this->assertSame('completed', $liveClass->fresh()->status);

        $this->actingAs($admin)
            ->post("/admin/courses/live-classes/{$liveClass->id}/complete-session")
            ->assertStatus(409);

        $this->assertDatabaseCount('class_session_records', 1);
        $this->assertDatabaseCount('teacher_class_payments', 1);
        $this->assertDatabaseCount('class_session_student_charges', 2);
        $this->assertDatabaseCount('fee_invoices', 2);

        $paymentId = DB::table('teacher_class_payments')->value('id');
        $this->actingAs($admin)->post("/admin/courses/teacher-class-payments/{$paymentId}/mark-paid", [
            'payment_reference' => 'BANK-REF-1001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teacher_class_payments', [
            'id' => $paymentId,
            'status' => 'paid',
            'payment_reference' => 'BANK-REF-1001',
        ]);
        $this->actingAs($admin)->post("/admin/courses/teacher-class-payments/{$paymentId}/mark-paid", [
            'payment_reference' => 'DUPLICATE',
        ])->assertStatus(409);
    }

    public function test_cancelled_class_cannot_create_teacher_pay_or_student_invoices(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'student-management', 'fees-management']);

        $admin = $this->createUserWithRole('admin');
        $permissionId = Permission::query()->where('slug', 'courses.manage')->value('id');
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);
        $teacher = $this->createUserWithRole('teacher');
        $course = Course::query()->create([
            'code' => 'CLS-CANCEL-01',
            'title' => 'Cancelled Class Test',
            'slug' => 'cancelled-class-test',
            'price' => 0,
            'status' => 'published',
        ]);
        DB::table('course_teacher_assignments')->insert([
            'course_id' => $course->id, 'teacher_user_id' => $teacher->id,
            'assigned_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $liveClass = LiveClass::query()->create([
            'course_id' => $course->id, 'teacher_user_id' => $teacher->id,
            'teacher_rate_per_class' => 900, 'student_fee_per_class' => 200,
            'title' => 'Cancelled Session', 'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'starts_at' => now()->subHour(), 'status' => 'cancelled', 'capacity' => 20,
        ]);

        $this->actingAs($admin)
            ->post("/admin/courses/live-classes/{$liveClass->id}/complete-session")
            ->assertStatus(409);
        $this->assertDatabaseCount('class_session_records', 0);
        $this->assertDatabaseCount('teacher_class_payments', 0);
        $this->assertDatabaseCount('fee_invoices', 0);
    }
}
