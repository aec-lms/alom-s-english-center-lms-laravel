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
        $this->activatePlugins(['referral-income', 'student-management', 'teacher-management']);

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
