<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Plugins\Modules\ReferralIncome\Services\ReferralIncomeService;

final class ReferralIncomeRedTeamHardeningTest extends TestCase
{
    private function setupReferralAdmin(): object
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['referral-income', 'student-management', 'payment-management', 'fees-management']);
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
        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', 'REDTEAM-ORDER-001', 1000, 'BDT');

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
        $this->assertSame('5.00', $metadata['before']['commission_percent'] ?? null);
        $this->assertSame('6.5', $metadata['after']['commission_percent'] ?? null);
    }

    public function test_payout_and_recovery_review_transitions_write_actor_attributed_audit_events(): void
    {
        $admin = $this->setupReferralAdmin();
        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        $service = app(ReferralIncomeService::class);
        $service->applyCode($buyer, $service->createOrGetCode($referrer));
        $commissionId = $service->recordSettledPurchase($buyer, 'commerce_order', 'REDTEAM-ORDER-002', 1000, 'BDT');

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

        if (!Schema::hasTable('course_cart_orders')) {
            Schema::create('course_cart_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('status', 20);
            });
        }

        $referrer = $this->createUserWithRole('student');
        $buyer = $this->createUserWithRole('student');
        DB::table('course_cart_orders')->insert([
            'user_id' => $buyer->id,
            'status' => 'paid',
        ]);

        $service = app(ReferralIncomeService::class);
        try {
            $service->applyCode($buyer, $service->createOrGetCode($referrer));
            $this->fail('A paid course-cart order must make the buyer ineligible for a new referral attribution.');
        } catch (\\Illuminate\\Validation\\ValidationException $exception) {
            $this->assertStringContainsString('before the first paid order', $exception->errors()['referral_code'][0] ?? '');
        }

        $this->assertDatabaseCount('referral_attributions', 0);
    }


}
