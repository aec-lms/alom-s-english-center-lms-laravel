<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
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
            'entity_type' => 'institution',
            'placement' => 'homepage_featured',
            'destination_url' => 'javascript:alert(1)',
            'starts_at' => '2026-11-20 10:00:00',
            'ends_at' => '2026-11-19 10:00:00',
        ])->assertSessionHasErrors(['destination_url', 'ends_at']);

        $this->assertDatabaseCount('boost_campaigns', 0);
    }

    public function test_campaign_creation_rejects_external_open_redirect_hosts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/plugins/boost', [
            'campaign_name' => 'External Redirect',
            'entity_type' => 'institution',
            'placement' => 'homepage_featured',
            'destination_url' => 'https://attacker.example/phishing',
        ])->assertSessionHasErrors('destination_url');

        $this->assertDatabaseCount('boost_campaigns', 0);
    }

    public function test_campaign_click_only_redirects_to_safe_destination_when_active_and_in_window(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting']);
        $admin = $this->createUserWithRole('admin');

        $targetCourse = Course::query()->create([
            'code' => 'BOOST-TARGET-01',
            'title' => 'Boost Target IELTS',
            'slug' => 'boost-target-ielts',
            'price' => 0,
            'status' => 'published',
            'branch_id' => null,
        ]);

        $this->actingAs($admin)->post('/plugins/boost', [
            'campaign_name' => 'Active Campaign',
            'entity_type' => 'course',
            'entity_id' => $targetCourse->id,
            'placement' => 'homepage_featured',
            'destination_url' => '/courses/ielts',
            'starts_at' => now()->subMinute()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect()
            ->assertSessionHas('status', "Boost campaign 'Active Campaign' launched.");

        $this->assertDatabaseHas('boost_campaigns', ['campaign_name' => 'Active Campaign', 'entity_id' => $targetCourse->id]);
        $campaign = BoostCampaign::query()->firstOrFail();
        $this->actingAs($admin)->put('/plugins/boost/' . $campaign->id, [
            'campaign_name' => 'Active Campaign',
            'entity_type' => 'branch',
            'entity_id' => 7,
            'placement' => 'homepage_featured',
            'destination_url' => 'javascript:alert(1)',
            'priority' => 5,
            'status' => 'active',
        ])->assertSessionHasErrors('destination_url');

        $this->assertSame('/courses/ielts', $campaign->fresh()->destination_url);
        $this->get('/boost/click/' . $campaign->id)->assertRedirect('/courses/ielts');
        $this->assertSame(1, $campaign->fresh()->clicks);

        $this->get('/boost/click/' . $campaign->id)->assertRedirect('/courses/ielts');
        $this->assertSame(1, $campaign->fresh()->clicks, 'Repeated clicks from the same session must be deduplicated.');

        $campaign->update(['status' => 'paused']);
        $this->get('/boost/click/' . $campaign->id)->assertRedirect('/courses');
        $this->assertSame(1, $campaign->fresh()->clicks);
    }

    public function test_impressions_are_recorded_once_per_session_and_campaign(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting']);
        $campaign = BoostCampaign::query()->create([
            'campaign_name' => 'Impression Test',
            'entity_type' => 'course',
            'placement' => 'homepage_featured',
            'destination_url' => '/courses',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);

        $this->get('/boost/impression/' . $campaign->id)->assertNoContent();
        $this->get('/boost/impression/' . $campaign->id)->assertNoContent();

        $this->assertSame(1, $campaign->fresh()->impressions);
        $this->assertSame(1, DB::table('boost_campaign_events')->where('campaign_id', $campaign->id)->where('event_type', 'impression')->count());
    }
}
