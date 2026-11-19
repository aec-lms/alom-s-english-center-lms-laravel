<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Plugins\Modules\CommerceCart\Services\CommerceCatalog;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\BranchManagement\Models\Branch;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\MarketingManagement\Models\PromoCoupon;
use Plugins\Modules\UniversalPayment\Services\UniversalPaymentOrchestrator;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Tests\TestCase;

final class UnifiedCommerceCartTest extends TestCase
{
    public function test_recipient_specific_gift_drafts_can_be_added_to_the_unified_cart(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart', 'course-management']);
        $student = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'UNIFIED-GIFT-CATALOG-01',
            'title' => 'Giftable IELTS Course',
            'slug' => 'giftable-ielts-course',
            'price' => 1200,
            'status' => 'published',
            'branch_id' => null,
        ]);

        $giftId = DB::table('course_gifts')->insertGetId([
            'sender_user_id' => $student->id,
            'recipient_user_id' => null,
            'recipient_email' => 'course.recipient@example.test',
            'course_id' => $course->id,
            'invoice_id' => null,
            'redeem_token_hash' => hash('sha256', 'course-gift-token'),
            'redeem_token_encrypted' => encrypt('course-gift-token'),
            'status' => 'awaiting_payment',
            'currency' => 'BDT',
            'amount' => 1200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $voucherId = DB::table('course_gift_vouchers')->insertGetId([
            'sender_user_id' => $student->id,
            'recipient_email' => 'voucher.recipient@example.test',
            'redeem_token_hash' => hash('sha256', 'voucher-gift-token'),
            'redeem_token_encrypted' => encrypt('voucher-gift-token'),
            'currency' => 'BDT',
            'amount' => 1500,
            'remaining_amount' => 1500,
            'status' => 'awaiting_payment',
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'gift_course', 'item_id' => $giftId, 'quantity' => 1,
        ])->assertRedirect('/cart');
        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'gift_voucher', 'item_id' => $voucherId, 'quantity' => 1,
        ])->assertRedirect('/cart');

        $this->assertDatabaseHas('commerce_cart_items', [
            'item_type' => 'gift_course', 'item_id' => $giftId, 'quantity' => 1, 'unit_price' => 1200,
        ]);
        $this->assertDatabaseHas('commerce_cart_items', [
            'item_type' => 'gift_voucher', 'item_id' => $voucherId, 'quantity' => 1, 'unit_price' => 1500,
        ]);
        $this->assertDatabaseCount('commerce_cart_items', 2);
        $this->assertDatabaseHas('course_gifts', ['id' => $giftId, 'status' => 'awaiting_payment', 'invoice_id' => null]);
        $this->assertDatabaseHas('course_gift_vouchers', ['id' => $voucherId, 'status' => 'awaiting_payment', 'invoice_id' => null]);
    }

    public function test_student_can_create_course_gift_and_voucher_drafts_directly_in_unified_cart(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart', 'course-management', 'fees-management', 'universal-payment', 'payment-management']);
        $student = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'UNIFIED-GIFT-DRAFT-01',
            'title' => 'Course Gift Draft Course',
            'slug' => 'course-gift-draft-course',
            'price' => 1800,
            'status' => 'published',
            'branch_id' => null,
        ]);

        $this->actingAs($student)->post('/cart/gifts/course', [
            'course_id' => $course->id,
            'recipient_email' => 'gift.draft.recipient@example.test',
            'recipient_name' => 'Gift Recipient',
            'message' => 'Enjoy learning!',
        ])->assertRedirect('/cart');

        $gift = DB::table('course_gifts')->where('sender_user_id', $student->id)->first();
        $this->assertNotNull($gift);
        $this->assertSame('awaiting_payment', $gift->status);
        $this->assertSame('gift_course', DB::table('commerce_cart_items')->where('item_id', $gift->id)->value('item_type'));
        $this->assertSame('gift.draft.recipient@example.test', $gift->recipient_email);

        $this->actingAs($student)->post('/cart/gifts/voucher', [
            'recipient_email' => 'voucher.draft.recipient@example.test',
            'recipient_name' => 'Voucher Recipient',
            'amount' => 2500,
            'message' => 'For your next course!',
        ])->assertRedirect('/cart');

        $voucher = DB::table('course_gift_vouchers')->where('sender_user_id', $student->id)->first();
        $this->assertNotNull($voucher);
        $this->assertSame('awaiting_payment', $voucher->status);
        $this->assertSame(2500.0, (float) $voucher->amount);
        $this->assertSame('gift_voucher', DB::table('commerce_cart_items')->where('item_id', $voucher->id)->value('item_type'));
        $this->assertDatabaseCount('commerce_cart_items', 2);

        $payments = \Mockery::mock(UniversalPaymentOrchestrator::class);
        $payments->shouldReceive('assertGatewayReady')->once()->with('gateway-sslcommerz');
        $payments->shouldReceive('initiate')->once()->andReturn(['redirect_url' => 'https://gateway.example.test/gift-checkout']);
        $this->app->instance(UniversalPaymentOrchestrator::class, $payments);

        $this->actingAs($student)->post('/cart/checkout', [
            'gateway' => 'gateway-sslcommerz',
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'payer_phone' => '01700000000',
        ])->assertRedirect('https://gateway.example.test/gift-checkout');

        $order = DB::table('commerce_orders')->where('user_id', $student->id)->first();
        $this->assertNotNull($order);
        $courseGiftOrderItem = DB::table('commerce_order_items')->where('order_id', $order->id)->where('item_type', 'gift_course')->first();
        $voucherOrderItem = DB::table('commerce_order_items')->where('order_id', $order->id)->where('item_type', 'gift_voucher')->first();
        $this->assertNotNull($courseGiftOrderItem);
        $this->assertNotNull($voucherOrderItem);
        $this->assertDatabaseHas('course_gifts', [
            'id' => $gift->id,
            'commerce_order_item_id' => $courseGiftOrderItem->id,
            'status' => 'awaiting_payment',
            'invoice_id' => null,
        ]);
        $this->assertDatabaseHas('course_gift_vouchers', [
            'id' => $voucher->id,
            'commerce_order_item_id' => $voucherOrderItem->id,
            'status' => 'awaiting_payment',
            'invoice_id' => null,
        ]);
    }

    public function test_course_gift_draft_endpoint_rejects_courses_from_another_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart', 'course-management', 'branch-management']);
        $student = $this->createUserWithRole('student');
        $senderBranch = Branch::query()->create([
            'code' => 'GIFT-SENDER-' . $student->id,
            'name' => 'Gift Sender Branch',
            'is_active' => true,
        ]);
        $otherBranch = Branch::query()->create([
            'code' => 'GIFT-OTHER-' . $student->id,
            'name' => 'Other Gift Branch',
            'is_active' => true,
        ]);

        if (Schema::hasColumn('users', 'branch_id')) {
            $student->forceFill(['branch_id' => $senderBranch->id])->save();
        }

        $course = Course::query()->create([
            'code' => 'CROSS-BRANCH-GIFT-' . $student->id,
            'title' => 'Other Branch Gift Course',
            'slug' => 'other-branch-gift-course-' . $student->id,
            'price' => 1800,
            'status' => 'published',
            'branch_id' => $otherBranch->id,
        ]);

        $this->actingAs($student)->post('/cart/gifts/course', [
            'course_id' => $course->id,
            'recipient_email' => 'cross.branch.recipient@example.test',
        ])->assertSessionHasErrors('course_id');

        $this->assertDatabaseMissing('course_gifts', [
            'sender_user_id' => $student->id,
            'course_id' => $course->id,
        ]);
        $this->assertDatabaseMissing('commerce_cart_items', ['item_type' => 'gift_course']);
    }

    public function test_checkout_rejects_catalogue_price_currency_or_title_changes_after_add_to_cart(): void
    {
        $catalog = app(CommerceCatalog::class);
        $cartItem = (object) [
            'unit_price' => 499,
            'currency' => 'BDT',
            'title_snapshot' => 'Premium Monthly',
        ];

        $catalog->assertCartSnapshotUnchanged([
            'price' => 499, 'currency' => 'BDT', 'title' => 'Premium Monthly',
        ], $cartItem);

        foreach ([
            ['price' => 599, 'currency' => 'BDT', 'title' => 'Premium Monthly'],
            ['price' => 499, 'currency' => 'USD', 'title' => 'Premium Monthly'],
            ['price' => 499, 'currency' => 'BDT', 'title' => 'Premium Annual'],
        ] as $changedSnapshot) {
            try {
                $catalog->assertCartSnapshotUnchanged($changedSnapshot, $cartItem);
                $this->fail('Checkout must reject a changed cart snapshot.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('cart', $exception->errors());
            }
        }
    }

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
        $this->actingAs($student)->get('/cart')->assertOk()->assertSee('Premium Monthly')->assertSee('Live Speaking Workshop')->assertSee('Stripe (Card)');
    }

    public function test_readding_existing_sellable_refreshes_its_currency_snapshot(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart']);
        $student = $this->createUserWithRole('student');
        $sellableId = DB::table('commerce_sellables')->insertGetId([
            'type' => 'other',
            'title' => 'Currency Refresh Workshop',
            'sku' => 'CURRENCY-REFRESH-' . strtoupper(uniqid()),
            'price' => 250,
            'currency' => 'BDT',
            'entitlement' => '{}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = ['item_type' => 'other', 'item_id' => $sellableId, 'quantity' => 1];
        $this->actingAs($student)->post('/cart/items', $payload)->assertRedirect('/cart');
        $this->assertDatabaseHas('commerce_cart_items', [
            'item_type' => 'other',
            'item_id' => $sellableId,
            'currency' => 'BDT',
        ]);

        DB::table('commerce_sellables')->where('id', $sellableId)->update([
            'price' => 275,
            'currency' => 'USD',
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', $payload)->assertRedirect('/cart');

        $this->assertDatabaseHas('commerce_cart_items', [
            'item_type' => 'other',
            'item_id' => $sellableId,
            'quantity' => 2,
            'unit_price' => 275,
            'currency' => 'USD',
        ]);
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
        } catch (\RuntimeException $exception) {
            $this->assertSame('Only initiated payment transactions can be settled.', $exception->getMessage());
        }

        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id, 'status' => 'failed']);
        $this->assertDatabaseHas('fee_invoices', ['id' => $invoice->id, 'paid_amount' => 0, 'status' => 'unpaid']);
    }

    public function test_payment_settlement_rejects_branch_changed_for_invoice_enrollment_without_commerce_order(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'fees-management', 'payment-management']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'INVOICE-A-' . uniqid(), 'name' => 'Invoice Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'INVOICE-B-' . uniqid(), 'name' => 'Invoice Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', (int) $branchA);
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'INVOICE-BRANCH-' . strtoupper(uniqid()),
            'title' => 'Invoice Branch Course',
            'slug' => 'invoice-branch-course-' . uniqid(),
            'price' => 650,
            'status' => 'published',
            'branch_id' => $branchA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INVOICE-BRANCH-' . strtoupper(uniqid()),
            'student_id' => null,
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'payer_phone' => '01700000000',
            'branch_id' => $branchA,
            'subtotal_amount' => 650,
            'discount_amount' => 0,
            'net_amount' => 650,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
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
            'transaction_ref' => 'PAY-INVOICE-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'branch_id' => $branchA,
            'gateway_slug' => 'gateway-sslcommerz',
            'amount' => 650,
            'currency' => 'BDT',
            'status' => 'initiated',
            'metadata' => [],
        ]);

        DB::table('users')->where('id', $student->id)->update(['branch_id' => $branchB]);

        try {
            app(PaymentSettlementService::class)->settleTransaction($transaction, 'BANK-INVOICE-BRANCH-001');
            $this->fail('Invoice settlement must reject an enrollment after its student changes branch.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Student branch assignment changed after invoice creation; manual payment reconciliation is required.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id, 'status' => 'initiated']);
        $this->assertDatabaseHas('fee_invoices', ['id' => $invoice->id, 'paid_amount' => 0, 'status' => 'unpaid']);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $courseId, 'status' => 'pending']);
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
            'branch_id' => null,
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
        $this->assertDatabaseCount('commerce_entitlements', 0);
    }

    public function test_partial_refund_suspends_commerce_entitlements_and_repayment_reactivates_them(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart']);

        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REFUND-COMMERCE-' . strtoupper(uniqid()),
            'student_id' => null,
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'payer_phone' => '01700000000',
            'branch_id' => null,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'AEC-REFUND-' . strtoupper(uniqid()),
            'user_id' => $student->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 1000,
            'discount_total' => 0,
            'grand_total' => 1000,
            'status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderItemId = DB::table('commerce_order_items')->insertGetId([
            'order_id' => $orderId,
            'item_type' => 'subscription',
            'item_id' => 42,
            'title_snapshot' => 'Premium Subscription',
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'metadata' => json_encode(['entitlement' => ['duration_days' => 30]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commerce_entitlements')->insert([
            'user_id' => $student->id,
            'order_item_id' => $orderItemId,
            'entitlement_type' => 'subscription',
            'resource_id' => null,
            'details' => json_encode(['duration_days' => 30]),
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'REFUND-TXN-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 1000,
            'currency' => 'BDT',
            'status' => 'settled',
            'settled_at' => now(),
            'metadata' => [],
        ]);

        $this->actingAs($admin)->post('/admin/payments/' . $transaction->id . '/refund', [
            'amount' => 250,
            'reason' => 'Partial commerce refund',
        ])->assertRedirect();

        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 750,
            'status' => 'partial',
        ]);
        $this->assertDatabaseHas('commerce_orders', [
            'id' => $orderId,
            'status' => 'partially_refunded',
            'payment_status' => 'partial',
        ]);
        $this->assertDatabaseHas('commerce_entitlements', [
            'user_id' => $student->id,
            'order_item_id' => $orderItemId,
            'status' => 'suspended',
        ]);

        // A later verified payment of the outstanding balance must restore the
        // same entitlement instead of leaving a stale suspended row.
        $this->actingAs($admin)->post('/admin/payments/offline', [
            'invoice_id' => $invoice->id,
            'amount' => 250,
            'receipt_note' => 'Remaining balance after refund',
        ])->assertRedirect();

        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 1000,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('commerce_orders', [
            'id' => $orderId,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('commerce_entitlements', [
            'user_id' => $student->id,
            'order_item_id' => $orderItemId,
            'status' => 'active',
        ]);
    }

    public function test_full_invoice_refund_revokes_order_entitlements_and_marks_commerce_order_refunded(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'commerce-cart']);

        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'COMMERCE-REFUND-' . strtoupper(uniqid()),
            'student_id' => null,
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'payer_phone' => '01700000000',
            'branch_id' => null,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'net_amount' => 500,
            'paid_amount' => 500,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'AEC-REFUND-' . strtoupper(uniqid()),
            'user_id' => $student->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 500,
            'discount_total' => 0,
            'grand_total' => 500,
            'status' => 'completed',
            'payment_status' => 'paid',
            'billing_snapshot' => json_encode(['name' => $student->name, 'email' => $student->email]),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderItemId = DB::table('commerce_order_items')->insertGetId([
            'order_id' => $orderId,
            'item_type' => 'subscription',
            'item_id' => 71,
            'title_snapshot' => 'Premium Subscription',
            'quantity' => 1,
            'unit_price' => 500,
            'line_total' => 500,
            'metadata' => json_encode(['entitlement' => ['duration_days' => 30]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commerce_entitlements')->insert([
            'user_id' => $student->id,
            'order_item_id' => $orderItemId,
            'entitlement_type' => 'subscription',
            'resource_id' => 71,
            'details' => json_encode(['duration_days' => 30]),
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'COMMERCE-REFUND-TX-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 500,
            'currency' => 'BDT',
            'status' => 'settled',
            'metadata' => [],
            'settled_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/payments/' . $transaction->id . '/refund', [
            'amount' => 500,
            'reason' => 'Full commerce order refund',
        ])->assertRedirect();

        $this->assertDatabaseHas('commerce_orders', [
            'id' => $orderId,
            'status' => 'refunded',
            'payment_status' => 'refunded',
        ]);
        $this->assertDatabaseHas('commerce_entitlements', [
            'order_item_id' => $orderItemId,
            'status' => 'suspended',
        ]);
        $this->assertDatabaseHas('fee_invoices', [
            'id' => $invoice->id,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
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

    public function test_unified_cart_applies_course_coupon_to_eligible_items_and_redeems_it_on_settlement(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'commerce-cart', 'course-management', 'marketing-management',
            'fees-management', 'universal-payment', 'payment-management',
        ]);

        $student = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'COUPON-CART-' . strtoupper(uniqid()),
            'title' => 'Coupon Eligible Course',
            'slug' => 'coupon-eligible-course-' . uniqid(),
            'price' => 1000, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $subscriptionId = DB::table('commerce_sellables')->insertGetId([
            'type' => 'subscription', 'title' => 'Monthly Subscription',
            'sku' => 'COUPON-SUB-' . strtoupper(uniqid()),
            'description' => 'A separate non-course cart item', 'price' => 200, 'currency' => 'BDT',
            'entitlement' => json_encode(['type' => 'subscription', 'duration_days' => 30]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'course', 'item_id' => $courseId, 'quantity' => 1,
        ])->assertRedirect('/cart');
        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'subscription', 'item_id' => $subscriptionId, 'quantity' => 1,
        ])->assertRedirect('/cart');
        $this->actingAs($student)->get('/cart')->assertOk()->assertSee('Course coupon code');

        $coupon = PromoCoupon::query()->create([
            'code' => 'MIXED' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)),
            'discount_percent' => 20, 'max_uses' => 10, 'used_count' => 0,
            'expires_at' => now()->addDays(2)->toDateString(), 'is_active' => true,
            'discount_type' => 'percent', 'discount_amount' => null, 'minimum_subtotal' => 0,
            'maximum_discount' => null, 'per_user_limit' => null, 'starts_at' => null,
            'applies_to_course_ids' => [], 'first_purchase_only' => false, 'gift_only' => false,
        ]);

        $payments = \Mockery::mock(UniversalPaymentOrchestrator::class);
        $payments->shouldReceive('assertGatewayReady')->once()->with('gateway-sslcommerz');
        $payments->shouldReceive('initiate')->once()->andReturn(['redirect_url' => 'https://gateway.example.test/checkout']);
        $this->app->instance(UniversalPaymentOrchestrator::class, $payments);

        $this->actingAs($student)->post('/cart/checkout', [
            'gateway' => 'gateway-sslcommerz', 'payer_name' => 'Coupon Cart Student',
            'payer_email' => 'coupon-cart@example.test', 'payer_phone' => '01700000000',
            'coupon_code' => $coupon->code,
        ])->assertRedirect('https://gateway.example.test/checkout');

        $order = DB::table('commerce_orders')->where('user_id', $student->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('200.00', number_format((float) $order->discount_total, 2, '.', ''));
        $this->assertSame('1000.00', number_format((float) $order->grand_total, 2, '.', ''));
        $this->assertSame($coupon->code, $order->coupon_code);
        $this->assertNotEmpty($order->coupon_reservation_key);

        $invoice = FeeInvoice::query()->findOrFail($order->invoice_id);
        $this->assertSame('1200.00', number_format((float) $invoice->subtotal_amount, 2, '.', ''));
        $this->assertSame('200.00', number_format((float) $invoice->discount_amount, 2, '.', ''));
        $this->assertSame('1000.00', number_format((float) $invoice->net_amount, 2, '.', ''));

        $this->assertDatabaseHas('promo_coupon_redemptions', [
            'reservation_key' => $order->coupon_reservation_key, 'user_id' => $student->id,
            'status' => 'reserved', 'discount_amount' => 200,
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'COUPON-SETTLE-' . strtoupper(uniqid()),
            'invoice_id' => $invoice->id, 'branch_id' => null, 'gateway_slug' => 'gateway-sslcommerz',
            'amount' => 1000, 'currency' => 'BDT', 'status' => 'initiated', 'metadata' => [],
        ]);
        app(PaymentSettlementService::class)->settleTransaction(
            $transaction, 'BANK-UNIFIED-COUPON-' . strtoupper(uniqid())
        );

        $this->assertDatabaseHas('promo_coupon_redemptions', [
            'reservation_key' => $order->coupon_reservation_key, 'status' => 'redeemed',
        ]);
        $this->assertDatabaseHas('promo_coupons', ['id' => $coupon->id, 'used_count' => 1]);
        $this->assertDatabaseHas('commerce_orders', [
            'id' => $order->id, 'payment_status' => 'paid', 'discount_total' => 200, 'grand_total' => 1000,
        ]);
    }

    public function test_unified_cart_supports_manual_bank_cash_payment_and_staff_verification(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['commerce-cart', 'course-management', 'fees-management', 'universal-payment', 'payment-management']);
        app(\App\\Core\\Plugins\\PluginManager::class)->saveSettings('universal-payment', [
            'enabled_gateways' => 'bank_cash',
            'allow_offline_payments' => '1',
            'default_currency' => 'BDT',
        ]);

        $student = $this->createUserWithRole('student');
        $course = Course::query()->create([
            'code' => 'MANUAL-CART-' . strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Manual Checkout Course',
            'slug' => 'manual-checkout-' . strtolower(bin2hex(random_bytes(3))),
            'price' => 900,
            'status' => 'published',
            'branch_id' => null,
        ]);

        $this->actingAs($student)->post('/cart/items', [
            'item_type' => 'course',
            'item_id' => $course->id,
            'quantity' => 1,
        ])->assertRedirect('/cart');

        $this->actingAs($student)->get('/cart')
            ->assertOk()
            ->assertSee('Bank Transfer / Cash (Manual Verification)');

        $this->actingAs($student)->post('/cart/checkout', [
            'gateway' => 'bank_cash',
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'payer_phone' => '01700000000',
            'external_trx_id' => 'AEC-MANUAL-REF-' . strtoupper(bin2hex(random_bytes(3))),
        ])->assertRedirect('/cart')->assertSessionHas('status');

        $order = DB::table('commerce_orders')->where('user_id', $student->id)->first();
        $this->assertNotNull($order);
        $manual = DB::table('universal_payment_transactions')->where('fee_invoice_id', $order->invoice_id)->first();
        $this->assertNotNull($manual);
        $this->assertSame('pending', $manual->status);
        $this->assertSame('bank_cash', $manual->gateway);
        $this->assertSame('pending', $order->payment_status);
        $this->assertDatabaseHas('fee_invoices', ['id' => $order->invoice_id, 'status' => 'unpaid', 'paid_amount' => 0]);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->post('/plugins/universal-payment/transactions/' . $manual->id . '/status', [
            'status' => 'verified',
            'payment_reference' => $manual->external_trx_id,
        ])->assertRedirect();

        $this->assertDatabaseHas('universal_payment_transactions', ['id' => $manual->id, 'status' => 'verified']);
        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'completed', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('fee_invoices', ['id' => $order->invoice_id, 'status' => 'paid', 'paid_amount' => 900]);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);
        $this->assertDatabaseHas('payment_transactions', ['invoice_id' => $order->invoice_id, 'status' => 'settled']);
    }

}
