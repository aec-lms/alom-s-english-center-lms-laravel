<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Models\PluginSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseBundlesPluginTest extends TestCase
{
    public function test_course_bundles_installs_without_course_or_payment_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-bundles']);

        $this->assertTrue(app(PluginManager::class)->isEnabled('course-bundles'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('course-management'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('fees-management'));
        $this->assertTrue(Schema::hasTable('course_bundles'));
        $this->assertTrue(Schema::hasTable('course_bundle_orders'));
        $this->assertTrue(Schema::hasTable('course_bundle_order_courses'));
    }

    public function test_student_can_purchase_free_bundle_and_get_enrolled_in_every_course(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'course-bundles']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $first = $this->createPublishedCourse('Free Grammar');
        $second = $this->createPublishedCourse('Free Writing');
        $bundleId = DB::table('course_bundles')->insertGetId([
            'created_by' => $admin->id,
            'title' => 'Free English Starter',
            'slug' => 'free-english-starter',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->attachCourses($bundleId, [$first, $second]);

        $this->actingAs($student)->post('/course-bundles/free-english-starter/checkout')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = DB::table('course_bundle_orders')->where('user_id', $student->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('completed', $order->status);
        $this->assertDatabaseCount('course_bundle_order_courses', 2);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $first, 'status' => 'active']);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $second, 'status' => 'active']);

        $this->actingAs($student)->post('/course-bundles/free-english-starter/checkout')
            ->assertStatus(409);
        $this->assertDatabaseCount('course_bundle_orders', 1);
    }

    public function test_branch_specific_bundles_are_hidden_from_other_or_unassigned_students(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'course-bundles']);
        $admin = $this->createUserWithRole('admin');
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'BND-A', 'name' => 'Bundle Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'BND-B', 'name' => 'Bundle Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $this->createPublishedCourse('Branch A Grammar');
        $second = $this->createPublishedCourse('Branch A Writing');
        DB::table('courses')->whereIn('id', [$first, $second])->update(['branch_id' => $branchA]);
        $bundleId = DB::table('course_bundles')->insertGetId([
            'branch_id' => $branchA,
            'created_by' => $admin->id,
            'title' => 'Branch A Starter',
            'slug' => 'branch-a-starter',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->attachCourses($bundleId, [$first, $second]);

        $unassigned = $this->createUserWithRole('student');
        $otherBranchStudent = $this->createUserWithRole('student', $branchB);
        $assignedStudent = $this->createUserWithRole('student', $branchA);

        $this->actingAs($unassigned)->get('/course-bundles')->assertOk()->assertDontSee('Branch A Starter');
        $this->actingAs($unassigned)->get('/course-bundles/branch-a-starter')->assertNotFound();
        $this->actingAs($unassigned)->post('/course-bundles/branch-a-starter/checkout')->assertForbidden();
        $this->actingAs($otherBranchStudent)->post('/course-bundles/branch-a-starter/checkout')->assertForbidden();

        $this->actingAs($assignedStudent)->post('/course-bundles/branch-a-starter/checkout')
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('course_bundle_orders', [
            'bundle_id' => $bundleId,
            'user_id' => $assignedStudent->id,
            'branch_id' => $branchA,
            'status' => 'completed',
        ]);
    }

    public function test_paid_bundle_creates_one_invoice_and_pending_enrollments_for_immutable_course_snapshot(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'fees-management', 'course-bundles']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $first = $this->createPublishedCourse('Paid Grammar');
        $second = $this->createPublishedCourse('Paid Speaking');
        $bundleId = DB::table('course_bundles')->insertGetId([
            'created_by' => $admin->id,
            'title' => 'Premium English Bundle',
            'slug' => 'premium-english-bundle',
            'price' => 1500,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->attachCourses($bundleId, [$first, $second]);

        $this->actingAs($student)->post('/course-bundles/premium-english-bundle/checkout')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = DB::table('course_bundle_orders')->where('user_id', $student->id)->first();
        $this->assertNotNull($order->invoice_id);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertDatabaseCount('course_bundle_order_courses', 2);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $first, 'status' => 'pending', 'invoice_id' => $order->invoice_id]);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $second, 'status' => 'pending', 'invoice_id' => $order->invoice_id]);

        // Editing the bundle must not change the purchased course snapshot.
        DB::table('course_bundle_courses')->where('bundle_id', $bundleId)->delete();
        $third = $this->createPublishedCourse('Newly Added Course');
        $this->attachCourses($bundleId, [$first, $third]);
        $this->assertDatabaseHas('course_bundle_order_courses', ['order_id' => $order->id, 'course_id' => $second, 'course_title' => 'Paid Speaking']);
        $this->assertDatabaseMissing('course_bundle_order_courses', ['order_id' => $order->id, 'course_id' => $third]);

        DB::table('fee_invoices')->where('id', $order->invoice_id)->update([
            'status' => 'paid',
            'paid_amount' => 1500,
            'updated_at' => now(),
        ]);
        $this->actingAs($student)->get('/my-bundle-orders')->assertOk()->assertSee('completed');
        $this->assertDatabaseHas('course_bundle_orders', ['id' => $order->id, 'status' => 'completed']);
    }

    public function test_course_bundle_archive_does_not_delete_order_history(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'course-bundles']);
        $admin = $this->createUserWithRole('admin');
        $first = $this->createPublishedCourse('Archive Course A');
        $second = $this->createPublishedCourse('Archive Course B');
        $bundleId = DB::table('course_bundles')->insertGetId([
            'created_by' => $admin->id,
            'title' => 'Historical Bundle',
            'slug' => 'historical-bundle',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->attachCourses($bundleId, [$first, $second]);
        $student = $this->createUserWithRole('student');
        DB::table('course_bundle_orders')->insert([
            'bundle_id' => $bundleId,
            'user_id' => $student->id,
            'bundle_title' => 'Historical Bundle',
            'amount' => 0,
            'currency' => 'BDT',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post("/admin/course-bundles/{$bundleId}/archive")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_bundles', ['id' => $bundleId, 'status' => 'archived']);
        $this->assertDatabaseHas('course_bundle_orders', ['bundle_id' => $bundleId, 'bundle_title' => 'Historical Bundle']);
    }

    public function test_paid_bundle_only_shows_manual_receipt_form_when_bank_cash_is_enabled(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'fees-management', 'universal-payment', 'course-bundles']);

        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $first = $this->createPublishedCourse('Gateway Setting Course A');
        $second = $this->createPublishedCourse('Gateway Setting Course B');
        $bundleId = DB::table('course_bundles')->insertGetId([
            'created_by' => $admin->id,
            'title' => 'Gateway Setting Bundle',
            'slug' => 'gateway-setting-bundle',
            'price' => 1200,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->attachCourses($bundleId, [$first, $second]);

        $this->actingAs($student)->post('/course-bundles/gateway-setting-bundle/checkout')
            ->assertRedirect()->assertSessionHasNoErrors();

        $order = DB::table('course_bundle_orders')->where('user_id', $student->id)->first();
        $this->assertNotNull($order);

        PluginSetting::query()->updateOrCreate(
            ['plugin_slug' => 'universal-payment', 'setting_key' => 'enabled_gateways'],
            ['setting_value' => 'sslcommerz', 'is_encrypted' => false]
        );
        PluginSetting::query()->updateOrCreate(
            ['plugin_slug' => 'universal-payment', 'setting_key' => 'allow_offline_payments'],
            ['setting_value' => '0', 'is_encrypted' => false]
        );

        $url = '/my-bundle-orders/' . $order->id;
        $this->actingAs($student)->get($url)
            ->assertOk()
            ->assertDontSee('Submit payment receipt')
            ->assertSee('Manual bank/cash receipt payments are currently disabled');

        // The public checkout endpoint must enforce gateway settings even when a
        // client bypasses the hidden/disabled receipt form and submits directly.
        $invoice = DB::table('fee_invoices')->where('id', $order->invoice_id)->first();
        $this->actingAs($student)->post('/universal-checkout', [
            'payer_name' => $student->name,
            'payer_contact' => $student->phone ?: $student->email,
            'item_title' => 'Gateway Setting Bundle',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'BANK-CASH-DISABLED-' . $order->id,
            'fee_invoice_id' => $order->invoice_id,
        ])->assertStatus(422);
        $this->assertDatabaseMissing('universal_payment_transactions', [
            'fee_invoice_id' => $order->invoice_id,
            'external_trx_id' => 'BANK-CASH-DISABLED-' . $order->id,
        ]);

        PluginSetting::query()->updateOrCreate(
            ['plugin_slug' => 'universal-payment', 'setting_key' => 'enabled_gateways'],
            ['setting_value' => 'sslcommerz,bank_cash', 'is_encrypted' => false]
        );
        PluginSetting::query()->updateOrCreate(
            ['plugin_slug' => 'universal-payment', 'setting_key' => 'allow_offline_payments'],
            ['setting_value' => '1', 'is_encrypted' => false]
        );

        $this->actingAs($student)->get($url)
            ->assertOk()
            ->assertSee('Submit payment receipt');
    }

    private function createPublishedCourse(string $title): int
    {
        return DB::table('courses')->insertGetId([
            'code' => strtoupper(substr(md5($title), 0, 8)),
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)) . '-' . substr(md5($title), 0, 4),
            'description' => $title,
            'price' => 1000,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachCourses(int $bundleId, array $courseIds): void
    {
        foreach (array_values($courseIds) as $position => $courseId) {
            DB::table('course_bundle_courses')->insert([
                'bundle_id' => $bundleId,
                'course_id' => $courseId,
                'position' => $position + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
