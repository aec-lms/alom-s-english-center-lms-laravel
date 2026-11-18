<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceReconciliationReportTest extends TestCase
{
    private function activateFinancePlugins(): object
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['expense-management','fees-management','payment-management','universal-payment']);
        return $this->createUserWithRole('admin');
    }

    private function invoice(?int $branchId,float $net,float $paid,string $status='partial'): int
    {
        return DB::table('fee_invoices')->insertGetId([
            'invoice_number'=>'INV-'.strtoupper(bin2hex(random_bytes(4))),
            'branch_id'=>$branchId,'subtotal_amount'=>$net,'discount_amount'=>0,
            'net_amount'=>$net,'paid_amount'=>$paid,'currency'=>'BDT','status'=>$status,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function test_finance_report_reconciles_invoice_balance_gateway_manual_payments_refunds_and_expenses(): void
    {
        $admin=$this->activateFinancePlugins();
        $invoice=$this->invoice(null,2000,1500);
        $payment=DB::table('payment_transactions')->insertGetId([
            'transaction_ref'=>'TXN-RECON-001','invoice_id'=>$invoice,'branch_id'=>null,'gateway_slug'=>'gateway-sslcommerz',
            'amount'=>1200,'currency'=>'BDT','status'=>'settled','external_reference'=>'EXT-001',
            'settled_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('payment_refunds')->insert([
            'payment_transaction_id'=>$payment,'amount'=>100,'reason'=>'Partial refund','created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('universal_payment_transactions')->insert([
            'reference'=>'UPAY-RECON-001','payer_name'=>'Test Payer','item_title'=>'Fee invoice receipt',
            'gateway'=>'bank_cash','currency'=>'BDT','amount'=>300,'fee_invoice_id'=>$invoice,
            'status'=>'verified','verified_by'=>$admin->id,'verified_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('expenses')->insert([
            'created_by'=>$admin->id,'category'=>'Utilities','title'=>'Office electricity','amount'=>200,
            'payment_method'=>'bank','expense_date'=>now()->toDateString(),'status'=>'approved',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->actingAs($admin)->get('/admin/expenses/finance-summary')
            ->assertOk()->assertSee('Issued Invoice Amount')->assertSee('Outstanding Invoice Balance')
            ->assertSee('Collections by payment method')->assertSee('Approved expenses by category')
            ->assertSee('৳1,500.00')->assertSee('৳100.00')->assertSee('৳200.00')
            ->assertSee('৳1,200.00')->assertSee('৳2,000.00')->assertSee('৳500.00');
    }

    public function test_accountant_finance_report_is_limited_to_the_active_assigned_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management','expense-management','fees-management','payment-management','universal-payment']);
        $admin=$this->createUserWithRole('admin');
        $branchA=DB::table('branches')->insertGetId(['code'=>'FIN-A','name'=>'Finance A','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $branchB=DB::table('branches')->insertGetId(['code'=>'FIN-B','name'=>'Finance B','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $permissionId=DB::table('permissions')->where('slug','expenses.view')->value('id');
        $roleId=DB::table('roles')->where('slug','accountant')->value('id');
        DB::table('permission_role')->insertOrIgnore(['role_id'=>$roleId,'permission_id'=>$permissionId]);
        $accountant=$this->createUserWithRole('accountant',$branchA);

        $invoiceA=$this->invoice($branchA,1000,400);
        $invoiceB=$this->invoice($branchB,900,0,'unpaid');
        DB::table('payment_transactions')->insert([
            ['transaction_ref'=>'TXN-FIN-A','invoice_id'=>$invoiceA,'branch_id'=>$branchA,'gateway_slug'=>'gateway-stripe','amount'=>400,'currency'=>'BDT','status'=>'settled','settled_at'=>now(),'created_at'=>now(),'updated_at'=>now()],
            ['transaction_ref'=>'TXN-FIN-B','invoice_id'=>$invoiceB,'branch_id'=>$branchB,'gateway_slug'=>'gateway-stripe','amount'=>900,'currency'=>'BDT','status'=>'settled','settled_at'=>now(),'created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::table('expenses')->insert([
            ['branch_id'=>$branchA,'created_by'=>$admin->id,'category'=>'Local','title'=>'Local expense','amount'=>50,'payment_method'=>'cash','expense_date'=>now()->toDateString(),'status'=>'approved','created_at'=>now(),'updated_at'=>now()],
            ['branch_id'=>$branchB,'created_by'=>$admin->id,'category'=>'Remote','title'=>'Remote expense','amount'=>700,'payment_method'=>'cash','expense_date'=>now()->toDateString(),'status'=>'approved','created_at'=>now(),'updated_at'=>now()],
        ]);

        $this->actingAs($accountant)->get('/admin/expenses/finance-summary')
            ->assertOk()->assertSee('৳400.00')->assertSee('৳600.00')
            ->assertDontSee('৳900.00')->assertDontSee('৳700.00');
    }
}
