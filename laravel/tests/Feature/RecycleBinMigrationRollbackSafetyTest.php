<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\TeacherManagement\Models\Teacher;
use Tests\TestCase;

class RecycleBinMigrationRollbackSafetyTest extends TestCase
{
    public function test_recycle_bin_migration_down_methods_preserve_columns_and_history(): void
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
            'exam-management',
            'page-builder',
            'slider-builder',
            'sms-gateway',
            'student-management',
            'teacher-management',
            'visitor-popup-ads',
        ]);

        $admin = $this->createUserWithRole('admin');
        $learner = $this->createUserWithRole('student');
        $instructor = $this->createUserWithRole('teacher');
        $student = Student::query()->create([
            'user_id' => $learner->id,
            'student_code' => 'ROLLBACK-STUDENT-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $teacher = Teacher::query()->create([
            'user_id' => $instructor->id,
            'employee_code' => 'ROLLBACK-TEACHER-001',
            'specialization' => 'English',
            'is_active' => true,
        ]);
        DB::table('students')->where('id', $student->id)->update([
            'deleted_at' => now(),
            'deleted_user_was_active' => true,
        ]);
        DB::table('teachers')->where('id', $teacher->id)->update([
            'deleted_at' => now(),
            'deleted_user_was_active' => true,
        ]);

        $migrations = [
            ['plugins/modules/admin-boosting/Database/Migrations/2026_11_16_000022_add_boost_campaign_recycle_bin_fields.php', ['boost_campaigns' => ['deleted_at']]],
            ['plugins/modules/batch-management/Database/Migrations/2026_11_16_000020_add_batch_recycle_bin_fields.php', ['study_batches' => ['deleted_at']]],
            ['plugins/modules/branch-management/Database/Migrations/2026_11_16_000023_add_branch_recycle_bin_fields.php', ['branches' => ['deleted_at']]],
            ['plugins/modules/carousel-builder/Database/Migrations/2026_11_16_000024_add_carousel_recycle_bin_fields.php', ['cms_carousels' => ['deleted_at']]],
            ['plugins/modules/class-management/Database/Migrations/2026_11_16_000021_add_class_recycle_bin_fields.php', ['academic_classes' => ['deleted_at']]],
            ['plugins/modules/cms-website/Database/Migrations/2026_11_16_000025_add_cms_recycle_bin_fields.php', ['cms_categories' => ['deleted_at'], 'cms_posts' => ['deleted_at']]],
            ['plugins/modules/course-management/Database/Migrations/2026_11_16_000018_add_course_recycle_bin_fields.php', ['courses' => ['deleted_at'], 'course_categories' => ['deleted_at'], 'live_classes' => ['deleted_at']]],
            ['plugins/modules/exam-management/Database/Migrations/2026_11_17_000003_make_exam_course_optional_and_add_recycle_bin.php', ['exams' => ['deleted_at']]],
            ['plugins/modules/page-builder/Database/Migrations/2026_11_16_000026_add_builder_page_recycle_bin_fields.php', ['cms_pages' => ['deleted_at']]],
            ['plugins/modules/slider-builder/Database/Migrations/2026_11_16_000027_add_hero_slider_recycle_bin_fields.php', ['cms_sliders' => ['deleted_at']]],
            ['plugins/modules/sms-gateway/Database/Migrations/2026_11_16_000028_add_sms_campaign_recycle_bin_fields.php', ['sms_campaigns' => ['deleted_at']]],
            ['plugins/modules/student-management/Database/Migrations/2026_11_16_000016_add_student_recycle_bin_fields.php', ['students' => ['deleted_at', 'deleted_user_was_active']]],
            ['plugins/modules/teacher-management/Database/Migrations/2026_11_16_000017_add_teacher_recycle_bin_fields.php', ['teachers' => ['deleted_at', 'deleted_user_was_active']]],
            ['plugins/modules/visitor-popup-ads/Database/Migrations/2026_11_16_000029_add_visitor_popup_recycle_bin_fields.php', ['visitor_popups' => ['deleted_at']]],
        ];

        foreach ($migrations as [$relativePath, $tables]) {
            $migration = require base_path($relativePath);
            $migration->down();

            foreach ($tables as $table => $columns) {
                $this->assertTrue(Schema::hasTable($table), $table . ' must remain after rollback.');
                foreach ($columns as $column) {
                    $this->assertTrue(Schema::hasColumn($table, $column), $table . '.' . $column . ' must be preserved.');
                }
            }
        }

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'student_code' => 'ROLLBACK-STUDENT-001',
            'deleted_user_was_active' => true,
        ]);
        $this->assertDatabaseHas('teachers', [
            'id' => $teacher->id,
            'employee_code' => 'ROLLBACK-TEACHER-001',
            'deleted_user_was_active' => true,
        ]);
    }
}
