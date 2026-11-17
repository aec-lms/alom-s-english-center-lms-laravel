<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
