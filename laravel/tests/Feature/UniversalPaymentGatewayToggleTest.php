<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Models\Permission;
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
        $this->grantUniversalPaymentManagePermission($admin);
        $this->actingAs($admin)->postJson('/plugins/universal-payment/transactions', [
            'payer_name' => 'Offline Disabled Test',
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition receipt',
            'gateway' => 'bank_cash',
            'amount' => 1000,
            'external_trx_id' => 'OFFLINE-DISABLED-001',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/universal-checkout', [
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
        $this->grantUniversalPaymentManagePermission($admin);
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

    public function test_bkash_online_checkout_is_ready_while_nagad_and_rocket_remain_manual_until_merchant_profiles_are_supplied(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment', 'gateway-bkash', 'gateway-nagad', 'gateway-rocket']);

        app(PluginManager::class)->saveSettings('universal-payment', [
            'enabled_gateways' => 'bkash,nagad,rocket',
        ]);
        app(PluginManager::class)->saveSettings('gateway-bkash', [
            'enabled' => '1',
            'app_key' => 'sandbox-app-key',
            'app_secret' => 'sandbox-app-secret',
            'username' => 'sandbox-user',
            'password' => 'sandbox-password',
        ]);
        app(PluginManager::class)->saveSettings('gateway-nagad', ['enabled' => '1']);
        app(PluginManager::class)->saveSettings('gateway-rocket', ['enabled' => '1']);

        $statuses = app(\Plugins\Modules\UniversalPayment\Services\UniversalPaymentOrchestrator::class)->gatewayStatus();

        $this->assertSame('ready', $statuses['gateway-bkash']['status']);
        $this->assertTrue($statuses['gateway-bkash']['available']);
        $this->assertSame('manual_review', $statuses['gateway-nagad']['status']);
        $this->assertTrue($statuses['gateway-nagad']['available']);
        $this->assertSame('manual_review', $statuses['gateway-rocket']['status']);
        $this->assertTrue($statuses['gateway-rocket']['available']);
        $this->assertSame([], $statuses['gateway-bkash']['missing_credentials']);
    }

    public function test_cash_and_bank_transfer_are_independent_installable_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment', 'gateway-cash', 'gateway-bank-transfer']);

        app(PluginManager::class)->saveSettings('universal-payment', [
            'enabled_gateways' => 'cash,bank_transfer',
            'allow_offline_payments' => '1',
        ]);
        app(PluginManager::class)->saveSettings('gateway-cash', ['enabled' => '1']);
        app(PluginManager::class)->saveSettings('gateway-bank-transfer', ['enabled' => '0']);

        $admin = $this->createUserWithRole('admin');
        $this->grantUniversalPaymentManagePermission($admin);
        $payload = [
            'payer_name' => 'Manual Payment Test',
            'payer_contact' => '01712345678',
            'item_title' => 'Tuition fee',
            'amount' => 500,
        ];

        $this->actingAs($admin)->post('/plugins/universal-payment/transactions', $payload + [
            'gateway' => 'cash',
            'external_trx_id' => 'CASH-PLUGIN-001',
        ])->assertRedirect();
        $this->assertDatabaseHas('universal_payment_transactions', [
            'external_trx_id' => 'CASH-PLUGIN-001',
            'gateway' => 'cash',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->post('/plugins/universal-payment/transactions', $payload + [
            'gateway' => 'bank_transfer',
            'external_trx_id' => 'BANK-PLUGIN-001',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('universal_payment_transactions', ['external_trx_id' => 'BANK-PLUGIN-001']);
    }

    public function test_manual_payment_fails_closed_when_its_plugin_is_not_installed(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['universal-payment']);
        app(PluginManager::class)->saveSettings('universal-payment', [
            'enabled_gateways' => 'cash,bank_transfer',
            'allow_offline_payments' => '1',
        ]);

        $admin = $this->createUserWithRole('admin');
        $this->grantUniversalPaymentManagePermission($admin);
        $this->actingAs($admin)->post('/plugins/universal-payment/transactions', [
            'payer_name' => 'Uninstalled Method',
            'item_title' => 'Tuition fee',
            'gateway' => 'cash',
            'amount' => 500,
            'external_trx_id' => 'CASH-PLUGIN-MISSING-001',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('universal_payment_transactions', [
            'external_trx_id' => 'CASH-PLUGIN-MISSING-001',
        ]);
    }

    private function grantUniversalPaymentManagePermission(\App\Models\User $user): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'payments.universal.manage'],
            ['name' => 'Manage Universal Payments', 'module' => 'universal-payment'],
        );
        $user->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
    }

}
