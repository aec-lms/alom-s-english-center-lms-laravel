<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SiteIdentityBrandingTest extends TestCase
{
    private function brandingAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Branding Admin',
            'email' => 'branding-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator', 'is_system' => true]
        );
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'core.settings.manage'],
            ['name' => 'Manage Core System Settings', 'module' => 'core']
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->roles()->attach($role);

        return $admin;
    }

    public function test_admin_can_save_site_identity_and_upload_safe_brand_assets(): void
    {
        $this->markSystemInstalled();
        $admin = $this->brandingAdmin();

        $this->actingAs($admin)->get(route('admin.site-identity.edit'))
            ->assertOk()
            ->assertSee('Site Identity & Branding');

        $this->actingAs($admin)->post(route('admin.site-identity.update'), [
            'tagline' => 'English learning for everyone',
            'footer_text' => '© 2026 Example Academy',
            'contact_email' => 'hello@example.test',
            'contact_phone' => '+8801700000000',
            'address' => 'Sirajganj, Bangladesh',
            'login_title' => 'Welcome to Example Academy',
            'login_message' => 'Sign in to access your learning portal',
            'logo' => UploadedFile::fake()->image('logo.png', 420, 140),
            'favicon' => UploadedFile::fake()->image('favicon.png', 64, 64),
        ])->assertRedirect(route('admin.site-identity.edit'));

        $this->assertDatabaseHas('system_settings', ['key' => 'branding.tagline', 'value' => 'English learning for everyone']);
        $this->assertDatabaseHas('system_settings', ['key' => 'branding.contact_email', 'value' => 'hello@example.test']);
        $this->assertDatabaseHas('system_settings', ['key' => 'branding.address', 'value' => 'Sirajganj, Bangladesh']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.identity.updated']);

        $logo = \App\Models\SystemSetting::query()->where('key', 'branding.logo_path')->value('value');
        $favicon = \App\Models\SystemSetting::query()->where('key', 'branding.favicon_path')->value('value');
        $this->assertIsString($logo);
        $this->assertIsString($favicon);
        $this->assertFileExists(public_path(ltrim($logo, '/')));
        $this->assertFileExists(public_path(ltrim($favicon, '/')));

        $this->post(route('logout'));
        $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome to Example Academy')
            ->assertSee('English learning for everyone')
            ->assertSee('class="h-10 max-w-36 object-contain"', false);
    }

    public function test_admin_can_remove_brand_assets_without_touching_unrelated_uploads(): void
    {
        $this->markSystemInstalled();
        $admin = $this->brandingAdmin();
        $this->actingAs($admin)->post(route('admin.site-identity.update'), [
            'tagline' => 'Keep learning',
            'login_title' => 'Sign in',
            'logo' => UploadedFile::fake()->image('logo.png', 240, 80),
            'favicon' => UploadedFile::fake()->image('favicon.png', 64, 64),
        ])->assertRedirect();

        $logo = \App\Models\SystemSetting::query()->where('key', 'branding.logo_path')->value('value');
        $favicon = \App\Models\SystemSetting::query()->where('key', 'branding.favicon_path')->value('value');
        $logoPath = public_path(ltrim($logo, '/'));
        $faviconPath = public_path(ltrim($favicon, '/'));
        $unrelatedDir = public_path('uploads/unrelated');
        File::ensureDirectoryExists($unrelatedDir);
        File::put($unrelatedDir . '/keep.txt', 'keep');

        $this->actingAs($admin)->post(route('admin.site-identity.update'), [
            'tagline' => 'Keep learning',
            'login_title' => 'Sign in',
            'remove_logo' => '1',
            'remove_favicon' => '1',
        ])->assertRedirect();

        $this->assertFileDoesNotExist($logoPath);
        $this->assertFileDoesNotExist($faviconPath);
        $this->assertDatabaseHas('system_settings', ['key' => 'branding.logo_path', 'value' => '']);
        $this->assertDatabaseHas('system_settings', ['key' => 'branding.favicon_path', 'value' => '']);
        $this->assertFileExists($unrelatedDir . '/keep.txt');
        File::deleteDirectory($unrelatedDir);
    }

    public function test_non_admin_cannot_change_site_branding_and_svg_upload_is_rejected(): void
    {
        $this->markSystemInstalled();
        $teacher = User::query()->create([
            'name' => 'Teacher',
            'email' => 'branding-teacher@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($teacher)->get(route('admin.site-identity.edit'))->assertForbidden();
        $this->actingAs($teacher)->post(route('admin.site-identity.update'), [
            'tagline' => 'Not allowed',
            'login_title' => 'No',
        ])->assertForbidden();

        $admin = $this->brandingAdmin();
        $this->actingAs($admin)->from(route('admin.site-identity.edit'))
            ->post(route('admin.site-identity.update'), [
                'tagline' => 'Safe',
                'login_title' => 'Safe login',
                'logo' => UploadedFile::fake()->create('unsafe.svg', 5, 'image/svg+xml'),
            ])->assertSessionHasErrors('logo');
    }
}
