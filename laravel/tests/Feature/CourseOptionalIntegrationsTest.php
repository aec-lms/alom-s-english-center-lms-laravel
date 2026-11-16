<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Tests\TestCase;

class CourseOptionalIntegrationsTest extends TestCase
{
    public function test_course_enrollment_lists_work_without_fees_and_student_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'fees-management']);
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

        $paidCourse = Course::query()->create([
            'code' => 'PAID-OPT-001',
            'title' => 'Paid Optional Integration Course',
            'slug' => 'paid-optional-integration-course',
            'description' => 'Paid course uses standalone payer invoice without a Student profile.',
            'price' => 2400,
            'status' => 'published',
        ]);
        $this->actingAs($student)->post('/courses/' . $paidCourse->id . '/enroll')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $paidEnrollment = DB::table('enrollments')->where('course_id', $paidCourse->id)->first();
        $this->assertNotNull($paidEnrollment);
        $this->assertSame('pending', $paidEnrollment->status);
        $invoice = DB::table('fee_invoices')->where('id', $paidEnrollment->invoice_id)->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->student_id);
        $this->assertSame($student->name, $invoice->payer_name);
        $this->assertSame($student->email, $invoice->payer_email);

        $this->actingAs($student)->get('/my-learning/enrollments')->assertOk();
    }

    public function test_course_enrollment_views_do_not_query_invoice_table_when_fees_plugin_is_disabled(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);
        $student = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'FREE-NO-FEES-001',
            'title' => 'Free Course Without Fees',
            'slug' => 'free-course-without-fees',
            'description' => 'Enrollment records can be listed without the Fees plugin.',
            'price' => 0,
            'status' => 'published',
        ]);
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'branch_id' => null,
            'invoice_id' => null,
            'status' => 'active',
        ]);

        $this->actingAs($student)->get('/my-learning/enrollments')->assertOk();
        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->get('/admin/enrollments')->assertOk();
    }

}
