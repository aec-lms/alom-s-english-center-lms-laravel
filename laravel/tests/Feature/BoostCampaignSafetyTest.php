<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\AdminBoosting\Models\BoostCampaign;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\BranchManagement\Models\Branch;
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
        $this->activatePlugins(['admin-boosting', 'course-management']);
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

    public function test_default_campaign_end_date_is_thirty_days_after_a_future_start(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting', 'course-management']);
        $admin = $this->createUserWithRole('admin');
        $course = Course::query()->create([
            'code' => 'BOOST-SCHEDULE-01',
            'title' => 'Scheduled Course',
            'slug' => 'scheduled-course',
            'price' => 0,
            'status' => 'published',
        ]);
        $futureStart = now()->addDays(10)->startOfMinute();

        $this->actingAs($admin)->post('/plugins/boost', [
            'campaign_name' => 'Future Campaign',
            'entity_type' => 'course',
            'entity_id' => $course->id,
            'placement' => 'homepage_featured',
            'destination_url' => '/courses',
            'starts_at' => $futureStart->toDateTimeString(),
        ])->assertRedirect();

        $campaign = BoostCampaign::query()->where('campaign_name', 'Future Campaign')->firstOrFail();
        $this->assertTrue($campaign->ends_at->greaterThan($campaign->starts_at));
        $this->assertEquals(30, $campaign->starts_at->diffInDays($campaign->ends_at));
    }

    public function test_campaign_target_ids_must_resolve_and_global_target_switch_clears_old_id(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting', 'course-management']);
        $admin = $this->createUserWithRole('admin');
        $targetCourse = Course::query()->create([
            'code' => 'BOOST-UPDATE-01',
            'title' => 'Targeted Course',
            'slug' => 'targeted-course',
            'price' => 0,
            'status' => 'published',
        ]);

        $this->actingAs($admin)->post('/plugins/boost', [
            'campaign_name' => 'Invalid Course Target',
            'entity_type' => 'course',
            'entity_id' => 999999,
            'placement' => 'course_listing',
            'destination_url' => '/courses',
        ])->assertSessionHasErrors('entity_id');

        $this->actingAs($admin)->post('/plugins/boost', [
            'campaign_name' => 'Invalid Global Target',
            'entity_type' => 'offer',
            'entity_id' => 1,
            'placement' => 'course_listing',
            'destination_url' => '/courses',
        ])->assertSessionHasErrors('entity_id');

        $campaign = BoostCampaign::query()->create([
            'campaign_name' => 'Target Switch',
            'entity_type' => 'course',
            'entity_id' => $targetCourse->id,
            'placement' => 'course_listing',
            'destination_url' => '/courses',
            'status' => 'active',
        ]);

        $this->actingAs($admin)->put('/plugins/boost/' . $campaign->id, [
            'campaign_name' => 'Target Switch',
            'entity_type' => 'offer',
            'placement' => 'course_listing',
            'destination_url' => '/courses',
            'priority' => 5,
            'status' => 'active',
        ])->assertRedirect();

        $this->assertNull($campaign->fresh()->entity_id);
        $this->assertSame('offer', $campaign->fresh()->entity_type);
    }

    public function test_course_and_branch_campaigns_render_only_for_matching_public_courses(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting', 'course-management', 'branch-management']);

        $branch = Branch::query()->create([
            'code' => 'BOOST-BR-01',
            'name' => 'Dhanmondi Branch',
            'city' => 'Dhaka',
            'is_active' => true,
        ]);
        $branchCourse = Course::query()->create([
            'code' => 'BOOST-BRANCH-COURSE',
            'title' => 'Branch IELTS Course',
            'slug' => 'branch-ielts-course',
            'price' => 0,
            'status' => 'published',
            'branch_id' => $branch->id,
        ]);
        $otherCourse = Course::query()->create([
            'code' => 'BOOST-OTHER-COURSE',
            'title' => 'General Spoken Course',
            'slug' => 'general-spoken-course',
            'price' => 0,
            'status' => 'published',
            'branch_id' => null,
        ]);

        $branchCampaign = BoostCampaign::query()->create([
            'campaign_name' => 'Dhanmondi Branch Promotion',
            'entity_type' => 'branch',
            'entity_id' => $branch->id,
            'placement' => 'course_listing',
            'badge_text' => 'BRANCH OFFER',
            'cta_text' => 'View Branch Offer',
            'destination_url' => '/courses',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        $courseCampaign = BoostCampaign::query()->create([
            'campaign_name' => 'IELTS Course Promotion',
            'entity_type' => 'course',
            'entity_id' => $otherCourse->id,
            'placement' => 'course_listing',
            'badge_text' => 'COURSE OFFER',
            'cta_text' => 'View Course Offer',
            'destination_url' => '/courses',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);

        $this->get('/courses')
            ->assertOk()
            ->assertSee('Dhanmondi Branch Promotion')
            ->assertSee('IELTS Course Promotion')
            ->assertSee(route('plugins.boost.impression', $branchCampaign), false)
            ->assertSee(route('plugins.boost.click', $courseCampaign), false);

        $html = $this->get('/courses')->getContent();
        $this->assertSame(1, substr_count($html, 'Dhanmondi Branch Promotion'), 'A branch campaign must render only on courses belonging to its target branch.');
        $this->assertSame(1, substr_count($html, 'IELTS Course Promotion'), 'A course campaign must render only on its target course.');
    }

    public function test_global_homepage_campaigns_are_rendered_and_tracked(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['admin-boosting']);

        $campaign = BoostCampaign::query()->create([
            'campaign_name' => 'AEC Global Admission Offer',
            'entity_type' => 'offer',
            'entity_id' => null,
            'placement' => 'homepage_featured',
            'badge_text' => 'ADMISSION OPEN',
            'cta_text' => 'Explore Offer',
            'destination_url' => '/courses',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);

        $noticeCampaign = BoostCampaign::query()->create([
            'campaign_name' => 'AEC Important Notice',
            'entity_type' => 'institution',
            'entity_id' => null,
            'placement' => 'notice_banner',
            'badge_text' => 'IMPORTANT',
            'cta_text' => 'Read Notice',
            'destination_url' => '/courses',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('AEC Global Admission Offer')
            ->assertSee('AEC Important Notice')
            ->assertSee(route('plugins.boost.impression', $campaign), false)
            ->assertSee(route('plugins.boost.click', $campaign), false)
            ->assertSee(route('plugins.boost.impression', $noticeCampaign), false)
            ->assertSee(route('plugins.boost.click', $noticeCampaign), false);
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
