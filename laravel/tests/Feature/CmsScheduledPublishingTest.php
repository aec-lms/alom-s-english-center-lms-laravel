<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\CmsWebsite\Models\CmsPost;
use Tests\TestCase;

final class CmsScheduledPublishingTest extends TestCase
{
    public function test_scheduler_publishes_only_due_approved_posts_and_records_audit_events(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['cms-website']);

        $approved = CmsPost::query()->create([
            'slug' => 'approved-scheduled-' . uniqid(),
            'title' => 'Approved Scheduled Article',
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Approved content.',
            'is_published' => false,
            'editorial_status' => 'approved',
            'publish_at' => now()->subMinute(),
        ]);
        $draft = CmsPost::query()->create([
            'slug' => 'draft-scheduled-' . uniqid(),
            'title' => 'Draft Scheduled Article',
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Unapproved content.',
            'is_published' => false,
            'editorial_status' => 'draft',
            'publish_at' => now()->subMinute(),
        ]);
        $trashed = CmsPost::query()->create([
            'slug' => 'trashed-scheduled-' . uniqid(),
            'title' => 'Trashed Scheduled Article',
            'category' => 'General',
            'author' => 'AEC LMS',
            'content' => 'Trashed approved content.',
            'is_published' => false,
            'editorial_status' => 'approved',
            'publish_at' => now()->subMinute(),
        ]);
        $trashed->delete();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduled) => $scheduled->description === 'cms-publish-scheduled-posts');
        $this->assertNotNull($event, 'The named CMS scheduled publishing event must be registered.');
        $event->run(app());

        $this->assertDatabaseHas('cms_posts', [
            'id' => $approved->id,
            'is_published' => true,
            'editorial_status' => 'published',
            'publish_at' => null,
        ]);
        $this->assertDatabaseHas('cms_posts', [
            'id' => $draft->id,
            'is_published' => false,
            'editorial_status' => 'draft',
        ]);
        $this->assertSoftDeleted('cms_posts', ['id' => $trashed->id]);

        if (DB::getSchemaBuilder()->hasTable('audit_logs')) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'cms.post_scheduled_published',
                'entity_type' => 'cms_post',
                'entity_id' => (string) $approved->id,
            ]);
        }
    }
}
