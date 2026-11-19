<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
use Tests\TestCase;

final class ReferralIncomeCommerceSettlementTest extends TestCase
{
    public function test_student_referrer_receives_one_commission_for_a_settled_order(): void
    {
        $this->assertReferralCommissionForRole('student', 10.0);
    }

    public function test_teacher_referrer_receives_one_commission_for_a_settled_order(): void
    {
        $this->assertReferralCommissionForRole('teacher', 15.0);
    }

    private function assertReferralCommissionForRole(string $referrerRole, float $percent): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'student-management', 'teacher-management', 'payment-management']);

        $referrer = $this->createUserWithRole($referrerRole);
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);

        DB::table('referral_income_rules')->where('referrer_type', $referrerRole)->update([
            'commission_percent' => $percent,
            'is_active' => true,
            'updated_at' => now(),
        ]);

        $code = $service->createOrGetCode($referrer);
        $service->applyCode($buyer, $code);

        $first = $service->recordSettledPurchase($buyer, 'commerce_order', 'AEC-ORDER-1001', 1000.00, 'BDT');
        $retry = $service->recordSettledPurchase($buyer, 'commerce_order', 'AEC-ORDER-1001', 1000.00, 'BDT');

        $this->assertNotNull($first);
        $this->assertSame((int) $first, (int) $retry);
        $this->assertDatabaseCount('referral_commissions', 1);
        $this->assertDatabaseHas('referral_commissions', [
            'attribution_id' => DB::table('referral_attributions')->where('referred_user_id', $buyer->id)->value('id'),
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'source_type' => 'commerce_order',
            'source_id' => 'AEC-ORDER-1001',
            'eligible_amount' => 1000.00,
            'commission_percent' => $percent,
            'commission_amount' => round(1000.00 * $percent / 100, 2),
            'currency' => 'BDT',
            'status' => 'pending',
        ]);

        $paymentId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'REFUND-TEST-' . strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => null,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 1000.00,
            'currency' => 'BDT',
            'status' => 'settled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $paymentId,
            'amount' => 500.00,
            'reason' => 'Partial refund test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstReversal = $service->reverseSettledPurchase($buyer, 'commerce_order', 'AEC-ORDER-1001', $firstRefundId, 500.00, 'BDT');
        $retryReversal = $service->reverseSettledPurchase($buyer, 'commerce_order', 'AEC-ORDER-1001', $firstRefundId, 500.00, 'BDT');
        $this->assertSame((int) $firstReversal, (int) $retryReversal);
        $this->assertDatabaseHas('referral_commissions', [
            'source_id' => 'AEC-ORDER-1001',
            'commission_amount' => round(1000.00 * $percent / 200, 2),
            'status' => 'pending',
        ]);

        $secondRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $paymentId,
            'amount' => 500.00,
            'reason' => 'Final refund test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service->reverseSettledPurchase($buyer, 'commerce_order', 'AEC-ORDER-1001', $secondRefundId, 500.00, 'BDT');
        $this->assertDatabaseHas('referral_commissions', [
            'source_id' => 'AEC-ORDER-1001',
            'commission_amount' => 0,
            'status' => 'reversed',
        ]);
        $this->assertDatabaseCount('referral_commission_reversals', 2);

        $paidOrderCommission = $service->recordSettledPurchase($buyer, 'commerce_order', 'AEC-ORDER-PAID-1002', 1000.00, 'BDT');
        DB::table('referral_commissions')->where('id', $paidOrderCommission)->update(['status' => 'paid', 'paid_at' => now()]);
        $paidRefundId = DB::table('payment_refunds')->insertGetId([
            'payment_transaction_id' => $paymentId,
            'amount' => 1000.00,
            'reason' => 'Refund after referral payout',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service->reverseSettledPurchase($buyer, 'commerce_order', 'AEC-ORDER-PAID-1002', $paidRefundId, 1000.00, 'BDT');
        $this->assertDatabaseHas('referral_commissions', [
            'id' => $paidOrderCommission,
            'commission_amount' => round(1000.00 * $percent / 100, 2),
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('referral_commission_reversals', [
            'payment_refund_id' => $paidRefundId,
            'status' => 'recovery_due',
            'commission_reversal_amount' => round(1000.00 * $percent / 100, 2),
        ]);
    }

    public function test_multiple_partial_refunds_fully_reverse_commission_without_rounding_residue(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'student-management', 'payment-management']);

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        DB::table('referral_income_rules')->where('referrer_type', 'student')->update([
            'commission_percent' => 10.01,
            'is_active' => true,
            'updated_at' => now(),
        ]);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));
        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', 'ROUNDING-ORDER-1', 1000.00, 'BDT');

        $paymentId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'REFUND-ROUNDING-' . strtoupper(bin2hex(random_bytes(4))),
            'invoice_id' => null,
            'branch_id' => null,
            'gateway_slug' => 'offline',
            'amount' => 1000.00,
            'currency' => 'BDT',
            'status' => 'settled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([333.33, 333.33, 333.34] as $index => $amount) {
            $refundId = DB::table('payment_refunds')->insertGetId([
                'payment_transaction_id' => $paymentId,
                'amount' => $amount,
                'reason' => 'Rounding-safe partial refund ' . ($index + 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $service->reverseSettledPurchase($buyer, 'commerce_order', 'ROUNDING-ORDER-1', $refundId, $amount, 'BDT');
        }

        $this->assertDatabaseHas('referral_commissions', [
            'id' => $commissionId,
            'commission_amount' => 0,
            'status' => 'reversed',
        ]);
        $this->assertSame(100.10, round((float) DB::table('referral_commission_reversals')
            ->where('referral_commission_id', $commissionId)
            ->sum('commission_reversal_amount'), 2));
    }

    public function test_student_cannot_refer_themselves_or_apply_two_referral_codes(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income']);
        $owner = $this->createUserWithRole('student');
        $anotherOwner = $this->createUserWithRole('teacher');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);

        $ownCode = $service->createOrGetCode($owner);
        $otherCode = $service->createOrGetCode($anotherOwner);

        try {
            $service->applyCode($owner, $ownCode);
            $this->fail('Self-referral must be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('referral_attributions', 0);
        }

        $service->applyCode($buyer, $ownCode);
        try {
            $service->applyCode($buyer, $otherCode);
            $this->fail('A buyer must not be assigned more than one referrer.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('referral_attributions', 1);
        }
    }
}
