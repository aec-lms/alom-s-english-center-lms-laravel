<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecycleBinMigrationRollbackSafetyTest extends TestCase
{
    public function test_recycle_bin_migration_rollbacks_preserve_existing_columns_and_recovery_data(): void
    {
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

        $tables = array_values(array_unique(array_merge(...array_values($migrations))));
        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $tableName) {
                Schema::dropIfExists($tableName);
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->timestamp('deleted_at')->nullable();
                    $table->boolean('deleted_user_was_active')->nullable();
                });
                DB::table($tableName)->insert([
                    'deleted_at' => '2026-11-19 10:30:00',
                    'deleted_user_was_active' => true,
                ]);
            }

            foreach ($migrations as $migrationPath => $migrationTables) {
                $migration = require base_path($migrationPath);
                $migration->down();

                foreach ($migrationTables as $tableName) {
                    $this->assertTrue(Schema::hasColumn($tableName, 'deleted_at'), $migrationPath . ' removed a pre-existing deleted_at column from ' . $tableName);
                    $this->assertTrue(Schema::hasColumn($tableName, 'deleted_user_was_active'), $migrationPath . ' removed a pre-existing recovery-state column from ' . $tableName);
                    $this->assertDatabaseHas($tableName, [
                        'id' => 1,
                        'deleted_at' => '2026-11-19 10:30:00',
                        'deleted_user_was_active' => true,
                    ]);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
