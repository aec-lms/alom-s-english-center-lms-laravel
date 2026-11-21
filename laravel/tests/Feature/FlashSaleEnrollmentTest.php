<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugins\Modules\CommerceCart\Services\CommerceCatalog;
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

    public function test_missing_storefront_settings_keep_admin_publish_switch_off(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $admin = $this->createUserWithRole('admin');
        $response = $this->actingAs($admin)->get('/admin/flash-sales')
            ->assertOk()
            ->assertSee('Hidden');

        $this->assertSame(
            0,
            preg_match('/<input[^>]*\\bid="page_enabled"[^>]*\\bchecked\\b/i', $response->getContent()),
            'The storefront publish switch must default to off when no settings row exists.'
        );
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

    public function test_branch_specific_campaign_precedes_global_offer_even_with_lower_priority(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'FLASH-PRIORITY-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Flash Priority Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $course = Course::query()->create([
            'branch_id' => $branchId,
            'code' => 'FLASH-BRANCH-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Branch Campaign Course',
            'slug' => 'branch-campaign-' . bin2hex(random_bytes(3)),
            'price' => 1000,
            'status' => 'published',
        ]);

        foreach ([
            ['title' => 'Global premium priority', 'branch_id' => null, 'priority' => 900, 'discount_value' => 40],
            ['title' => 'Branch-specific offer', 'branch_id' => $branchId, 'priority' => 1, 'discount_value' => 15],
        ] as $campaign) {
            DB::table('flash_sales')->insert($campaign + [
                'course_id' => $course->id,
                'discount_type' => 'percent',
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDay(),
                'is_published' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $quote = app(\Plugins\Modules\FlashSale\Services\FlashSalePricingService::class)
            ->quote((int) $course->id, (int) $branchId);

        $this->assertSame('Branch-specific offer', $quote['sale']->title);
        $this->assertEquals(150.00, $quote['discount']);
        $this->assertEquals(850.00, $quote['total']);
    }

    public function test_public_storefront_uses_the_same_winning_offer_as_the_quote_resolver(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $course = Course::query()->create([
            'code' => 'FLASH-PUBLIC-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Public Flash Sale Course',
            'slug' => 'public-flash-' . bin2hex(random_bytes(3)),
            'price' => 2000,
            'status' => 'published',
        ]);

        foreach ([
            ['title' => 'Lower Priority Public Offer', 'discount_value' => 20, 'priority' => 1],
            ['title' => 'Winning Public Offer', 'discount_value' => 10, 'priority' => 50],
        ] as $campaign) {
            DB::table('flash_sales')->insert($campaign + [
                'course_id' => $course->id,
                'discount_type' => 'percent',
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
        $this->assertSame('Winning Public Offer', $quote['sale']->title);
        $this->assertEquals(1800.00, $quote['total']);

        DB::table('flash_sale_settings')->updateOrInsert(
            ['id' => 1],
            ['page_enabled' => true, 'page_title' => 'Flash Sale', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->get('/flash-sale')
            ->assertOk()
            ->assertSee('Flash Sale')
            ->assertSee('Winning Public Offer')
            ->assertDontSee('Lower Priority Public Offer')
            ->assertSee('Public Flash Sale Course')
            ->assertSee('1,800.00');
    }

    public function test_public_storefront_requires_explicit_enabled_page_setting(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        DB::table('flash_sale_settings')->delete();

        $this->get('/flash-sale')->assertNotFound();

        DB::table('flash_sale_settings')->insert([
            'page_enabled' => false,
            'page_title' => 'Flash Sale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/flash-sale')->assertNotFound();
    }

    public function test_pricing_resolver_rejects_campaign_branch_that_conflicts_with_course_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $makeBranch = static fn (string $suffix): int => DB::table('branches')->insertGetId([
            'code' => 'FLASH-QUOTE-' . $suffix . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Flash Quote ' . $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courseBranchId = $makeBranch('COURSE');
        $campaignBranchId = $makeBranch('CAMPAIGN');
        $course = Course::query()->create([
            'branch_id' => $courseBranchId,
            'code' => 'FLASH-QUOTE-MISMATCH-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Branch Mismatch Quote Course',
            'slug' => 'branch-mismatch-quote-' . bin2hex(random_bytes(3)),
            'price' => 700,
            'status' => 'published',
        ]);
        DB::table('flash_sales')->insert([
            'course_id' => $course->id,
            'branch_id' => $campaignBranchId,
            'title' => 'Incompatible Branch Offer',
            'discount_type' => 'percent',
            'discount_value' => 50,
            'priority' => 100,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'is_published' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quote = app(\Plugins\Modules\FlashSale\Services\FlashSalePricingService::class)
            ->quote((int) $course->id, (int) $campaignBranchId);

        $this->assertNull($quote['sale']);
        $this->assertEquals(700.00, $quote['total']);
    }

    public function test_branch_manager_cannot_mutate_campaign_assigned_to_another_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'flash-sale']);

        $makeBranch = static fn (string $suffix): int => DB::table('branches')->insertGetId([
            'code' => 'FLASH-AUTH-' . $suffix . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Flash Auth ' . $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $managerBranchId = $makeBranch('MANAGER');
        $campaignBranchId = $makeBranch('CAMPAIGN');
        $manager = $this->createUserWithRole('branch_manager', $managerBranchId);
        $course = Course::query()->create([
            'branch_id' => $managerBranchId,
            'code' => 'FLASH-AUTH-COURSE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'title' => 'Manager Branch Course',
            'slug' => 'manager-branch-course-' . bin2hex(random_bytes(3)),
            'price' => 900,
            'status' => 'published',
        ]);
        $saleId = DB::table('flash_sales')->insertGetId([
            'course_id' => $course->id,
            'branch_id' => $campaignBranchId,
            'title' => 'Foreign Branch Campaign',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'is_published' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)->post('/admin/flash-sales/' . $saleId . '/toggle')
            ->assertForbidden();

        $this->assertDatabaseHas('flash_sales', ['id' => $saleId, 'is_active' => true]);
    }

    public function test_student_branch_profile_conflict_excludes_all_branch_targeted_storefront_offers(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'flash-sale']);

        $makeBranch = static fn (string $suffix): int => DB::table('branches')->insertGetId([
            'code' => 'FLASH-VIEW-' . $suffix . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Flash View ' . $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $accountBranchId = $makeBranch('ACCOUNT');
        $profileBranchId = $makeBranch('PROFILE');
        $studentUser = $this->createUserWithRole('student', $accountBranchId);
        Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $profileBranchId,
            'student_code' => 'FLASH-VIEW-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'admission_date' => today(),
            'status' => 'active',
        ]);

        $insertOffer = function (string $suffix, ?int $branchId): void {
            $course = Course::query()->create([
                'branch_id' => $branchId,
                'code' => 'FLASH-VIEW-COURSE-' . $suffix . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                'title' => 'Flash View Course ' . $suffix,
                'slug' => 'flash-view-course-' . strtolower($suffix) . '-' . bin2hex(random_bytes(3)),
                'price' => 1000,
                'status' => 'published',
            ]);
            DB::table('flash_sales')->insert([
                'course_id' => $course->id,
                'branch_id' => $branchId,
                'title' => 'Visible ' . $suffix . ' Offer',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDay(),
                'is_published' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        $insertOffer('ACCOUNT', $accountBranchId);
        $insertOffer('PROFILE', $profileBranchId);
        $insertOffer('GLOBAL', null);

        DB::table('flash_sale_settings')->updateOrInsert(
            ['id' => 1],
            ['page_enabled' => true, 'page_title' => 'Flash Sale', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->actingAs($studentUser)->get('/flash-sale')
            ->assertOk()
            ->assertSee('Visible GLOBAL Offer')
            ->assertDontSee('Visible ACCOUNT Offer')
            ->assertDontSee('Visible PROFILE Offer');
    }

    public function test_premium_migration_retry_and_rollback_preserve_preexisting_branch_data(): void
    {
        $this->markSystemInstalled();
        $schema = \Illuminate\Support\Facades\Schema::class;
        $schema::dropIfExists('flash_sales');

        try {
            $schema::create('flash_sales', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('course_id');
                $table->string('title');
                $table->string('discount_type');
                $table->decimal('discount_value', 12, 2);
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->boolean('is_published')->default(false);
                $table->boolean('is_active')->default(false);
                // Simulate a branch_id and index that predate this migration.
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->index(
                    ['branch_id', 'is_published', 'is_active', 'starts_at', 'ends_at'],
                    'flash_sales_branch_schedule'
                );
                $table->timestamps();
            });

            $saleId = DB::table('flash_sales')->insertGetId([
                'course_id' => 9001,
                'branch_id' => 777,
                'title' => 'Pre-existing branch sentinel',
                'discount_type' => 'fixed',
                'discount_value' => 50,
                'starts_at' => now(),
                'ends_at' => now()->addDay(),
                'is_published' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration = require base_path('plugins/modules/flash-sale/Database/Migrations/2026_11_19_000001_add_premium_flash_sale_campaign_fields.php');
            $migration->up();
            $migration->up();
            $migration->down();

            $this->assertTrue($schema::hasColumn('flash_sales', 'branch_id'));
            $this->assertTrue($schema::hasIndex('flash_sales', 'flash_sales_branch_schedule'));
            $this->assertTrue($schema::hasIndex('flash_sales', 'flash_sales_course_priority'));
            $this->assertDatabaseHas('flash_sales', [
                'id' => $saleId,
                'branch_id' => 777,
                'title' => 'Pre-existing branch sentinel',
            ]);
        } finally {
            $schema::dropIfExists('flash_sales');
        }
    }


    public function test_unified_commerce_uses_branch_specific_flash_sale_quote_over_higher_priority_global_offer(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'commerce-cart', 'flash-sale']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'UNIFIED-FLASH-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Unified Flash Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentUser = $this->createUserWithRole('student', $branchId);
        Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
            'student_code' => 'UNIFIED-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'admission_date' => today(),
            'status' => 'active',
        ]);
        $course = Course::query()->create([
            'code' => 'UNIFIED-FLASH-01',
            'title' => 'Unified Flash Course',
            'slug' => 'unified-flash-course',
            'price' => 1000,
            'status' => 'published',
        ]);

        DB::table('flash_sales')->insert([
            [
                'course_id' => $course->id,
                'title' => 'High Priority Global Offer',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'priority' => 999,
                'branch_id' => null,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDay(),
                'is_published' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'course_id' => $course->id,
                'title' => 'Branch Launch Offer',
                'discount_type' => 'percent',
                'discount_value' => 30,
                'priority' => 1,
                'branch_id' => $branchId,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDay(),
                'is_published' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $quote = app(CommerceCatalog::class)->resolve('course', (int) $course->id, $studentUser);

        $this->assertEquals(700.00, (float) $quote['price']);
        $this->assertSame('Branch Launch Offer', $quote['metadata']['flash_sale']['campaign_title']);
        $this->assertEquals(300.00, (float) $quote['metadata']['flash_sale']['discount_amount']);
    }

    public function test_legacy_course_cart_snapshots_and_revalidates_the_same_flash_sale_price(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'fees-management', 'course-management', 'flash-sale']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'LEGACY-FLASH-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Legacy Flash Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentUser = $this->createUserWithRole('student', $branchId);
        Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
            'student_code' => 'LEGACY-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'admission_date' => today(),
            'status' => 'active',
        ]);
        $course = Course::query()->create([
            'code' => 'LEGACY-FLASH-01',
            'title' => 'Legacy Cart Flash Course',
            'slug' => 'legacy-cart-flash-course',
            'price' => 1000,
            'status' => 'published',
        ]);
        $saleId = DB::table('flash_sales')->insertGetId([
            'course_id' => $course->id,
            'title' => 'Cart Snapshot Offer',
            'discount_type' => 'percent',
            'discount_value' => 25,
            'priority' => 10,
            'branch_id' => $branchId,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'is_published' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($studentUser)
            ->post(route('course-commerce.cart.add', $course->id))
            ->assertRedirect(route('course-commerce.cart'));

        $cartItem = DB::table('course_cart_items')->first();
        $this->assertNotNull($cartItem);
        $this->assertEquals(750.00, (float) $cartItem->unit_price);

        DB::table('flash_sales')->where('id', $saleId)->update([
            'discount_value' => 40,
            'updated_at' => now(),
        ]);

        $this->actingAs($studentUser)
            ->post(route('course-commerce.checkout'), ['payer_contact' => '01712345678'])
            ->assertStatus(409);

        $this->assertDatabaseMissing('course_cart_orders', ['user_id' => $studentUser->id]);
    }

    public function test_conflicting_account_and_student_profile_branches_fail_closed_for_commerce_catalog(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'commerce-cart', 'flash-sale']);

        $accountBranchId = DB::table('branches')->insertGetId([
            'code' => 'ACCOUNT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Account Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profileBranchId = DB::table('branches')->insertGetId([
            'code' => 'PROFILE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Profile Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentUser = $this->createUserWithRole('student', $accountBranchId);
        Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $profileBranchId,
            'student_code' => 'CONFLICT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'admission_date' => today(),
            'status' => 'active',
        ]);
        $course = Course::query()->create([
            'code' => 'CONFLICT-FLASH-01',
            'title' => 'Conflict Branch Course',
            'slug' => 'conflict-branch-course',
            'price' => 1000,
            'status' => 'published',
        ]);

        $this->expectException(ValidationException::class);
        app(CommerceCatalog::class)->resolve('course', (int) $course->id, $studentUser);
    }

    public function test_direct_enrollment_rejects_conflicting_account_and_student_profile_branches(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'fees-management', 'course-management', 'flash-sale']);

        $accountBranchId = DB::table('branches')->insertGetId([
            'code' => 'ENROLL-ACCOUNT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Enrollment Account Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profileBranchId = DB::table('branches')->insertGetId([
            'code' => 'ENROLL-PROFILE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Enrollment Profile Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentUser = $this->createUserWithRole('student', $accountBranchId);
        Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $profileBranchId,
            'student_code' => 'ENROLL-CONFLICT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'admission_date' => today(),
            'status' => 'active',
        ]);
        $course = Course::query()->create([
            'code' => 'ENROLL-CONFLICT-01',
            'title' => 'Enrollment Conflict Course',
            'slug' => 'enrollment-conflict-course',
            'price' => 1000,
            'status' => 'published',
        ]);

        $this->actingAs($studentUser)
            ->post('/courses/' . $course->id . '/enroll')
            ->assertForbidden();

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $studentUser->id,
            'course_id' => $course->id,
        ]);
    }

}
