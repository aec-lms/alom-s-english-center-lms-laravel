<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketingGlobalDataAdminOnlyTest extends TestCase
{
    public function test_branch_manager_cannot_view_or_mutate_global_marketing_campaigns_or_coupons(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['marketing-management']);

        $manager = $this->createUserWithRole('branch_manager');
        $permissionId = DB::table('permissions')->where('slug', 'marketing.manage')->value('id');
        $this->assertNotNull($permissionId);
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permissionId]);

        $this->actingAs($manager)->get('/admin/marketing')->assertForbidden();
        $this->actingAs($manager)->post('/admin/marketing/campaigns', [])->assertForbidden();
        $this->actingAs($manager)->post('/admin/marketing/coupons', [])->assertForbidden();

        $this->assertSame(0, DB::table('marketing_campaigns')->count());
        $this->assertSame(0, DB::table('promo_coupons')->count());
    }
}
