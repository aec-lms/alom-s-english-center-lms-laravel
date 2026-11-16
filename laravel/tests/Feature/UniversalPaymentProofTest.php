<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            'proof' => UploadedFile::fake()->create('bank-receipt.pdf', 120, 'application/pdf'),
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
}
