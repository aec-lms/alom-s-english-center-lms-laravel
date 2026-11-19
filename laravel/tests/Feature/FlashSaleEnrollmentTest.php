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

    public function test_flash_sale_pricing_service_selects_highest_priority_and_caps_fixed_discount(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $course = Course::query()->create([
            'code' => 'FLASH-QUOTE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Campaign Quote Course',
            'slug' => 'campaign-quote-' . bin2hex(random_bytes(3)),
            'price' => 500,
            'status' => 'published',
        ]);

        foreach ([
            ['title' => 'Lower priority', 'discount_type' => 'percent', 'discount_value' => 10, 'priority' => 1],
            ['title' => 'Premium priority', 'discount_type' => 'fixed', 'discount_value' => 900, 'priority' => 20],
        ] as $campaign) {
            DB::table('flash_sales')->insert($campaign + [
                'course_id' => $course->id,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDay(),
                'is_published' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $quote = app(\Plugins\Modules\FlashSale\Services\FlashSalePricingService::class)
            ->quote((int) $course->id);

        $this->assertSame('Premium priority', $quote['sale']->title);
        $this->assertEquals(500.00, $quote['subtotal']);
        $this->assertEquals(500.00, $quote['discount']);
        $this->assertEquals(0.00, $quote['total']);
    }

    public function test_flash_sale_pricing_service_ignores_unpublished_inactive_and_expired_campaigns(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $course = Course::query()->create([
            'code' => 'FLASH-INACTIVE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Inactive Campaign Course',
            'slug' => 'inactive-campaign-' . bin2hex(random_bytes(3)),
            'price' => 800,
            'status' => 'published',
        ]);

        foreach ([
            ['title' => 'Draft', 'is_published' => false, 'is_active' => true, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()],
            ['title' => 'Paused', 'is_published' => true, 'is_active' => false, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()],
            ['title' => 'Expired', 'is_published' => true, 'is_active' => true, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()],
        ] as $campaign) {
            DB::table('flash_sales')->insert($campaign + [
                'course_id' => $course->id,
                'discount_type' => 'percent',
                'discount_value' => 80,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $quote = app(\Plugins\Modules\FlashSale\Services\FlashSalePricingService::class)
            ->quote((int) $course->id);

        $this->assertNull($quote['sale']);
        $this->assertEquals(800.00, $quote['subtotal']);
        $this->assertEquals(0.00, $quote['discount']);
        $this->assertEquals(800.00, $quote['total']);
    }

    public function test_unified_commerce_catalog_uses_the_same_flash_sale_quote_as_enrollment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'fees-management', 'course-management', 'flash-sale', 'commerce-cart']);

        $course = Course::query()->create([
            'code' => 'FLASH-COMMERCE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Unified Commerce Flash Course',
            'slug' => 'unified-commerce-flash-' . bin2hex(random_bytes(3)),
            'price' => 1200,
            'status' => 'published',
        ]);
        DB::table('flash_sales')->insert([
            'course_id' => $course->id,
            'title' => 'Unified Cart Launch Offer',
            'discount_type' => 'percent',
            'discount_value' => 15,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'is_published' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quote = app(\Plugins\Modules\CommerceCart\Services\CommerceCatalog::class)
            ->resolve('course', (int) $course->id);

        $this->assertEquals(1020.00, $quote['price']);
        $this->assertSame('Unified Cart Launch Offer', $quote['metadata']['flash_sale']['campaign_title']);
        $this->assertEquals(180.00, $quote['metadata']['flash_sale']['discount_amount']);
    }

    public function test_admin_can_create_premium_campaign_and_lifecycle_is_audited(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $admin = $this->createUserWithRole('admin');
        $course = Course::query()->create([
            'code' => 'FLASH-CREATE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Premium Campaign Course',
            'slug' => 'premium-campaign-' . bin2hex(random_bytes(3)),
            'price' => 1500,
            'status' => 'published',
        ]);

        $this->actingAs($admin)->post('/admin/flash-sales', [
            'course_id' => $course->id,
            'title' => 'Premium Launch Week',
            'subtitle' => 'A focused offer for ambitious learners',
            'description' => 'Join the next learning cohort with a limited-time price.',
            'badge_text' => 'LAUNCH OFFER',
            'banner_image_url' => 'https://cdn.example.test/campaign.webp',
            'cta_label' => 'Explore course',
            'terms' => 'Offer applies to new enrollments only.',
            'priority' => 25,
            'discount_type' => 'percent',
            'discount_value' => 20,
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'is_published' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $sale = DB::table('flash_sales')->where('title', 'Premium Launch Week')->first();
        $this->assertNotNull($sale);
        $this->assertSame('A focused offer for ambitious learners', $sale->subtitle);
        $this->assertSame('LAUNCH OFFER', $sale->badge_text);
        $this->assertSame('https://cdn.example.test/campaign.webp', $sale->banner_image_url);
        $this->assertEquals(25, $sale->priority);

        if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'flash_sale.created',
                'entity_type' => 'flash_sale',
                'entity_id' => (string) $sale->id,
            ]);
        }
    }

}
