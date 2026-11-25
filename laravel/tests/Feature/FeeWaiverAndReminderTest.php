<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Tests\TestCase;

final class FeeWaiverAndReminderTest extends TestCase
{
    private function adminWithFeePermission(): object
    {
        $admin = $this->createUserWithRole('admin');
        $permissionId = Permission::query()->where('slug', 'fees.manage')->value('id');
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);
        return $admin;
    }

    public function test_waiver_cannot_exceed_due_balance_and_is_audited(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management']);
        $admin = $this->adminWithFeePermission();
        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'WAIVE-' . strtoupper(bin2hex(random_bytes(3))),
            'payer_name' => 'Waiver Test', 'payer_email' => 'waiver@aec.test',
            'subtotal_amount' => 5000, 'discount_amount' => 0, 'net_amount' => 5000,
            'paid_amount' => 1000, 'currency' => 'BDT', 'status' => 'partial',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->actingAs($admin)->post("/admin/fees/invoices/{$invoice->id}/waive", [
            'amount' => 500, 'reason' => 'Approved scholarship adjustment',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('4500.00', $invoice->net_amount);
        $this->assertSame('500.00', $invoice->discount_amount);
        $this->assertSame('1000.00', $invoice->paid_amount);
        $this->assertSame('partial', $invoice->status);
        $this->assertDatabaseHas('fee_invoice_adjustments', [
            'fee_invoice_id' => $invoice->id, 'amount' => '500.00',
            'previous_net_amount' => '5000.00', 'new_net_amount' => '4500.00',
            'adjustment_type' => 'waiver', 'reason' => 'Approved scholarship adjustment',
        ]);

        $this->actingAs($admin)->post("/admin/fees/invoices/{$invoice->id}/waive", [
            'amount' => 4000, 'reason' => 'Exceeds remaining amount',
        ])->assertStatus(422);
        $this->assertSame('4500.00', $invoice->fresh()->net_amount);
        $this->assertSame(1, DB::table('fee_invoice_adjustments')->where('fee_invoice_id', $invoice->id)->count());
    }

    public function test_due_reminder_is_sent_and_recorded_for_open_invoice(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['fees-management']);
        $admin = $this->adminWithFeePermission();
        Mail::fake();

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'REMIND-' . strtoupper(bin2hex(random_bytes(3))),
            'payer_name' => 'Reminder Test', 'payer_email' => 'reminder@aec.test',
            'subtotal_amount' => 1500, 'discount_amount' => 0, 'net_amount' => 1500,
            'paid_amount' => 500, 'currency' => 'BDT', 'status' => 'partial',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($admin)->post("/admin/fees/invoices/{$invoice->id}/remind")
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fee_invoice_reminders', [
            'fee_invoice_id' => $invoice->id, 'recipient_email' => 'reminder@aec.test',
            'status' => 'sent',
        ]);
    }
}
