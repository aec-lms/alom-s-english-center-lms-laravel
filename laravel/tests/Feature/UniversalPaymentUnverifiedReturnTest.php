<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Tests\TestCase;

class UniversalPaymentUnverifiedReturnTest extends TestCase
{
    public function test_forged_sslcommerz_failed_and_cancelled_browser_returns_never_change_payment_state(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);

        $transaction = UniversalPaymentTransaction::query()->create([
            'reference' => 'UPAY-UNVERIFIED-RETURN',
            'payer_name' => 'Return Callback Test',
            'payer_contact' => '01712345678',
            'item_title' => 'Test e-book',
            'gateway' => 'sslcommerz',
            'currency' => 'BDT',
            'amount' => 1200,
            'status' => 'pending',
            'notes' => json_encode(['provider' => 'sslcommerz', 'purpose' => 'ebook'], JSON_THROW_ON_ERROR),
        ]);

        $this->post('/universal-payment/sslcommerz/failed', [
            'tran_id' => $transaction->reference,
        ])->assertRedirect();

        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'pending',
            'external_trx_id' => null,
            'verified_at' => null,
        ]);

        $this->post('/universal-payment/sslcommerz/cancelled', [
            'tran_id' => $transaction->reference,
        ])->assertRedirect();

        $this->assertDatabaseHas('universal_payment_transactions', [
            'id' => $transaction->id,
            'status' => 'pending',
            'external_trx_id' => null,
            'verified_at' => null,
        ]);

        $this->assertSame(
            ['provider' => 'sslcommerz', 'purpose' => 'ebook'],
            json_decode((string) DB::table('universal_payment_transactions')->where('id', $transaction->id)->value('notes'), true)
        );
    }
}
