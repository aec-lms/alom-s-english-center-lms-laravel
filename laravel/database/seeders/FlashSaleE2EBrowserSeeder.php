<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Plugins\PluginManager;
use App\Models\PluginRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FlashSaleE2EBrowserSeeder extends Seeder
{
    public function run(): void
    {
        $manager = app(PluginManager::class);
        $manager->syncCatalog();

        foreach (['branch-management', 'course-management', 'flash-sale'] as $slug) {
            $record = PluginRecord::query()->where('slug', $slug)->first();
            if (!$record || !$record->is_installed) {
                $manager->install($slug);
            }
            $manager->enable($slug);
        }

        // The browser workflow may rerun seeders while retrying a failed
        // job. Upsert by the stable E2E slug so the fixture is deterministic
        // and never violates the courses.slug unique constraint.
        DB::table('courses')->updateOrInsert(
            ['slug' => 'e2e-premium-english-course'],
            [
                'code' => 'E2E-FLASH-COURSE-01',
                'title' => 'E2E Premium English Course',
                'description' => 'Browser-test course for the Flash Sale storefront.',
                'price' => 1000,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $courseId = (int) DB::table('courses')
            ->where('slug', 'e2e-premium-english-course')
            ->value('id');

        DB::table('flash_sales')->updateOrInsert(
            ['course_id' => $courseId, 'title' => 'E2E Winter Offer'],
            [
                'subtitle' => 'A limited-time test offer',
                'description' => 'A seeded offer used by the Flash Sale browser acceptance test.',
                'badge_text' => 'E2E 20% OFF',
                'cta_label' => 'Explore course',
                'priority' => 20,
                'branch_id' => null,
                'terms' => 'Browser test offer only.',
                'discount_type' => 'percent',
                'discount_value' => 20,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDay(),
                'is_published' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('flash_sale_settings')->updateOrInsert(
            ['id' => 1],
            [
                'page_enabled' => true,
                'page_title' => 'Flash Sale E2E',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
