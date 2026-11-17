<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GlobalSiteSettingsCentralAdminTest extends TestCase
{
    public function test_branch_manager_is_denied_by_central_admin_middleware_even_with_other_permissions(): void
    {
        $this->markSystemInstalled();

        Route::middleware(['web', 'auth', 'central.admin'])
            ->get('/_test/central-admin-only', fn () => response('allowed'));

        $manager = $this->createUserWithRole('branch_manager');

        $this->actingAs($manager)->get('/_test/central-admin-only')->assertForbidden();
    }

    public function test_central_admin_can_pass_central_admin_middleware(): void
    {
        $this->markSystemInstalled();

        Route::middleware(['web', 'auth', 'central.admin'])
            ->get('/_test/central-admin-only', fn () => response('allowed'));

        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->get('/_test/central-admin-only')
            ->assertOk()
            ->assertSeeText('allowed');
    }

    public function test_branch_manager_cannot_access_global_mobile_or_gateway_configuration_routes(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['mobile-app-api', 'gateway-stripe', 'gateway-paypal', 'storefront-catalog']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = \Illuminate\Support\Facades\DB::table('permissions')
            ->whereIn('slug', ['mobile.api.access', 'payments.stripe.manage', 'payments.paypal.manage', 'cms.manage'])
            ->pluck('id');
        $this->assertCount(4, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)->get('/admin/mobile-app')->assertForbidden();
        $this->actingAs($manager)->get('/admin/storefront')->assertForbidden();
        $this->actingAs($manager)->get('/admin/payments/stripe')->assertForbidden();
        $this->actingAs($manager)->get('/admin/payments/paypal')->assertForbidden();
    }

    public function test_branch_manager_cannot_view_global_menu_editor_even_with_settings_permission(): void
    {
        $this->markSystemInstalled();

        $manager = $this->createUserWithRole('branch_manager');
        $permissionId = DB::table('permissions')->where('slug', 'core.settings.manage')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $this->actingAs($manager)->get('/admin/menu-editor')->assertForbidden();
    }


    public function test_branch_manager_cannot_access_core_global_administration_areas(): void
    {
        $this->markSystemInstalled();
        $manager = $this->createUserWithRole('branch_manager');
        $permissionIds = \Illuminate\Support\Facades\DB::table('permissions')
            ->whereIn('slug', ['core.settings.manage', 'core.plugins.manage', 'core.backups.manage', 'core.recycle_bin.manage'])
            ->pluck('id');
        $this->assertCount(4, $permissionIds);
        $manager->roles()->first()->permissions()->syncWithoutDetaching($permissionIds);

        $this->actingAs($manager)->get('/admin/site-identity')->assertForbidden();
        $this->actingAs($manager)->get('/admin/plugins')->assertForbidden();
        $this->actingAs($manager)->get('/admin/backups')->assertForbidden();
        $this->actingAs($manager)->get('/admin/recycle-bin')->assertForbidden();
    }

}
