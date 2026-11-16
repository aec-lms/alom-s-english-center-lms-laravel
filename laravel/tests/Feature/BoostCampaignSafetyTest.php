<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BoostCampaignSafetyTest extends TestCase
{
    public function test_boost_click_rejects_unsafe_destination_and_does_not_count_click(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting']);

        $campaignId = DB::table('boost_campaigns')->insertGetId([
            'campaign_name' => 'Unsafe Campaign',
            'entity_type' => 'course',
            'placement' => 'homepage_featured',
            'destination_url' => 'javascript:alert(1)',
            'priority' => 5,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/boost/click/' . $campaignId)
            ->assertRedirect('/courses');

        $this->assertDatabaseHas('boost_campaigns', ['id' => $campaignId, 'clicks' => 0]);
    }

    public function test_admin_cannot_create_boost_campaign_with_unsafe_destination(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/plugins/boost', [
            'campaign_name' => 'Unsafe Campaign',
            'entity_type' => 'course',
            'placement' => 'homepage_featured',
            'destination_url' => 'data:text/html,hello',
            'priority' => 5,
        ])->assertSessionHasErrors('destination_url');

        $this->assertSame(0, DB::table('boost_campaigns')->count());
    }
}
