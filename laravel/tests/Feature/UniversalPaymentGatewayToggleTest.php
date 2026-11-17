<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Tests\TestCase;

class UniversalPaymentGatewayToggleTest extends TestCase
{
    public function test_disabled_gateway_cannot_be_used_for_new_manual_transactions(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);

        app(PluginManager::class)->saveSettings('universal-payment', [
            'enabled_gateways' => 'sslcommerz',
        ]);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->post('/plugins/universal-payment/transactions', [
            'payer_name' => 'Disabled Gateway Test',
            'payer_contact' => '01712345678',
            'item_title' => 'IELTS course deposit',
            'gateway' => 'bank_cash',
            'amount' => 1000,
            'external_trx_id' => 'DISABLED-BANK-CASH-001',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('universal_payment_transactions', [
            'external_trx_id' => 'DISABLED-BANK-CASH-001',
        ]);
    }
}
