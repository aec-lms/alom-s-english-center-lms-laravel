<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class FlashSaleEnrollmentTest extends TestCase
{
    public function test_active_flash_sale_discount_is_applied_to_server_generated_course_invoice(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'fees-management',
            'course-management',
            'flash-sale',
        ]);

        $studentUser = $this->createUserWithRole('student');
        Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => null,
            'student_code' => 'FLASH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'admission_date' => today(),
            'status' => 'active',
        ]);

        $course = Course::query()->create([
            'code' => 'FLASH-COURSE-01',
            'title' => 'Flash Sale English Course',
            'slug' => 'flash-sale-english-course',
            'price' => 1000,
            'status' => 'published',
        ]);

        DB::table('flash_sales')->insert([
            'course_id' => $course->id,
            'title' => 'Launch Week Discount',
            'discount_type' => 'percent',
            'discount_value' => 25,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'is_published' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($studentUser)
            ->post('/courses/' . $course->id . '/enroll')
            ->assertSessionHasNoErrors();

        $enrollment = Enrollment::query()
            ->where('user_id', $studentUser->id)
            ->where('course_id', $course->id)
            ->firstOrFail();
        $invoice = FeeInvoice::query()->findOrFail($enrollment->invoice_id);

        $this->assertSame('pending', $enrollment->status);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertEquals(1000.00, (float) $invoice->subtotal_amount);
        $this->assertEquals(250.00, (float) $invoice->discount_amount);
        $this->assertEquals(750.00, (float) $invoice->net_amount);
    }
}
