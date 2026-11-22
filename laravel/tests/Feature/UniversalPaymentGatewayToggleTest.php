<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Tests\TestCase;

class UniversalPaymentGatewayToggleTest extends TestCase
{
    public function test_offline_payment_setting_blocks_manual_and_public_cash_checkout(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);

        app(PluginManager::class)->saveSettings('universal-payment', [
            'enabled_gateways' => 'bank_cash',
            'allow_offline_payments' => '0',
        ]);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->post('/plugins/universal-payment/transactions', [
            'payer_name' => 'Offline Disabled Test',
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition receipt',
            'gateway' => 'bank_cash',
            'amount' => 1000,
            'external_trx_id' => 'OFFLINE-DISABLED-001',
        ])->assertStatus(422);

        $this->actingAs($admin)->post('/universal-checkout', [
            'payer_name' => $admin->name,
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition invoice',
            'gateway' => 'bank_cash',
            'external_trx_id' => 'OFFLINE-DISABLED-002',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('universal_payment_transactions', [
            'external_trx_id' => 'OFFLINE-DISABLED-001',
        ]);
        $this->assertDatabaseMissing('universal_payment_transactions', [
            'external_trx_id' => 'OFFLINE-DISABLED-002',
        ]);
    }

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

    public function test_bangladesh_merchant_plugins_fail_closed_until_provider_adapters_are_verified(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment', 'gateway-bkash', 'gateway-nagad', 'gateway-rocket']);

        app(PluginManager::class)->saveSettings('universal-payment', [
            'enabled_gateways' => 'bkash,nagad,rocket',
        ]);
        app(PluginManager::class)->saveSettings('gateway-bkash', [
            'app_key' => 'sandbox-app-key',
            'app_secret' => 'sandbox-app-secret',
            'username' => 'sandbox-user',
            'password' => 'sandbox-password',
        ]);

        $statuses = app(\\Plugins\\Modules\\UniversalPayment\\Services\\UniversalPaymentOrchestrator::class)->gatewayStatus();

        foreach (['gateway-bkash', 'gateway-nagad', 'gateway-rocket'] as $slug) {
            $this->assertSame('processor_adapter_required', $statuses[$slug]['status']);
            $this->assertFalse($statuses[$slug]['available']);
        }
        $this->assertSame([], $statuses['gateway-bkash']['missing_credentials']);
    }
}
