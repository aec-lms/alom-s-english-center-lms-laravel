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

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'FLASH-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Flash Sale Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentUser = $this->createUserWithRole('student', $branchId);
        Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
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

    public function test_admin_can_view_edit_toggle_and_delete_flash_sale_campaigns(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $admin = $this->createUserWithRole('admin');
        $course = Course::query()->create([
            'code' => 'FLASH-ADMIN-01',
            'title' => 'Admin Flash Course',
            'slug' => 'admin-flash-course',
            'price' => 500,
            'status' => 'published',
        ]);
        $saleId = DB::table('flash_sales')->insertGetId([
            'course_id' => $course->id,
            'title' => 'Admin Campaign',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'is_published' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get('/admin/flash-sales')
            ->assertOk()
            ->assertSee('Admin Campaign')
            ->assertSee('Edit');

        $this->actingAs($admin)->put('/admin/flash-sales/' . $saleId, [
            'course_id' => $course->id,
            'title' => 'Updated Campaign',
            'discount_type' => 'fixed',
            'discount_value' => 75,
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'is_published' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('flash_sales', [
            'id' => $saleId,
            'title' => 'Updated Campaign',
            'discount_type' => 'fixed',
            'discount_value' => 75,
        ]);

        $this->actingAs($admin)->post('/admin/flash-sales/' . $saleId . '/toggle')
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('flash_sales', ['id' => $saleId, 'is_active' => false]);

        $this->actingAs($admin)->delete('/admin/flash-sales/' . $saleId)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('flash_sales', ['id' => $saleId]);
    }

}
