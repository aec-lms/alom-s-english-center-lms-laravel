<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Tests\TestCase;

class PaymentBranchIsolationTest extends TestCase
{
    public function test_unassigned_and_inactive_branch_managers_are_denied_payment_workflows(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'fees-management', 'payment-management']);

        $unassigned = $this->createUserWithRole('branch_manager');
        $this->grantPaymentPermission($unassigned);

        $inactiveBranchId = DB::table('branches')->insertGetId([
            'code' => 'INACTIVE',
            'name' => 'Inactive Branch',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inactive = $this->createUserWithRole('branch_manager', $inactiveBranchId);
        $this->grantPaymentPermission($inactive);

        $transaction = PaymentTransaction::query()->create([
            'transaction_ref' => 'TXN-BRANCH-GUARD-001',
            'branch_id' => $inactiveBranchId,
            'gateway_slug' => 'offline',
            'amount' => 1000,
            'currency' => 'BDT',
            'status' => 'settled',
        ]);

        foreach ([$unassigned, $inactive] as $manager) {
            $this->actingAs($manager)->get('/admin/payments')->assertForbidden();

            $this->actingAs($manager)->post('/admin/payments/offline', [
                'invoice_id' => 999999,
                'amount' => 100,
            ])->assertForbidden();

            $this->actingAs($manager)
                ->post('/admin/payments/' . $transaction->id . '/refund', [
                    'amount' => 100,
                    'reason' => 'Branch isolation regression test',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_branch_manager_lists_only_own_branch_and_cannot_pay_another_branch_invoice(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'fees-management', 'payment-management']);

        $ownBranchId = DB::table('branches')->insertGetId([
            'code' => 'OWN',
            'name' => 'Own Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherBranchId = DB::table('branches')->insertGetId([
            'code' => 'OTHER',
            'name' => 'Other Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = $this->createUserWithRole('branch_manager', $ownBranchId);
        $this->grantPaymentPermission($manager);

        PaymentTransaction::query()->create([
            'transaction_ref' => 'TXN-OWN-BRANCH-001',
            'branch_id' => $ownBranchId,
            'gateway_slug' => 'offline',
            'amount' => 1000,
            'currency' => 'BDT',
            'status' => 'settled',
        ]);
        PaymentTransaction::query()->create([
            'transaction_ref' => 'TXN-OTHER-BRANCH-001',
            'branch_id' => $otherBranchId,
            'gateway_slug' => 'offline',
            'amount' => 2000,
            'currency' => 'BDT',
            'status' => 'settled',
        ]);

        $this->actingAs($manager)
            ->get('/admin/payments')
            ->assertOk()
            ->assertSee('TXN-OWN-BRANCH-001')
            ->assertDontSee('TXN-OTHER-BRANCH-001');

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-OTHER-BRANCH-001',
            'branch_id' => $otherBranchId,
            'subtotal_amount' => 500,
            'discount_amount' => 0,
            'net_amount' => 500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $this->actingAs($manager)->post('/admin/payments/offline', [
            'invoice_id' => $invoice->id,
            'amount' => 100,
        ])->assertForbidden();

        $this->assertDatabaseMissing('payment_transactions', [
            'invoice_id' => $invoice->id,
            'gateway_slug' => 'offline',
        ]);
    }

    private function grantPaymentPermission($manager): void
    {
        $permissionIds = DB::table('permissions')->where('slug', 'payments.manage')->pluck('id');
        $this->assertCount(1, $permissionIds);
        $manager->roles()->firstOrFail()->permissions()->syncWithoutDetaching($permissionIds);
    }
}
