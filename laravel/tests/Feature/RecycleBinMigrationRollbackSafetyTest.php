<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RecycleBinMigrationRollbackSafetyTest extends TestCase
{
    public function test_all_recycle_bin_field_migration_rollbacks_preserve_columns_and_existing_values(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'admin-boosting',
            'batch-management',
            'branch-management',
            'carousel-builder',
            'class-management',
            'cms-website',
            'course-management',
            'page-builder',
            'slider-builder',
            'sms-gateway',
            'student-management',
            'teacher-management',
            'visitor-popup-ads',
        ]);

        $deletedAt = now()->subMinutes(7)->startOfSecond();
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'ROLLBACK-SENTINEL-' . strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Rollback Sentinel Branch',
            'is_active' => true,
            'deleted_at' => $deletedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migrations = [
            'plugins/modules/admin-boosting/Database/Migrations/2026_11_16_000022_add_boost_campaign_recycle_bin_fields.php' => ['boost_campaigns'],
            'plugins/modules/batch-management/Database/Migrations/2026_11_16_000020_add_batch_recycle_bin_fields.php' => ['study_batches'],
            'plugins/modules/branch-management/Database/Migrations/2026_11_16_000023_add_branch_recycle_bin_fields.php' => ['branches'],
            'plugins/modules/carousel-builder/Database/Migrations/2026_11_16_000024_add_carousel_recycle_bin_fields.php' => ['cms_carousels'],
            'plugins/modules/class-management/Database/Migrations/2026_11_16_000021_add_class_recycle_bin_fields.php' => ['academic_classes'],
            'plugins/modules/cms-website/Database/Migrations/2026_11_16_000025_add_cms_recycle_bin_fields.php' => ['cms_categories', 'cms_posts'],
            'plugins/modules/course-management/Database/Migrations/2026_11_16_000018_add_course_recycle_bin_fields.php' => ['courses', 'course_categories', 'live_classes'],
            'plugins/modules/page-builder/Database/Migrations/2026_11_16_000026_add_builder_page_recycle_bin_fields.php' => ['cms_pages'],
            'plugins/modules/slider-builder/Database/Migrations/2026_11_16_000027_add_hero_slider_recycle_bin_fields.php' => ['cms_sliders'],
            'plugins/modules/sms-gateway/Database/Migrations/2026_11_16_000028_add_sms_campaign_recycle_bin_fields.php' => ['sms_campaigns'],
            'plugins/modules/student-management/Database/Migrations/2026_11_16_000016_add_student_recycle_bin_fields.php' => ['students'],
            'plugins/modules/teacher-management/Database/Migrations/2026_11_16_000017_add_teacher_recycle_bin_fields.php' => ['teachers'],
            'plugins/modules/visitor-popup-ads/Database/Migrations/2026_11_16_000029_add_visitor_popup_recycle_bin_fields.php' => ['visitor_popups'],
        ];

        foreach ($migrations as $path => $tables) {
            $migration = require base_path($path);
            $migration->down();

            foreach ($tables as $table) {
                $this->assertTrue(Schema::hasTable($table), "Expected {$table} to exist for rollback verification.");
                $this->assertTrue(
                    Schema::hasColumn($table, 'deleted_at'),
                    "Rollback must retain the deleted_at column on {$table}."
                );
            }
        }

        $this->assertTrue(Schema::hasColumn('students', 'deleted_user_was_active'));
        $this->assertTrue(Schema::hasColumn('teachers', 'deleted_user_was_active'));
        $this->assertDatabaseHas('branches', [
            'id' => $branchId,
            'name' => 'Rollback Sentinel Branch',
            'deleted_at' => $deletedAt,
        ]);
    }
}
