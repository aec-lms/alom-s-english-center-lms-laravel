<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Appearance\SiteNavigationManager;
use Tests\TestCase;

class SiteNavigationBuilderTest extends TestCase
{
    public function test_admin_can_customize_public_header_and_footer_navigation(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'site-navigation@aec.test');

        $this->actingAs($admin)->get(route('admin.site-navigation.index'))
            ->assertOk()
            ->assertSee('Website Navigation Builder')
            ->assertSee('Header / Main Menu')
            ->assertSee('Footer Links');

        $this->actingAs($admin)->post(route('admin.site-navigation.update'), [
            'menus' => [
                'primary' => [
                    ['label' => 'Home', 'url' => '/', 'order' => 10, 'visibility' => 'show'],
                    ['label' => 'Admissions', 'url' => '/admissions', 'order' => 20, 'visibility' => 'show'],
                    ['label' => 'Partner Portal', 'url' => 'https://partner.example.test', 'order' => 30, 'visibility' => 'show'],
                    ['label' => 'External Partner', 'url' => 'https://hidden.example.test', 'order' => 40, 'visibility' => 'hide'],
                ],
                'footer' => [
                    ['label' => 'About', 'url' => '/about-us', 'order' => 10, 'visibility' => 'show'],
                    ['label' => 'Contact', 'url' => '/contact-us', 'order' => 20, 'visibility' => 'show'],
                ],
            ],
        ])->assertRedirect(route('admin.site-navigation.index'));

        $this->assertDatabaseHas('system_settings', ['key' => 'site.navigation.primary', 'updated_by' => $admin->id]);
        $this->assertDatabaseHas('system_settings', ['key' => 'site.navigation.footer', 'updated_by' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.navigation.updated']);

        $primary = app(SiteNavigationManager::class)->items('primary');
        $this->assertSame(['Home', 'Admissions', 'Partner Portal'], array_column($primary, 'label'));
        $this->assertSame('/admissions', $primary[1]['url']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Admissions')
            ->assertSee('href="/admissions"', false)
            ->assertSee('href="https://partner.example.test" target="_blank" rel="noopener noreferrer"', false)
            ->assertDontSee('External Partner');

        $this->assertSame(['About', 'Contact'], array_column(app(SiteNavigationManager::class)->items('footer'), 'label'));
    }

    public function test_public_navigation_rejects_unsafe_and_protocol_relative_urls(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'site-navigation-security@aec.test');

        $this->actingAs($admin)->from(route('admin.site-navigation.index'))
            ->post(route('admin.site-navigation.update'), [
                'menus' => [
                    'primary' => [
                        ['label' => 'Unsafe', 'url' => 'javascript:alert(1)', 'order' => 1, 'visibility' => 'show'],
                    ],
                    'footer' => [
                        ['label' => 'Protocol relative', 'url' => '//evil.example.test', 'order' => 1, 'visibility' => 'show'],
                    ],
                ],
            ])->assertSessionHasErrors(['menus.primary.0.url', 'menus.footer.0.url']);

        $this->assertDatabaseMissing('system_settings', ['key' => 'site.navigation.primary']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'site.navigation.footer']);
    }

    public function test_navigation_manager_ignores_unsafe_legacy_saved_urls(): void
    {
        $this->markSystemInstalled();

        \Illuminate\Support\Facades\DB::table('system_settings')->insert([
            'key' => 'site.navigation.primary',
            'value' => json_encode([
                ['label' => 'Bad', 'url' => 'javascript:alert(1)', 'order' => 1, 'hidden' => false],
                ['label' => 'Good', 'url' => '/courses', 'order' => 2, 'hidden' => false],
            ], JSON_THROW_ON_ERROR),
            'is_encrypted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = app(SiteNavigationManager::class)->items('primary');
        $this->assertSame(['Good'], array_column($items, 'label'));
    }
}
