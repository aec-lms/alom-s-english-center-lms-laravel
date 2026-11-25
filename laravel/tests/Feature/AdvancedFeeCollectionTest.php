<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Tests\TestCase;

class AdvancedFeeCollectionTest extends TestCase
{
    public function test_offline_installment_updates_invoice_balance_and_ledger_atomically(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management','payment-management']);
        $admin=$this->createUserWithRole('admin');
        $permission=Permission::query()->where('slug','payments.manage')->value('id');
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permission]);
        $invoice=FeeInvoice::query()->create([
            'invoice_number'=>'ADV-FEE-'.strtoupper(bin2hex(random_bytes(3))),
            'payer_name'=>'Installment Student','payer_email'=>'installment@aec.test','payer_phone'=>'01700000000',
            'subtotal_amount'=>5000,'discount_amount'=>500,'net_amount'=>4500,'paid_amount'=>0,
            'currency'=>'BDT','status'=>'unpaid','due_date'=>now()->addDays(5)->toDateString(),
        ]);

        $this->actingAs($admin)->get('/admin/payments')->assertOk()->assertSee('Collect advance / due / installment')->assertSee($invoice->invoice_number);
        $this->actingAs($admin)->post('/admin/payments/offline',[
            'invoice_id'=>$invoice->id,'amount'=>1500,'receipt_note'=>'Cash counter receipt #101',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('partial',$invoice->status);
        $this->assertSame('1500.00',$invoice->paid_amount);
        $this->assertDatabaseHas('payment_transactions',[
            'invoice_id'=>$invoice->id,'gateway_slug'=>'offline','amount'=>1500,'status'=>'settled',
        ]);

        $this->actingAs($admin)->post('/admin/payments/offline',[
            'invoice_id'=>$invoice->id,'amount'=>3001,'receipt_note'=>'Over-collection should fail',
        ])->assertStatus(422);
        $this->assertSame('1500.00',$invoice->fresh()->paid_amount);
        $this->assertSame(1,PaymentTransaction::query()->where('invoice_id',$invoice->id)->count());
    }

    public function test_fees_report_shows_billed_collected_and_overdue_amounts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management']);
        $admin=$this->createUserWithRole('admin');
        FeeInvoice::query()->create([
            'invoice_number'=>'ADV-OVERDUE-'.strtoupper(bin2hex(random_bytes(3))),
            'payer_name'=>'Due Student','payer_email'=>'due@aec.test','payer_phone'=>'01700000001',
            'subtotal_amount'=>2000,'discount_amount'=>0,'net_amount'=>2000,'paid_amount'=>500,
            'currency'=>'BDT','status'=>'partial','due_date'=>now()->subDay()->toDateString(),
        ]);
        $this->actingAs($admin)->get('/admin/fees?status=overdue')->assertOk()
            ->assertSee('Outstanding / overdue')->assertSee('Due Student')->assertSee('1 overdue invoices');
    }
}
