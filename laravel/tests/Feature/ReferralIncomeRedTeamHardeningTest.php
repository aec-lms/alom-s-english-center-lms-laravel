<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Plugins\Modules\CourseManagement\Services\CourseCommercePaymentSettlementService;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;

final class ReferralIncomeRedTeamHardeningTest extends TestCase
{
    private function setupReferralAdmin(): object
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'student-management', 'payment-management', 'fees-management', 'commerce-cart']);
        $admin = $this->createUserWithRole('admin');

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'referral_income.manage')->value('id');
        $this->assertNotNull($roleId);
        $this->assertNotNull($permissionId);
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);

        return $admin;
    }

    public function test_manual_reversal_is_rejected_and_commission_review_and_rule_changes_are_audited(): void
    {
        $admin = $this->setupReferralAdmin();
        $this->actingAs($admin)->get('/admin/referral-income')
            ->assertOk()
            ->assertDontSee('value="reversed"')
            ->assertSee('Reason (required for rejection)');

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));
        $orderId = $this->createPaidCommerceOrder($buyer, 1000, 'BDT');
        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 1000, 'BDT');

        $this->actingAs($admin)
            ->from('/admin/referral-income')
            ->post('/admin/referral-income/commissions/' . $commissionId . '/review', [
                'status' => 'reversed',
            ])
            ->assertRedirect('/admin/referral-income')
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('referral_commission_reversals', 0);

        $this->actingAs($admin)
            ->from('/admin/referral-income')
            ->post('/admin/referral-income/commissions/' . $commissionId . '/review', [
                'status' => 'rejected',
            ])
            ->assertRedirect('/admin/referral-income')
            ->assertSessionHasErrors('admin_note');

        $this->assertDatabaseHas('referral_commissions', ['id' => $commissionId, 'status' => 'pending']);

        $this->actingAs($admin)
            ->from('/admin/referral-income')
            ->post('/admin/referral-income/commissions/' . $commissionId . '/review', [
                'status' => 'rejected',
                'admin_note' => 'Referral evidence did not meet policy.',
            ])
            ->assertRedirect('/admin/referral-income');

        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
        ]);

        $commissionLog = DB::table('audit_logs')
            ->where('action', 'referral_income.commission_reviewed')
            ->where('entity_id', (string) $commissionId)
            ->latest('id')
            ->first();
        $this->assertNotNull($commissionLog);
        $this->assertSame($admin->id, (int) $commissionLog->user_id);
        $this->assertSame('Referral evidence did not meet policy.', json_decode($commissionLog->metadata, true)['reason'] ?? null);

        $this->actingAs($admin)->put('/admin/referral-income/rules', [
            'student_commission_percent' => 6.5,
            'teacher_commission_percent' => 11,
            'student_minimum_payout' => 600,
            'teacher_minimum_payout' => 700,
        ])->assertRedirect();

        $this->assertDatabaseHas('referral_income_rules', [
            'referrer_type' => 'student',
            'commission_percent' => 6.5,
            'minimum_payout' => 600,
        ]);

        $ruleLog = DB::table('audit_logs')
            ->where('action', 'referral_income.rules_updated')
            ->where('entity_type', 'referral_income_rule')
            ->where('entity_id', 'student')
            ->latest('id')
            ->first();
        $this->assertNotNull($ruleLog);
        $this->assertSame($admin->id, (int) $ruleLog->user_id);
        $metadata = json_decode($ruleLog->metadata, true);
        $this->assertEquals(5.0, (float) ($metadata['before']['commission_percent'] ?? 0));
        $this->assertEquals(6.5, (float) ($metadata['after']['commission_percent'] ?? 0));
    }

    public function test_payout_and_recovery_review_transitions_write_actor_attributed_audit_events(): void
    {
        $admin = $this->setupReferralAdmin();
        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));
        $orderId = $this->createPaidCommerceOrder($buyer, 1000, 'BDT');
        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 1000, 'BDT');

        $payoutId = DB::table('referral_payout_requests')->insertGetId([
            'user_id' => $referrer->id,
            'owner_type' => 'student',
            'amount' => 50,
            'currency' => 'BDT',
            'method' => 'bkash',
            'account_details_encrypted' => Crypt::encryptString('01700000000'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('referral_commissions')->where('id', $commissionId)->update([
            'status' => 'payout_requested',
            'payout_request_id' => $payoutId,
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/referral-income/payouts/' . $payoutId . '/review', [
            'status' => 'approved',
            'admin_note' => 'Approved after finance verification.',
        ])->assertRedirect();
        $this->actingAs($admin)->post('/admin/referral-income/payouts/' . $payoutId . '/review', [
            'status' => 'paid',
        ])->assertRedirect()->assertSessionHasErrors('admin_note');
        $this->assertDatabaseHas('referral_payout_requests', ['id' => $payoutId, 'status' => 'approved']);

        $this->actingAs($admin)->post('/admin/referral-income/payouts/' . $payoutId . '/review', [
            'status' => 'paid',
            'admin_note' => 'Bank transfer reference PAY-REDTEAM-001.',
        ])->assertRedirect();

        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'status' => 'paid',
            'payout_request_id' => $payoutId,
        ]);
        $payoutLog = DB::table('audit_logs')
            ->where('action', 'referral_income.payout_reviewed')
            ->where('entity_id', (string) $payoutId)
            ->latest('id')
            ->first();
        $this->assertNotNull($payoutLog);
        $this->assertSame($admin->id, (int) $payoutLog->user_id);
        $this->assertSame('paid', json_decode($payoutLog->metadata, true)['to'] ?? null);
        $this->assertSame('Bank transfer reference PAY-REDTEAM-001.', json_decode($payoutLog->metadata, true)['reason'] ?? null);

        $transactionId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'REDTEAM-RECOVERY-TX-' . strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => null,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 1000,
            'currency' => 'BDT',
            'status' => 'settled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $refundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $transactionId,
            'amount' => 1000,
            'reason' => 'Red-team recovery test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reversalId = DB::table('referral_commission_reversals')->insertGetId([
            'referral_commission_id' => $commissionId,
            'payment_refund_id' => $refundId,
            'refunded_amount' => 1000,
            'commission_reversal_amount' => 50,
            'currency' => 'BDT',
            'status' => 'recovery_due',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/referral-income/recoveries/' . $reversalId . '/review', [
            'status' => 'waived',
        ])->assertRedirect()->assertSessionHasErrors('admin_note');
        $this->assertDatabaseHas('referral_commission_reversals', [
            'id' => $reversalId,
            'status' => 'recovery_due',
        ]);

        $this->actingAs($admin)->post('/admin/referral-income/recoveries/' . $reversalId . '/review', [
            'status' => 'waived',
            'admin_note' => 'Finance approved waiver after documented reconciliation.',
        ])->assertRedirect();

        $this->assertDatabaseHas('referral_commission_reversals', [
            'id' => $reversalId,
            'status' => 'waived',
            'reviewed_by' => $admin->id,
        ]);
        $recoveryLog = DB::table('audit_logs')
            ->where('action', 'referral_income.recovery_reviewed')
            ->where('entity_id', (string) $reversalId)
            ->latest('id')
            ->first();
        $this->assertNotNull($recoveryLog);
        $this->assertSame($admin->id, (int) $recoveryLog->user_id);
        $this->assertSame('Finance approved waiver after documented reconciliation.', json_decode($recoveryLog->metadata, true)['reason'] ?? null);
    }
    public function test_attribution_is_rejected_after_a_paid_course_cart_order(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'student-management', 'course-management']);

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $buyer->id,
            'status' => 'completed',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_cart_orders')->insert([
            'cart_id' => $cartId,
            'user_id' => $buyer->id,
            'branch_id' => null,
            'invoice_id' => null,
            'payment_transaction_id' => null,
            'coupon_id' => null,
            'coupon_code' => null,
            'coupon_reservation_key' => null,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ReferralIncomeService::class);
        try {
            $service->applyCode($buyer, $service->createOrGetCode($referrer));
            $this->fail('A paid course-cart order must make the buyer ineligible for a new referral attribution.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('before the first paid order', $exception->errors()['referral_code'][0] ?? '');
        }

        $this->assertDatabaseCount('referral_attributions', 0);
    }


    private function createPaidCommerceOrder(object $buyer, float $amount, string $currency): int
    {
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REF-REDTEAM-' . strtoupper(bin2hex(random_bytes(5))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'payer_phone' => '01700000000',
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'net_amount' => $amount,
            'paid_amount' => $amount,
            'currency' => strtoupper($currency),
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);

        return (int) DB::table('commerce_orders')->insertGetId([
            'order_number' => 'REF-REDTEAM-' . strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => strtoupper($currency),
            'subtotal' => $amount,
            'discount_total' => 0,
            'grand_total' => $amount,
            'status' => 'completed',
            'payment_status' => 'paid',
            'billing_snapshot' => json_encode(['name' => $buyer->name, 'email' => $buyer->email]),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }


    public function test_commission_recording_rejects_unknown_sources_wrong_buyers_and_untrusted_amounts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'student-management', 'fees-management', 'commerce-cart']);

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $otherBuyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));
        $service->applyCode($otherBuyer, $service->createOrGetCode($referrer));
        $orderId = $this->createPaidCommerceOrder($buyer, 1000, 'BDT');

        $this->assertNull($service->recordSettledPurchase($buyer, 'arbitrary_order', $orderId, 1000, 'BDT'));
        $this->assertNull($service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 999, 'BDT'));
        $this->assertNull($service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 1000, 'USD'));
        $this->assertNull($service->recordSettledPurchase($otherBuyer, 'commerce_order', $orderId, 1000, 'BDT'));
        $this->assertDatabaseCount('referral_commissions', 0);

        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 1000, 'BDT');
        $this->assertNotNull($commissionId);
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'source_type' => 'commerce_order',
            'source_id' => (string) $orderId,
            'eligible_amount' => 1000,
            'currency' => 'BDT',
        ]);
    }


    public function test_course_cart_order_commission_uses_paid_invoice_and_order_snapshot(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'student-management', 'course-management', 'fees-management', 'universal-payment']);

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REF-COURSE-CART-' . strtoupper(bin2hex(random_bytes(4))),
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'payer_phone' => '01700000000',
            'subtotal_amount' => 1200,
            'discount_amount' => 200,
            'net_amount' => 1000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
            'due_date' => now()->toDateString(),
        ]);
        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $buyer->id,
            'status' => 'completed',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('course_cart_orders')->insertGetId([
            'cart_id' => $cartId,
            'user_id' => $buyer->id,
            'branch_id' => null,
            'invoice_id' => $invoice->id,
            'payment_transaction_id' => null,
            'coupon_id' => null,
            'coupon_code' => null,
            'coupon_reservation_key' => null,
            'subtotal_amount' => 1200,
            'discount_amount' => 200,
            'net_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'paid',
            'paid_at' => now(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payment = UniversalPaymentTransaction::query()->create([
            'reference' => 'REF-COURSE-CART-' . strtoupper(bin2hex(random_bytes(4))),
            'payer_name' => $buyer->name,
            'payer_contact' => '01700000000',
            'item_title' => 'Course Cart Order #' . $orderId,
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => 1000,
            'external_trx_id' => 'REF-COURSE-CART-VERIFIED-' . $orderId,
            'status' => 'pending',
            'notes' => json_encode([
                'purpose' => 'course_cart',
                'course_cart_order_id' => $orderId,
            ], JSON_THROW_ON_ERROR),
            'user_id' => $buyer->id,
            'fee_invoice_id' => $invoice->id,
            'verified_at' => now(),
        ]);
        DB::table('course_cart_orders')->where('id', $orderId)->update([
            'payment_transaction_id' => $payment->id,
            'updated_at' => now(),
        ]);

        $this->assertNull(
            $service->recordSettledPurchase($buyer, 'course_cart_order', $orderId, 1000, 'BDT'),
            'A paid-looking order must not earn commission while its linked provider payment is still pending.'
        );

        DB::table('universal_payment_transactions')->where('id', $payment->id)->update([
            'status' => 'verified',
            'verified_at' => now(),
            'updated_at' => now(),
        ]);

        $commissionId = $service->recordSettledPurchase($buyer, 'course_cart_order', $orderId, 1000, 'BDT');
        $this->assertNotNull($commissionId);
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'source_type' => 'course_cart_order',
            'source_id' => (string) $orderId,
            'eligible_amount' => 1000,
            'commission_amount' => 50,
            'currency' => 'BDT',
        ]);
        $this->assertNull($service->recordSettledPurchase($buyer, 'course_cart_order', $orderId, 999, 'BDT'));

        $admin = $this->createUserWithRole('admin');
        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'referral_income.manage')->value('id');
        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);

        $this->actingAs($admin)->get('/admin/referral-income')
            ->assertOk()
            ->assertSee('Approval held until verified Universal Payment refund support is available.')
            ->assertDontSee('value="approved"');

        $this->actingAs($admin)->post('/admin/referral-income/commissions/' . $commissionId . '/review', [
            'status' => 'approved',
        ])->assertStatus(409);
        $this->assertDatabaseHas('referral_commissions', ['id' => $commissionId, 'status' => 'pending']);

        $payoutId = DB::table('referral_payout_requests')->insertGetId([
            'user_id' => $referrer->id,
            'owner_type' => 'student',
            'amount' => 50,
            'currency' => 'BDT',
            'method' => 'bkash',
            'account_details_encrypted' => Crypt::encryptString('01700000000'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('referral_commissions')->where('id', $commissionId)->update([
            'status' => 'payout_requested',
            'payout_request_id' => $payoutId,
            'updated_at' => now(),
        ]);
        $this->actingAs($admin)->post('/admin/referral-income/payouts/' . $payoutId . '/review', [
            'status' => 'approved',
            'admin_note' => 'Attempted payout before refund adapter.',
        ])->assertStatus(422);
        $this->assertDatabaseHas('referral_payout_requests', ['id' => $payoutId, 'status' => 'pending']);
    }


    public function test_attribution_is_rejected_after_a_paid_commerce_order(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'student-management', 'fees-management', 'commerce-cart']);

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $this->createPaidCommerceOrder($buyer, 1000, 'BDT');
        $service = app(ReferralIncomeService::class);

        try {
            $service->applyCode($buyer, $service->createOrGetCode($referrer));
            $this->fail('A paid commerce order must make the buyer ineligible for referral attribution.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('before the first paid order', $exception->errors()['referral_code'][0] ?? '');
        }

        $this->assertDatabaseCount('referral_attributions', 0);
    }


    public function test_course_cart_partial_and_full_refunds_reverse_commission_idempotently(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'referral-income',
            'student-management',
            'course-management',
            'fees-management',
            'marketing-management',
            'universal-payment',
            'payment-management',
        ]);

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));

        $courseId = DB::table('courses')->insertGetId([
            'code' => 'REF-COURSE-REFUND-001',
            'title' => 'Referral Refund Course',
            'slug' => 'referral-refund-course',
            'price' => 1000,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cartId = DB::table('course_carts')->insertGetId([
            'user_id' => $buyer->id,
            'status' => 'checkout_pending',
            'currency' => 'BDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('course_cart_orders')->insertGetId([
            'cart_id' => $cartId,
            'user_id' => $buyer->id,
            'branch_id' => null,
            'invoice_id' => null,
            'payment_transaction_id' => null,
            'coupon_id' => null,
            'coupon_code' => null,
            'coupon_reservation_key' => null,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'net_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'awaiting_payment',
            'paid_at' => null,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_cart_order_items')->insert([
            'order_id' => $orderId,
            'course_id' => $courseId,
            'course_title' => 'Referral Refund Course',
            'unit_price' => 1000,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'REF-COURSE-TX-' . strtoupper(bin2hex(random_bytes(5))),
            'payer_name' => $buyer->name,
            'payer_contact' => '01700000000',
            'item_title' => 'Referral Refund Course',
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => 1000,
            'external_trx_id' => null,
            'status' => 'pending',
            'notes' => json_encode(['purpose' => 'course_cart', 'course_cart_order_id' => $orderId], JSON_THROW_ON_ERROR),
            'user_id' => $buyer->id,
            'branch_id' => null,
        ]);
        DB::table('course_cart_orders')->where('id', $orderId)->update(['payment_transaction_id' => $transaction->id]);

        $settled = app(CourseCommercePaymentSettlementService::class)->settle(
            $transaction,
            'BANK-REF-COURSE-001',
            ['validation_status' => 'VALID']
        );
        $order = DB::table('course_cart_orders')->where('id', $orderId)->first();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->invoice_id);
        $this->assertSame('verified', $settled->fresh()->status);

        $commissionId = DB::table('referral_commissions')
            ->where('source_type', 'course_cart_order')
            ->where('source_id', (string) $orderId)
            ->value('id');
        $this->assertNotNull($commissionId);
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'eligible_amount' => 1000,
            'commission_amount' => 50,
            'status' => 'pending',
        ]);

        $refundOneTransaction = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'REF-COURSE-RFD-001-' . strtoupper(bin2hex(random_bytes(3))),
            'invoice_id' => $order->invoice_id,
            'branch_id' => null,
            'gateway_slug' => 'gateway-sslcommerz',
            'amount' => 200,
            'currency' => 'BDT',
            'status' => 'refunded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $refundOneId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $refundOneTransaction,
            'amount' => 200,
            'reason' => 'Provider-confirmed partial course-cart refund.',
            'provider_refund_reference' => 'PROVIDER-REF-COURSE-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A missing Universal Payment invoice binding must fail closed before
        // any referral reversal ledger row or commission mutation is written.
        DB::table('universal_payment_transactions')->where('id', $transaction->id)
            ->update(['fee_invoice_id' => null]);
        try {
            $service->reverseSettledPurchase($buyer, 'course_cart_order', $orderId, $refundOneId, 200, 'BDT');
            $this->fail('A course-cart refund must reject a missing original-payment invoice binding.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('verified Universal Payment transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount('referral_commission_reversals', 0);
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'commission_amount' => 50,
            'status' => 'pending',
        ]);

        DB::table('universal_payment_transactions')->where('id', $transaction->id)
            ->update(['fee_invoice_id' => $order->invoice_id]);

        $firstReversal = $service->reverseSettledPurchase($buyer, 'course_cart_order', $orderId, $refundOneId, 200, 'BDT');
        $firstRetry = $service->reverseSettledPurchase($buyer, 'course_cart_order', $orderId, $refundOneId, 200, 'BDT');
        $this->assertSame((int) $firstReversal, (int) $firstRetry);
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'commission_amount' => 40,
            'status' => 'pending',
        ]);

        $duplicateRefundTransaction = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'REF-COURSE-RFD-DUP-' . strtoupper(bin2hex(random_bytes(3))),
            'invoice_id' => $order->invoice_id,
            'branch_id' => null,
            'gateway_slug' => 'gateway-sslcommerz',
            'amount' => 200,
            'currency' => 'BDT',
            'status' => 'refunded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $duplicateRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $duplicateRefundTransaction,
            'amount' => 200,
            'reason' => 'Duplicate provider reference regression.',
            'provider_refund_reference' => 'PROVIDER-REF-COURSE-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            $service->reverseSettledPurchase($buyer, 'course_cart_order', $orderId, $duplicateRefundId, 200, 'BDT');
            $this->fail('A provider refund reference must not be applied to a second refund row.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('already been applied', $exception->getMessage());
        }
        $this->assertDatabaseCount('referral_commission_reversals', 1);

        $refundTwoTransaction = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'REF-COURSE-RFD-002-' . strtoupper(bin2hex(random_bytes(3))),
            'invoice_id' => $order->invoice_id,
            'branch_id' => null,
            'gateway_slug' => 'gateway-sslcommerz',
            'amount' => 800,
            'currency' => 'BDT',
            'status' => 'refunded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $refundTwoId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $refundTwoTransaction,
            'amount' => 800,
            'reason' => 'Provider-confirmed final course-cart refund.',
            'provider_refund_reference' => 'PROVIDER-REF-COURSE-002',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service->reverseSettledPurchase($buyer, 'course_cart_order', $orderId, $refundTwoId, 800, 'BDT');
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'commission_amount' => 0,
            'status' => 'reversed',
        ]);
        $this->assertDatabaseCount('referral_commission_reversals', 2);
        $this->assertSame(50.0, (float) DB::table('referral_commission_reversals')
            ->where('referral_commission_id', $commissionId)
            ->sum('commission_reversal_amount'));
    }


}
