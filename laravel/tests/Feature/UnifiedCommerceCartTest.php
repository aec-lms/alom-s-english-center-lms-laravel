<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Tests\TestCase;

final class UnifiedCommerceCartTest extends TestCase
{
    public function test_student_can_add_subscription_and_live_class_pass_to_one_cart_using_server_prices(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart', 'course-management']);
        $student = $this->createUserWithRole('student');

        $subscriptionId = DB::table('commerce_sellables')->insertGetId([
            'type' => 'subscription', 'title' => 'Premium Monthly', 'sku' => 'PREMIUM-30',
            'description' => 'Monthly access', 'price' => 499, 'currency' => 'BDT',
            'entitlement' => json_encode(['type' => 'subscription', 'duration_days' => 30]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'CART-LIVE-01', 'title' => 'Live Pass Test Course',
            'slug' => 'live-pass-test-course', 'price' => 0, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $liveClassId = DB::table('live_classes')->insertGetId([
            'course_id' => $courseId, 'title' => 'Live Speaking Workshop',
            'meeting_url' => 'https://example.test/live', 'starts_at' => now()->subMinutes(5),
            'status' => 'live', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $livePassId = DB::table('commerce_sellables')->insertGetId([
            'type' => 'live_class', 'title' => 'Live Speaking Workshop', 'sku' => 'LIVE-01',
            'description' => 'Speaking workshop access', 'price' => 250, 'currency' => 'BDT', 'resource_id' => $liveClassId,
            'entitlement' => json_encode(['type' => 'live_class']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'subscription', 'item_id' => $subscriptionId, 'quantity' => 1, 'unit_price' => 1,
        ])->assertRedirect('/cart');

        // Adding the same access item twice must not turn it into quantity two.
        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'subscription', 'item_id' => $subscriptionId, 'quantity' => 1,
        ])->assertRedirect('/cart');

        $subscriptionCartItem = DB::table('commerce_cart_items')
            ->where('item_type', 'subscription')->where('item_id', $subscriptionId)->first();
        $this->assertNotNull($subscriptionCartItem);
        $this->assertSame(1, (int) $subscriptionCartItem->quantity);

        // Direct quantity edits must not bypass the single-access-item rule.
        $this->actingAs($student)->patch('/cart/items/' . $subscriptionCartItem->id, [
            'quantity' => 2,
        ])->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('commerce_cart_items', [
            'id' => $subscriptionCartItem->id, 'quantity' => 1,
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'live_class', 'item_id' => $livePassId, 'quantity' => 1,
        ])->assertRedirect('/cart');

        $cart = DB::table('commerce_carts')->where('user_id', $student->id)->where('status', 'active')->first();
        $this->assertNotNull($cart);
        $this->assertDatabaseCount('commerce_cart_items', 2);
        $this->assertDatabaseHas('commerce_cart_items', [
            'cart_id' => $cart->id, 'item_type' => 'subscription', 'item_id' => $subscriptionId, 'unit_price' => 499,
        ]);
        $this->assertDatabaseHas('commerce_cart_items', [
            'cart_id' => $cart->id, 'item_type' => 'live_class', 'item_id' => $livePassId, 'unit_price' => 250,
        ]);
        $this->actingAs($student)->get('/cart')->assertOk()->assertSee('Premium Monthly')->assertSee('Live Speaking Workshop');
    }

    public function test_inactive_service_cannot_be_added_to_cart(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart']);
        $student = $this->createUserWithRole('student');
        $id = DB::table('commerce_sellables')->insertGetId([
            'type' => 'subscription', 'title' => 'Disabled Plan', 'price' => 199, 'currency' => 'BDT',
            'entitlement' => '{}', 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'subscription', 'item_id' => $id,
        ])->assertSessionHasErrors('item');

        $this->assertDatabaseCount('commerce_cart_items', 0);
    }

    public function test_cart_is_private_to_each_authenticated_student(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart']);
        $first = $this->createUserWithRole('student');
        $second = $this->createUserWithRole('student');
        $id = DB::table('commerce_sellables')->insertGetId([
            'type' => 'other', 'title' => 'Grammar Workshop', 'price' => 150, 'currency' => 'BDT',
            'entitlement' => '{}', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($first)->post('/cart/items', ['item_type' => 'other', 'item_id' => $id])->assertRedirect('/cart');
        $this->actingAs($second)->get('/cart')->assertOk()->assertDontSee('Grammar Workshop');
    }
    public function test_student_cannot_add_a_course_owned_by_another_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'commerce-cart']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'CART-A-' . uniqid(), 'name' => 'Cart Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'CART-B-' . uniqid(), 'name' => 'Cart Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', (int) $branchA);
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'CART-CROSS-' . strtoupper(uniqid()),
            'title' => 'Other Branch Paid Course',
            'slug' => 'other-branch-paid-course-' . uniqid(),
            'price' => 350,
            'status' => 'published',
            'branch_id' => $branchB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'course',
            'item_id' => $courseId,
            'quantity' => 1,
        ])->assertSessionHasErrors('item');

        $this->assertDatabaseCount('commerce_cart_items', 0);
    }

    public function test_student_profile_branch_is_used_when_account_branch_is_empty(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-management', 'commerce-cart']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'PROFILE-A-' . uniqid(), 'name' => 'Profile Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'PROFILE-B-' . uniqid(), 'name' => 'Profile Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student');
        DB::table('students')->insert([
            'user_id' => $student->id,
            'branch_id' => $branchA,
            'student_code' => 'PROFILE-' . strtoupper(uniqid()),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'PROFILE-CROSS-' . strtoupper(uniqid()),
            'title' => 'Profile Branch Protected Course',
            'slug' => 'profile-branch-protected-' . uniqid(),
            'price' => 450,
            'status' => 'published',
            'branch_id' => $branchB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'course', 'item_id' => $courseId, 'quantity' => 1,
        ])->assertSessionHasErrors('item');

        $this->assertDatabaseCount('commerce_cart_items', 0);
    }

    public function test_late_callback_cannot_settle_a_failed_payment_transaction(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management']);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'FAILED-CALLBACK-' . strtoupper(uniqid()),
            'student_id' => null,
            'payer_name' => 'Failed Payment Student',
            'payer_email' => 'failed-callback@example.test',
            'payer_phone' => '01700000000',
            'branch_id' => null,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'net_amount' => 500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'PAY-FAILED-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'gateway_slug' => 'gateway-sslcommerz',
            'amount' => 500,
            'currency' => 'BDT',
            'status' => 'failed',
            'metadata' => [],
        ]);

        try {
            app(PaymentSettlementService::class)->settleTransaction($transaction, 'BANK-LATE-FAILED-001');
            $this->fail('A failed payment transaction must not be resurrected by a late callback.');
        } catch (\\RuntimeException $exception) {
            $this->assertSame('Only initiated payment transactions can be settled.', $exception->getMessage());
        }

        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id, 'status' => 'failed']);
        $this->assertDatabaseHas('fee_invoices', ['id' => $invoice->id, 'paid_amount' => 0, 'status' => 'unpaid']);
    }

    public function test_payment_settlement_rejects_student_branch_change_after_checkout(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'fees-management', 'payment-management', 'commerce-cart']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'SETTLE-A-' . uniqid(), 'name' => 'Settlement Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'SETTLE-B-' . uniqid(), 'name' => 'Settlement Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', (int) $branchA);
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'SETTLE-BRANCH-' . strtoupper(uniqid()),
            'title' => 'Settlement Branch Course',
            'slug' => 'settlement-branch-course-' . uniqid(),
            'price' => 900,
            'status' => 'published',
            'branch_id' => $branchA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'SETTLE-BRANCH-' . strtoupper(uniqid()),
            'student_id' => null,
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'payer_phone' => '01700000000',
            'branch_id' => $branchA,
            'subtotal_amount' => 900,
            'discount_amount' => 0,
            'net_amount' => 900,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'AEC-SETTLE-' . strtoupper(uniqid()),
            'user_id' => $student->id,
            'invoice_id' => $invoice->id,
            'branch_id' => $branchA,
            'currency' => 'BDT',
            'subtotal' => 900,
            'discount_total' => 0,
            'grand_total' => 900,
            'status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'billing_snapshot' => json_encode(['name' => $student->name, 'email' => $student->email]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commerce_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'course',
            'item_id' => $courseId,
            'title_snapshot' => 'Settlement Branch Course',
            'quantity' => 1,
            'unit_price' => 900,
            'line_total' => 900,
            'metadata' => json_encode(['course_id' => $courseId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'branch_id' => $branchA,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'PAY-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'branch_id' => $branchA,
            'gateway_slug' => 'gateway-sslcommerz',
            'amount' => 900,
            'currency' => 'BDT',
            'status' => 'initiated',
            'metadata' => [],
        ]);

        // Simulate an administrator reassigning the student after checkout.
        DB::table('users')->where('id', $student->id)->update(['branch_id' => $branchB]);

        try {
            app(PaymentSettlementService::class)->settleTransaction($transaction, 'BANK-SETTLE-BRANCH-001');
            $this->fail('Settlement must reject a student whose assigned branch changed after checkout.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Student branch assignment changed after checkout; manual payment reconciliation is required.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id, 'status' => 'initiated']);
        $this->assertDatabaseHas('fee_invoices', ['id' => $invoice->id, 'paid_amount' => 0, 'status' => 'unpaid']);
        $this->assertDatabaseHas('commerce_orders', ['id' => $orderId, 'status' => 'awaiting_payment', 'payment_status' => 'unpaid']);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $courseId, 'status' => 'pending']);
        $this->assertDatabaseCount('commerce_entitlements', 0);
    }

    public function test_branch_manager_cannot_access_global_commerce_order_administration(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'commerce-cart']);
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'CART-MGR-' . uniqid(), 'name' => 'Cart Manager Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', (int) $branchId);
        $role = Role::query()->where('slug', 'branch_manager')->firstOrFail();
        $permission = Permission::query()->where('slug', 'commerce.orders.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($manager)->get('/admin/commerce/orders')->assertForbidden();
    }

}
