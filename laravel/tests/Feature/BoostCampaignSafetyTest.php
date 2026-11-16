<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\AdminBoosting\Models\BoostCampaign;
use Tests\TestCase;

class BoostCampaignSafetyTest extends TestCase
{
    public function test_campaign_creation_rejects_unsafe_urls_and_invalid_date_ranges(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/plugins/boost', [
            'campaign_name' => 'Unsafe Campaign',
            'entity_type' => 'course',
            'placement' => 'homepage_featured',
            'destination_url' => 'javascript:alert(1)',
            'starts_at' => '2026-11-20 10:00:00',
            'ends_at' => '2026-11-19 10:00:00',
        ])->assertSessionHasErrors(['destination_url', 'ends_at']);

        $this->assertDatabaseCount('boost_campaigns', 0);
    }

    public function test_campaign_click_only_redirects_to_safe_destination_when_active_and_in_window(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/plugins/boost', [
            'campaign_name' => 'Active Campaign',
            'entity_type' => 'course',
            'placement' => 'homepage_featured',
            'destination_url' => '/courses/ielts',
            'starts_at' => now()->subMinute()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect()
            ->assertSessionHas('status', "Boost campaign 'Active Campaign' launched.");

        $this->assertDatabaseHas('boost_campaigns', ['campaign_name' => 'Active Campaign']);
        $campaign = BoostCampaign::query()->firstOrFail();
        $this->get('/boost/click/' . $campaign->id)
            ->assertRedirect('/courses/ielts');
        $this->assertSame(1, $campaign->fresh()->clicks);

        $campaign->update(['status' => 'paused']);
        $this->get('/boost/click/' . $campaign->id)
            ->assertRedirect('/courses');
        $this->assertSame(1, $campaign->fresh()->clicks);
    }
}
