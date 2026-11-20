<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
use Tests\TestCase;

class ReferralIncomePluginTest extends TestCase
{
    public function test_student_referral_attribution_and_settled_purchase_commission_are_idempotent(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'fees-management', 'commerce-cart']);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->get('/admin/referral-income')->assertOk()->assertSee('Referral Income');

        $studentReferrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $code = $service->createOrGetCode($studentReferrer);

        $this->assertSame($code, $service->createOrGetCode($studentReferrer));
        $this->get('/r/' . $code)->assertRedirect('/register');
        $this->actingAs($buyer)->get('/my-referrals')->assertOk()->assertSee('My Referral Income');
        $this->assertDatabaseHas('referral_attributions', [
            'referrer_user_id' => $studentReferrer->id,
            'referred_user_id' => $buyer->id,
            'status' => 'active',
        ]);

        $orderId = $this->createPaidCommerceOrder($buyer, 10000, 'BDT');
        $first = $service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 10000, 'BDT');
        $retry = $service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 10000, 'BDT');

        $this->assertSame($first, $retry);
        $this->assertDatabaseCount('referral_attributions', 1);
        $this->assertDatabaseHas('referral_commissions', [
            'referrer_user_id' => $studentReferrer->id,
            'referred_user_id' => $buyer->id,
            'source_type' => 'commerce_order',
            'source_id' => (string) $orderId,
            'commission_amount' => 500,
            'commission_percent' => 5,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('referral_commissions', 1);
    }

    public function test_teacher_referral_uses_teacher_rate_and_rejects_self_or_second_attribution(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'fees-management', 'commerce-cart']);

        $teacher = $this->createUserWithRole('teacher');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $code = $service->createOrGetCode($teacher);

        $this->actingAs($teacher)->post('/my-referrals/apply', ['referral_code' => $code])
            ->assertSessionHasErrors('referral_code');

        $this->actingAs($buyer)->post('/my-referrals/apply', ['referral_code' => $code])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($buyer)->post('/my-referrals/apply', ['referral_code' => $code])
            ->assertSessionHasErrors('referral_code');

        $orderId = $this->createPaidCommerceOrder($buyer, 2000, 'BDT');
        $service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 2000, 'BDT');
        $this->assertDatabaseHas('referral_commissions', [
            'referrer_user_id' => $teacher->id,
            'commission_amount' => 200,
            'commission_percent' => 10,
        ]);
    }

    public function test_verified_manual_invoice_payment_completes_order_and_records_teacher_commission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'universal-payment', 'fees-management', 'payment-management']);

        $teacher = $this->createUserWithRole('teacher');
        $buyer = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $referrals = app(ReferralIncomeService::class);
        $referrals->applyCode($buyer, $referrals->createOrGetCode($teacher));

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REF-UPAY-' . $buyer->id,
            'payer_name' => $buyer->name,
            'payer_email' => $buyer->email,
            'payer_phone' => '01712345678',
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'net_amount' => 5000,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        if (!Schema::hasTable('commerce_orders')) {
            Schema::create('commerce_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('order_number', 32)->unique();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('currency', 8)->default('BDT');
                $table->decimal('subtotal', 10, 2);
                $table->decimal('discount_total', 10, 2)->default(0);
                $table->decimal('grand_total', 10, 2);
                $table->string('status', 24)->default('awaiting_payment');
                $table->string('payment_status', 24)->default('unpaid');
                $table->json('billing_snapshot')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'REF-ORDER-' . $buyer->id,
            'user_id' => $buyer->id,
            'invoice_id' => $invoice->id,
            'branch_id' => null,
            'currency' => 'BDT',
            'subtotal' => 5000,
            'discount_total' => 0,
            'grand_total' => 5000,
            'status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'billing_snapshot' => json_encode(['name' => $buyer->name]),
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'REF-UPAY-' . $buyer->id,
            'payer_name' => $buyer->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Unified cart order',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 5000,
            'external_trx_id' => 'BANK-REF-' . $buyer->id,
            'status' => 'pending',
            'user_id' => $buyer->id,
            'fee_invoice_id' => $invoice->id,
        ]);

        $this->actingAs($admin)->post('/plugins/universal-payment/transactions/' . $transaction->id . '/status', [
            'status' => 'verified',
            'payment_reference' => 'BANK-VERIFIED-' . $buyer->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('commerce_orders', [
            'id' => $orderId,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('referral_commissions', [
            'referrer_user_id' => $teacher->id,
            'referred_user_id' => $buyer->id,
            'source_type' => 'commerce_order',
            'source_id' => (string) $orderId,
            'commission_amount' => 500,
            'status' => 'pending',
        ]);
    }

    public function test_online_payment_settlement_records_referral_commission_after_full_invoice_payment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'payment-management', 'fees-management']);

        $student = $this->createUserWithRole('student');
        $referrer = $this->createUserWithRole('student');
        $referrals = app(ReferralIncomeService::class);
        $referrals->applyCode($student, $referrals->createOrGetCode($referrer));

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REF-ONLINE-' . $student->id,
            'payer_name' => $student->name,
            'payer_email' => $student->email,
            'payer_phone' => '01712345678',
            'subtotal_amount' => 10000,
            'discount_amount' => 0,
            'net_amount' => 10000,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);
        if (!Schema::hasTable('commerce_orders')) {
            Schema::create('commerce_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('order_number', 32)->unique();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('currency', 8)->default('BDT');
                $table->decimal('subtotal', 10, 2);
                $table->decimal('discount_total', 10, 2)->default(0);
                $table->decimal('grand_total', 10, 2);
                $table->string('status', 24)->default('awaiting_payment');
                $table->string('payment_status', 24)->default('unpaid');
                $table->json('billing_snapshot')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => 'REF-ORDER-ONLINE-' . $student->id,
            'user_id' => $student->id, 'invoice_id' => $invoice->id, 'branch_id' => null,
            'currency' => 'BDT', 'subtotal' => 10000, 'discount_total' => 0, 'grand_total' => 10000,
            'status' => 'awaiting_payment', 'payment_status' => 'pending',
            'billing_snapshot' => json_encode(['name' => $student->name]), 'paid_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'REF-PAY-' . $student->id,
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'gateway-stripe',
            'amount' => 10000,
            'currency' => 'BDT',
            'status' => 'initiated',
        ]);

        app(PaymentSettlementService::class)->settleTransaction($transaction, 'STRIPE-PAID-' . $student->id);

        $this->assertDatabaseHas('commerce_orders', [
            'id' => $orderId, 'status' => 'completed', 'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('referral_commissions', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $student->id,
            'source_type' => 'commerce_order',
            'source_id' => (string) $orderId,
            'commission_amount' => 500,
            'status' => 'pending',
        ]);
    }

    public function test_approved_commission_can_be_requested_and_paid_once_with_encrypted_payout_destination(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'fees-management', 'commerce-cart']);

        $teacher = $this->createUserWithRole('teacher');
        $buyer = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $service = app(ReferralIncomeService::class);
        $code = $service->createOrGetCode($teacher);
        $service->applyCode($buyer, $code);
        $orderId = $this->createPaidCommerceOrder($buyer, 5000, 'BDT');
        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 5000, 'BDT');

        $this->actingAs($admin)->post('/admin/referral-income/commissions/' . $commissionId . '/review', [
            'status' => 'approved',
        ])->assertRedirect();

        $this->actingAs($teacher)->post('/my-referrals/payout', [
            'method' => 'bkash',
            'account_details' => '01712345678',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $payout = DB::table('referral_payout_requests')->where('user_id', $teacher->id)->first();
        $this->assertNotNull($payout);
        $this->assertNotSame('01712345678', $payout->account_details_encrypted);

        $this->actingAs($admin)->post('/admin/referral-income/payouts/' . $payout->id . '/review', [
            'status' => 'paid',
            'admin_note' => 'Premature payout must be rejected.',
        ])->assertStatus(422);
        $this->assertDatabaseHas('referral_payout_requests', ['id' => $payout->id, 'status' => 'pending']);

        $this->actingAs($admin)->post('/admin/referral-income/payouts/' . $payout->id . '/review', [
            'status' => 'approved',
            'admin_note' => 'Verified',
        ])->assertRedirect();
        $this->actingAs($admin)->post('/admin/referral-income/payouts/' . $payout->id . '/review', [
            'status' => 'paid',
            'admin_note' => 'Bank transfer completed',
        ])->assertRedirect();

        $this->assertDatabaseHas('referral_payout_requests', ['id' => $payout->id, 'status' => 'paid']);
        $this->assertDatabaseHas('referral_commissions', ['id' => $commissionId, 'status' => 'paid']);
    }
    public function test_foreign_currency_commissions_are_not_mixed_into_local_bdt_payouts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'fees-management', 'commerce-cart']);

        $teacher = $this->createUserWithRole('teacher');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $service->applyCode($buyer, $service->createOrGetCode($teacher));
        $orderId = $this->createPaidCommerceOrder($buyer, 100000, 'USD');
        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', $orderId, 100000, 'USD');

        DB::table('referral_commissions')->where('id', $commissionId)->update(['status' => 'approved']);

        $this->actingAs($teacher)->post('/my-referrals/payout', [
            'method' => 'bkash',
            'account_details' => '01712345678',
        ])->assertSessionHasErrors('payout');

        $this->assertDatabaseCount('referral_payout_requests', 0);
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'currency' => 'USD',
            'status' => 'approved',
            'payout_request_id' => null,
        ]);
        $this->actingAs($teacher)->get('/my-referrals')->assertOk()->assertSee('USD');
    }

    public function test_branch_manager_cannot_view_global_referral_finance_even_with_manage_permission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'branch-management']);
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'REF-MGR-' . uniqid(),
            'name' => 'Referral Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', (int) $branchId);
        $role = Role::query()->where('slug', 'branch_manager')->firstOrFail();
        $permission = Permission::query()->where('slug', 'referral_income.manage')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($manager)->get('/admin/referral-income')->assertForbidden();
    }

    private function createPaidCommerceOrder(object $buyer, float $amount, string $currency): int
    {
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REF-PLUGIN-' . strtoupper(bin2hex(random_bytes(5))),
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
            'order_number' => 'REF-PLUGIN-ORDER-' . strtoupper(bin2hex(random_bytes(4))),
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


}
