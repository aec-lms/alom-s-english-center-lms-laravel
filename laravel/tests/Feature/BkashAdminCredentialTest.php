<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Models\PluginSetting;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BkashAdminCredentialTest extends TestCase
{
    public function test_admin_can_store_encrypted_bkash_credentials_and_test_sandbox_auth_without_charging(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-bkash']);

        $manager = app(PluginManager::class);
        $manager->saveSettings('gateway-bkash', [
            'enabled' => '1',
            'sandbox_mode' => '1',
            'app_key' => 'sandbox-app-key',
            'app_secret' => 'sandbox-app-secret',
            'username' => 'sandbox-merchant-user',
            'password' => 'sandbox-merchant-password',
        ]);

        foreach (['app_key', 'app_secret', 'username', 'password'] as $key) {
            $setting = PluginSetting::query()
                ->where('plugin_slug', 'gateway-bkash')
                ->where('setting_key', $key)
                ->firstOrFail();

            $this->assertTrue($setting->is_encrypted, $key . ' must be encrypted at rest.');
            $this->assertStringNotContainsString('sandbox-', (string) $setting->setting_value);
        }

        Http::fake([
            'https://tokenized.sandbox.bka.sh/*' => Http::response([
                'statusCode' => '0000',
                'statusMessage' => 'Successful',
                'id_token' => 'test-token-that-must-not-be-persisted',
                'expires_in' => 3600,
            ], 200),
        ]);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)
            ->post('/admin/plugins/gateway-bkash/test-connection')
            ->assertRedirect()
            ->assertSessionHas('status', 'BKASH connection verified (sandbox).')
            ->assertSessionHasNoErrors();

        Http::assertSent(function (ClientRequest $request): bool {
            return str_contains($request->url(), 'tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant')
                && $request->method() === 'POST'
                && $request->hasHeader('username', 'sandbox-merchant-user')
                && $request->hasHeader('password', 'sandbox-merchant-password')
                && $request->hasHeader('X-APP-Key', 'sandbox-app-key')
                && ($request->data()['app_secret'] ?? null) === 'sandbox-app-secret';
        });

        $this->assertDatabaseMissing('plugin_settings', [
            'plugin_slug' => 'gateway-bkash',
            'setting_key' => 'id_token',
        ]);
    }

    public function test_nagad_merchant_credentials_are_configurable_from_admin_and_encrypted(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-nagad']);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)
            ->get('/admin/plugins/gateway-nagad/configure')
            ->assertOk()
            ->assertSee('Merchant ID')
            ->assertSee('Signing Private Key');

        $this->actingAs($admin)
            ->post('/admin/plugins/gateway-nagad/configure', [
                'settings' => [
                    'enabled' => '1',
                    'sandbox_mode' => '1',
                    'merchant_id' => 'NAGAD-MERCHANT-001',
                    'api_key' => 'nagad-api-key',
                    'api_secret' => 'nagad-api-secret',
                    'username' => 'nagad-merchant-user',
                    'password' => 'nagad-merchant-password',
                    'signing_private_key' => 'merchant-private-key-material',
                    'provider_public_key' => 'nagad-provider-public-key',
                ],
            ])
            ->assertRedirect('/admin/plugins')
            ->assertSessionHasNoErrors();

        foreach (['api_key', 'api_secret', 'username', 'password', 'signing_private_key', 'provider_public_key'] as $key) {
            $setting = PluginSetting::query()
                ->where('plugin_slug', 'gateway-nagad')
                ->where('setting_key', $key)
                ->firstOrFail();
            $this->assertTrue($setting->is_encrypted, $key . ' must be encrypted at rest.');
        }

        $this->assertSame('NAGAD-MERCHANT-001', app(PluginManager::class)->getSetting('gateway-nagad', 'merchant_id'));

        $this->actingAs($admin)
            ->get('/admin/plugins/gateway-nagad/configure')
            ->assertOk()
            ->assertSee('Saved and encrypted')
            ->assertDontSee('merchant-private-key-material')
            ->assertDontSee('nagad-api-secret');
    }

    public function test_bkash_connection_test_fails_safely_when_credentials_are_missing_or_rejected(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['gateway-bkash']);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)
            ->post('/admin/plugins/gateway-bkash/test-connection')
            ->assertRedirect()
            ->assertSessionHasErrors('gateway_connection');

        app(PluginManager::class)->saveSettings('gateway-bkash', [
            'app_key' => 'wrong-app-key',
            'app_secret' => 'wrong-app-secret',
            'username' => 'wrong-user',
            'password' => 'wrong-password',
            'sandbox_mode' => '1',
        ]);

        Http::fake([
            'https://tokenized.sandbox.bka.sh/*' => Http::response([
                'statusCode' => '1001',
                'statusMessage' => 'Invalid credentials',
            ], 401),
        ]);

        $this->actingAs($admin)
            ->post('/admin/plugins/gateway-bkash/test-connection')
            ->assertRedirect()
            ->assertSessionHasErrors('gateway_connection');
    }
}
