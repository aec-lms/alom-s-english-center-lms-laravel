<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalPaymentProofTest extends TestCase
{
    public function test_offline_receipt_proof_is_stored_privately_and_downloaded_through_authorized_route(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);
        Storage::fake('local');
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/plugins/universal-payment/transactions', [
            'payer_name' => 'Proof Test Payer',
            'payer_contact' => '01712345678',
            'item_title' => 'IELTS course deposit',
            'gateway' => 'bank_cash',
            'amount' => 1500,
            'external_trx_id' => 'BANK-PROOF-001',
            'proof_file' => UploadedFile::fake()->create('bank-receipt.pdf', 120, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $transaction = DB::table('universal_payment_transactions')->where('external_trx_id', 'BANK-PROOF-001')->first();
        $this->assertNotNull($transaction);
        $this->assertSame('application/pdf', $transaction->proof_mime);
        $this->assertGreaterThan(0, (int) $transaction->proof_size);
        $this->assertStringStartsWith('universal-payment-proofs/', $transaction->proof_path);
        Storage::disk('local')->assertExists($transaction->proof_path);

        $this->actingAs($admin)->get('/plugins/universal-payment/transactions/' . $transaction->id . '/proof')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
    public function test_transaction_recording_rolls_back_when_required_audit_fails(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);
        $admin = $this->createUserWithRole('admin');

        AuditLog::creating(static function (): void {
            throw new RuntimeException('forced universal payment audit failure');
        });

        try {
            $this->actingAs($admin)->post('/plugins/universal-payment/transactions', [
                'payer_name' => 'Audit Failure Payer',
                'payer_contact' => '01712345678',
                'item_title' => 'Tuition deposit',
                'gateway' => 'bank_cash',
                'amount' => 1000,
                'external_trx_id' => 'BANK-AUDIT-FAIL-001',
            ])->assertStatus(500);

            $this->assertDatabaseMissing('universal_payment_transactions', [
                'external_trx_id' => 'BANK-AUDIT-FAIL-001',
            ]);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_manual_payment_status_rolls_back_when_required_audit_fails(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);
        $admin = $this->createUserWithRole('admin');
        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-AUDIT-FAIL-STATUS',
            'payer_name' => 'Audit Failure Payer',
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition deposit',
            'gateway' => 'bank_cash',
            'currency' => 'BDT',
            'amount' => 1000,
            'external_trx_id' => 'BANK-AUDIT-FAIL-STATUS',
            'status' => 'pending',
        ]);

        AuditLog::creating(static function (): void {
            throw new RuntimeException('forced universal payment status audit failure');
        });

        try {
            $this->actingAs($admin)->post('/plugins/universal-payment/transactions/' . $transaction->id . '/status', [
                'status' => 'verified',
                'payment_reference' => 'BANK-AUDIT-FAIL-STATUS-VERIFIED',
            ])->assertStatus(500);

            $this->assertDatabaseHas('universal_payment_transactions', [
                'id' => $transaction->id,
                'status' => 'pending',
                'external_trx_id' => 'BANK-AUDIT-FAIL-STATUS',
                'verified_at' => null,
            ]);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_unassigned_branch_manager_cannot_access_universal_payments_or_change_global_gateways(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'universal-payment']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = DB::table('permissions')
            ->where('slug', 'payments.universal.manage')
            ->pluck('id');
        $this->assertCount(1, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)
            ->get('/plugins/universal-payment')
            ->assertForbidden();

        $this->actingAs($manager)
            ->post('/plugins/universal-payment/gateways', [
                'default_currency' => 'BDT',
                'gateways' => ['sslcommerz'],
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->post('/plugins/universal-payment/transactions', [
                'payer_name' => 'Unassigned Manager',
                'payer_contact' => '01700000000',
                'item_title' => 'Test course',
                'gateway' => 'bank_cash',
                'amount' => 1000,
                'external_trx_id' => 'UNASSIGNED-001',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('universal_payment_transactions', [
            'external_trx_id' => 'UNASSIGNED-001',
        ]);
    }

}
