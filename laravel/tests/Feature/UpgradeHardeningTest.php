<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class UpgradeHardeningTest extends TestCase
{
    public function test_public_health_endpoint_does_not_disclose_runtime_versions(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'timestamp'])
            ->assertJsonMissingPath('php')
            ->assertJsonMissingPath('core_version');
    }

    public function test_admin_health_endpoint_keeps_diagnostics_private(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'upgrade-health-admin@aec.test');

        $response = $this->actingAs($admin)->getJson('/api/health');
        $response->assertOk()
            ->assertJsonStructure(['status', 'timestamp', 'core_version', 'php'])
            ->assertJsonPath('php', PHP_VERSION);

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_mobile_api_rejects_a_deactivated_account_even_with_a_valid_token(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['mobile-app-api']);
        $student = $this->createUserWithRole('student', null, 'upgrade-mobile-active@aec.test');
        $token = $student->createToken('upgrade-test-device')->plainTextToken;
        $route = route('plugins.mobile.me');

        $this->withToken($token)->getJson($route)->assertOk();
        $student->forceFill(['is_active' => false])->save();
        $this->withToken($token)->getJson($route)->assertForbidden();
    }

    public function test_registration_normalizes_email_and_requires_a_strong_password(): void
    {
        $this->markSystemInstalled();
        $this->post('/register', [
            'name' => 'Weak Password User',
            'email' => 'UpgradeWeak@AEC.TEST',
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors('password');

        $this->post('/register', [
            'name' => 'Strong Password User',
            'email' => 'UpgradeStrong@AEC.TEST',
            'password' => 'StrongPassword!2026',
            'password_confirmation' => 'StrongPassword!2026',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'upgradestrong@aec.test']);
    }
}
