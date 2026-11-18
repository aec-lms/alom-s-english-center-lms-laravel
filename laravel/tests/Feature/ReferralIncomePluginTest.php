<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;
use Tests\TestCase;

class ReferralIncomePluginTest extends TestCase
{
    public function test_student_referral_attribution_and_settled_purchase_commission_are_idempotent(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income']);

        $studentReferrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $code = $service->createOrGetCode($studentReferrer);

        $this->assertSame($code, $service->createOrGetCode($studentReferrer));
        $this->actingAs($buyer)->post('/my-referrals/apply', ['referral_code' => $code])
            ->assertRedirect()->assertSessionHasNoErrors();

        $first = $service->recordSettledPurchase($buyer, 'course_order', 'ORDER-1001', 10000, 'BDT');
        $retry = $service->recordSettledPurchase($buyer, 'course_order', 'ORDER-1001', 10000, 'BDT');

        $this->assertSame($first, $retry);
        $this->assertDatabaseCount('referral_attributions', 1);
        $this->assertDatabaseHas('referral_commissions', [
            'referrer_user_id' => $studentReferrer->id,
            'referred_user_id' => $buyer->id,
            'source_type' => 'course_order',
            'source_id' => 'ORDER-1001',
            'commission_amount' => 500,
            'commission_percent' => 5,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('referral_commissions', 1);
    }

    public function test_teacher_referral_uses_teacher_rate_and_rejects_self_or_second_attribution(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income']);

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

        $service->recordSettledPurchase($buyer, 'ebook_order', 'EBOOK-1001', 2000, 'BDT');
        $this->assertDatabaseHas('referral_commissions', [
            'referrer_user_id' => $teacher->id,
            'commission_amount' => 200,
            'commission_percent' => 10,
        ]);
    }

    public function test_approved_commission_can_be_requested_and_paid_once_with_encrypted_payout_destination(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income']);

        $teacher = $this->createUserWithRole('teacher');
        $buyer = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $service = app(ReferralIncomeService::class);
        $code = $service->createOrGetCode($teacher);
        $service->applyCode($buyer, $code);
        $commissionId = $service->recordSettledPurchase($buyer, 'subscription_order', 'SUB-1001', 5000, 'BDT');

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
}
