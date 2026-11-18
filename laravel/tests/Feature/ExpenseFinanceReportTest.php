<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseFinanceReportTest extends TestCase
{
    public function test_finance_report_reconciles_collections_refunds_expenses_and_outstanding_invoices_by_period(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management', 'payment-management', 'expense-management']);
        $admin = $this->createUserWithRole('admin');

        $invoiceId = DB::table('fee_invoices')->insertGetId([
            'invoice_number' => 'FIN-RPT-CURRENT-001',
            'student_id' => null,
            'branch_id' => null,
            'fee_category_id' => null,
            'subtotal_amount' => 3000,
            'discount_amount' => 0,
            'net_amount' => 3000,
            'paid_amount' => 1000,
            'currency' => 'BDT',
            'status' => 'partial',
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);
        DB::table('fee_invoices')->insert([
            'invoice_number' => 'FIN-RPT-OLD-001',
            'student_id' => null,
            'branch_id' => null,
            'fee_category_id' => null,
            'subtotal_amount' => 1500,
            'discount_amount' => 0,
            'net_amount' => 1500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $settledId = DB::table('payment_transactions')->insertGetId([
            'transaction_ref' => 'FIN-RPT-SETTLED-001',
            'invoice_id' => $invoiceId,
            'branch_id' => null,
            'gateway_slug' => 'gateway-sslcommerz',
            'amount' => 1200,
            'currency' => 'BDT',
            'status' => 'settled',
            'settled_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);
        DB::table('payment_transactions')->insert([
            [
                'transaction_ref' => 'FIN-RPT-FAILED-001',
                'invoice_id' => $invoiceId,
                'branch_id' => null,
                'gateway_slug' => 'gateway-card',
                'amount' => 500,
                'currency' => 'BDT',
                'status' => 'failed',
                'settled_at' => now()->subDay(),
                'created_at' => now()->subDay(),
                'updated_at' => now(),
            ],
            [
                'transaction_ref' => 'FIN-RPT-OLD-SETTLED-001',
                'invoice_id' => $invoiceId,
                'branch_id' => null,
                'gateway_slug' => 'offline',
                'amount' => 700,
                'currency' => 'BDT',
                'status' => 'settled',
                'settled_at' => now()->subDays(10),
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ],
        ]);
        DB::table('payment_refunds')->insert([
            'payment_transaction_id' => $settledId,
            'amount' => 200,
            'reason' => 'Partial refund',
            'approved_by' => $admin->id,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        DB::table('expenses')->insert([
            [
                'branch_id' => null, 'created_by' => $admin->id, 'approved_by' => $admin->id,
                'category' => 'Utilities', 'title' => 'Electricity', 'amount' => 300,
                'payment_method' => 'cash', 'expense_date' => now()->subDay()->toDateString(),
                'status' => 'approved', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'branch_id' => null, 'created_by' => $admin->id, 'approved_by' => null,
                'category' => 'Pending', 'title' => 'Unapproved purchase', 'amount' => 900,
                'payment_method' => 'cash', 'expense_date' => now()->subDay()->toDateString(),
                'status' => 'pending', 'approved_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin)->get(route('plugins.expenses.finance', [
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to' => now()->toDateString(),
        ]))
            ->assertOk()
            ->assertSee('৳1,200.00')
            ->assertSee('৳200.00')
            ->assertSee('৳300.00')
            ->assertSee('৳700.00')
            ->assertSee('2,000.00')
            ->assertSee('3,000.00')
            ->assertDontSee('900.00')
            ->assertSee('Sslcommerz');
    }

    public function test_branch_manager_finance_report_never_includes_other_branch_ledger_rows(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'fees-management', 'payment-management', 'expense-management']);
        $admin = $this->createUserWithRole('admin');
        $localBranch = DB::table('branches')->insertGetId([
            'code' => 'FIN-LOCAL', 'name' => 'Local Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $remoteBranch = DB::table('branches')->insertGetId([
            'code' => 'FIN-REMOTE', 'name' => 'Remote Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('slug', 'branch_manager')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'expenses.view')->value('id');
        $this->assertNotNull($roleId);
        $this->assertNotNull($permissionId);
        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        $manager = $this->createUserWithRole('branch_manager', $localBranch);

        foreach ([[$localBranch, 'FIN-LOCAL-INV', 1000], [$remoteBranch, 'FIN-REMOTE-INV', 9000]] as [$branchId, $number, $amount]) {
            $invoiceId = DB::table('fee_invoices')->insertGetId([
                'invoice_number' => $number, 'student_id' => null, 'branch_id' => $branchId,
                'fee_category_id' => null, 'subtotal_amount' => $amount, 'discount_amount' => 0,
                'net_amount' => $amount, 'paid_amount' => 0, 'currency' => 'BDT', 'status' => 'unpaid',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('payment_transactions')->insert([
                'transaction_ref' => $number . '-TX', 'invoice_id' => $invoiceId, 'branch_id' => $branchId,
                'gateway_slug' => 'offline', 'amount' => $amount / 10, 'currency' => 'BDT',
                'status' => 'settled', 'settled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('expenses')->insert([
                'branch_id' => $branchId, 'created_by' => $admin->id, 'approved_by' => $admin->id,
                'category' => 'Branch', 'title' => $number . ' expense', 'amount' => $amount / 20,
                'payment_method' => 'cash', 'expense_date' => now()->toDateString(), 'status' => 'approved',
                'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($manager)->get(route('plugins.expenses.finance'))
            ->assertOk()
            ->assertSee('৳100.00')
            ->assertSee('৳50.00')
            ->assertSee('৳1,000.00')
            ->assertDontSee('৳900.00')
            ->assertDontSee('৳450.00')
            ->assertDontSee('৳9,000.00');
    }
}
