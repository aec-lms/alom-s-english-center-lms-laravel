<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class LearningPathsPluginTest extends TestCase
{
    private function course(string $code, string $title, ?int $branchId = null): int
    {
        return DB::table('courses')->insertGetId([
            'branch_id' => $branchId,
            'code' => $code,
            'title' => $title,
            'slug' => strtolower($code) . '-' . bin2hex(random_bytes(2)),
            'description' => 'Learning path test course',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function contentPost(int $authorId, string $slug): int
    {
        return DB::table('course_content_posts')->insertGetId([
            'author_id' => $authorId,
            'title' => 'Learning Path Lesson ' . $slug,
            'slug' => $slug,
            'short_description' => 'A learning path lesson.',
            'long_description' => 'Lesson body.',
            'access_type' => 'course_enrollment',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_learning_paths_installs_standalone_without_course_management(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['learning-paths']);

        $this->assertTrue(app(PluginManager::class)->isEnabled('learning-paths'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('course-management'));
        $this->assertTrue(Schema::hasTable('learning_paths'));
        $this->assertTrue(Schema::hasTable('learning_path_courses'));
        $this->assertTrue(Schema::hasTable('learning_path_enrollments'));
    }

    public function test_admin_can_create_and_publish_an_ordered_path_and_students_can_join_after_first_course_enrollment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'learning-paths']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $first = $this->course('LP-GRAMMAR', 'Grammar Foundations');
        $second = $this->course('LP-SPEAKING', 'Speaking Practice');

        $this->actingAs($admin)->get('/admin/learning-paths')
            ->assertOk()->assertSee('Create a learning path')->assertSee('Add course step');

        $this->actingAs($admin)->post('/admin/learning-paths', [
            'title' => 'English Foundations Journey',
            'description' => 'A guided English learning sequence.',
            'status' => 'draft',
            'course_ids' => [$first, $second],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $path = DB::table('learning_paths')->where('slug', 'english-foundations-journey')->first();
        $this->assertNotNull($path);
        $this->assertDatabaseHas('learning_path_courses', [
            'learning_path_id' => $path->id,
            'course_id' => $first,
            'position' => 1,
        ]);
        $this->assertDatabaseHas('learning_path_courses', [
            'learning_path_id' => $path->id,
            'course_id' => $second,
            'position' => 2,
        ]);

        $this->actingAs($admin)->patch('/admin/learning-paths/' . $path->id . '/status', [
            'status' => 'published',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($student)->get('/learning-paths')->assertOk()->assertSee('English Foundations Journey');
        $this->actingAs($student)->post('/learning-paths/english-foundations-journey/enroll')->assertStatus(409);

        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $first,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($student)->post('/learning-paths/english-foundations-journey/enroll')
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('learning_path_enrollments', [
            'learning_path_id' => $path->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        $this->actingAs($student)->get('/my-learning-paths')
            ->assertOk()->assertSee('English Foundations Journey')->assertSee('Grammar Foundations');
    }

    public function test_enrolled_path_curriculum_cannot_be_reordered_or_replaced(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'learning-paths']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $first = $this->course('LP-LOCK-A', 'Path Lock A');
        $second = $this->course('LP-LOCK-B', 'Path Lock B');
        $third = $this->course('LP-LOCK-C', 'Path Lock C');
        $pathId = DB::table('learning_paths')->insertGetId([
            'created_by' => $admin->id,
            'title' => 'Locked Curriculum',
            'slug' => 'locked-curriculum',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$first, $second] as $index => $courseId) {
            DB::table('learning_path_courses')->insert([
                'learning_path_id' => $pathId,
                'course_id' => $courseId,
                'course_title' => DB::table('courses')->where('id', $courseId)->value('title'),
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('learning_path_enrollments')->insert([
            'learning_path_id' => $pathId,
            'user_id' => $student->id,
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->put('/admin/learning-paths/' . $pathId, [
            'title' => 'Locked Curriculum',
            'description' => 'Attempted replacement',
            'status' => 'published',
            'course_ids' => [$first, $third],
        ])->assertStatus(409);

        $this->assertDatabaseHas('learning_path_courses', ['learning_path_id' => $pathId, 'course_id' => $second, 'position' => 2]);
        $this->assertDatabaseMissing('learning_path_courses', ['learning_path_id' => $pathId, 'course_id' => $third]);
    }

    public function test_course_content_studio_works_when_optional_learning_paths_plugin_is_not_installed(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'course-content-studio']);
        $this->assertFalse(app(PluginManager::class)->isInstalled('learning-paths'));
        $teacher = $this->createUserWithRole('teacher');
        $student = $this->createUserWithRole('student');
        $course = $this->course('LP-OPTIONAL-OFF', 'Optional Integration Course');
        $post = $this->contentPost($teacher->id, 'optional-learning-paths-disabled');
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $course,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_curriculum_items')->insert([
            'course_id' => $course,
            'course_content_post_id' => $post,
            'position' => 1,
            'is_preview' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slug = DB::table('courses')->where('id', $course)->value('slug');

        $this->actingAs($student)->get('/courses/' . $slug . '/content/optional-learning-paths-disabled')->assertOk();
        $this->actingAs($student)->post('/courses/' . $slug . '/content/optional-learning-paths-disabled/complete')
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_mysql_path_status_and_enrollment_serialize_on_the_same_parent_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Path status/enrollment serialization requires MySQL 8 row locks.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'learning-paths']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $first = $this->course('LP-LOCK-RACE-A', 'Path Lock Race Course A');
        $second = $this->course('LP-LOCK-RACE-B', 'Path Lock Race Course B');
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $first,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $createPath = function (string $slug) use ($admin, $first, $second): int {
            $pathId = DB::table('learning_paths')->insertGetId([
                'created_by' => $admin->id,
                'title' => $slug,
                'slug' => $slug,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ([$first, $second] as $index => $courseId) {
                DB::table('learning_path_courses')->insert([
                    'learning_path_id' => $pathId,
                    'course_id' => $courseId,
                    'course_title' => DB::table('courses')->where('id', $courseId)->value('title'),
                    'position' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return $pathId;
        };

        $statusPathId = $createPath('status-lock-race-path');
        $enrollmentPathId = $createPath('enrollment-lock-race-path');
        $default = DB::getDefaultConnection();
        $secondaryName = 'learning_path_lock_' . bin2hex(random_bytes(3));
        config(['database.connections.' . $secondaryName => config('database.connections.' . $default)]);
        DB::purge($secondaryName);
        $primary = DB::connection($default);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            // An archive transaction owns the parent path row. A concurrent
            // enrolment must wait, then observe the archived status and fail.
            $primary->beginTransaction();
            $this->actingAs($admin)->patch('/admin/learning-paths/' . $statusPathId . '/status', [
                'status' => 'archived',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $secondary->beginTransaction();
            try {
                $secondary->table('learning_paths')->where('id', $statusPathId)->lockForUpdate()->first();
                $this->fail('Concurrent path enrollment must wait for the path status transaction.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            } finally {
                if ($secondary->transactionLevel() > 0) $secondary->rollBack();
            }
            $primary->commit();

            $this->actingAs($student)->post('/learning-paths/status-lock-race-path/enroll')->assertNotFound();

            // Conversely, a join transaction owns the same parent row. An
            // archive waits, then rechecks the committed active enrollment.
            $primary->beginTransaction();
            $this->actingAs($student)->post('/learning-paths/enrollment-lock-race-path/enroll')
                ->assertRedirect()->assertSessionHasNoErrors();

            $secondary->beginTransaction();
            try {
                $secondary->table('learning_paths')->where('id', $enrollmentPathId)->lockForUpdate()->first();
                $this->fail('Concurrent path archive must wait for the learner enrollment transaction.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            } finally {
                if ($secondary->transactionLevel() > 0) $secondary->rollBack();
            }
            $primary->commit();

            $this->actingAs($admin)->patch('/admin/learning-paths/' . $enrollmentPathId . '/status', [
                'status' => 'archived',
            ])->assertStatus(409);
            $this->assertDatabaseHas('learning_path_enrollments', [
                'learning_path_id' => $enrollmentPathId,
                'user_id' => $student->id,
                'status' => 'active',
            ]);
        } finally {
            if ($primary->transactionLevel() > 0) $primary->rollBack();
            DB::purge($secondaryName);
        }
    }

    public function test_branch_reassignment_after_path_enrollment_revokes_course_content_access(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'course-content-studio', 'learning-paths']);
        $admin = $this->createUserWithRole('admin');
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'LP-REASSIGN-A', 'name' => 'Path Original Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'LP-REASSIGN-B', 'name' => 'Path New Branch', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', $branchA);
        $first = $this->course('LP-REASSIGN-1', 'Global Prerequisite Course');
        $second = $this->course('LP-REASSIGN-2', 'Global Next Course');
        DB::table('enrollments')->insert([
            ['user_id' => $student->id, 'course_id' => $first, 'status' => 'completed', 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $student->id, 'course_id' => $second, 'status' => 'active', 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $pathId = DB::table('learning_paths')->insertGetId([
            'branch_id' => $branchA,
            'created_by' => $admin->id,
            'title' => 'Branch-bound Learning Journey',
            'slug' => 'branch-bound-learning-journey',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$first, $second] as $index => $courseId) {
            DB::table('learning_path_courses')->insert([
                'learning_path_id' => $pathId,
                'course_id' => $courseId,
                'course_title' => DB::table('courses')->where('id', $courseId)->value('title'),
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('learning_path_enrollments')->insert([
            'learning_path_id' => $pathId,
            'user_id' => $student->id,
            'branch_id' => $branchA,
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $post = $this->contentPost($admin->id, 'branch-reassigned-path-lesson');
        DB::table('course_curriculum_items')->insert([
            'course_id' => $second,
            'course_content_post_id' => $post,
            'position' => 1,
            'is_preview' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slug = DB::table('courses')->where('id', $second)->value('slug');

        $this->actingAs($student)->get('/courses/' . $slug . '/content/branch-reassigned-path-lesson')->assertOk();

        DB::table('users')->where('id', $student->id)->update(['branch_id' => $branchB]);

        $this->actingAs($student)->get('/courses/' . $slug . '/content/branch-reassigned-path-lesson')->assertForbidden();
    }

    public function test_course_in_active_learning_path_cannot_be_deleted(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'learning-paths']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $first = $this->course('LP-KEEP-A', 'Protected Path Course A');
        $second = $this->course('LP-KEEP-B', 'Protected Path Course B');
        $pathId = DB::table('learning_paths')->insertGetId([
            'created_by' => $admin->id,
            'title' => 'Protected Curriculum',
            'slug' => 'protected-curriculum',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$first, $second] as $index => $courseId) {
            DB::table('learning_path_courses')->insert([
                'learning_path_id' => $pathId,
                'course_id' => $courseId,
                'course_title' => DB::table('courses')->where('id', $courseId)->value('title'),
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('learning_path_enrollments')->insert([
            'learning_path_id' => $pathId,
            'user_id' => $student->id,
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->put('/admin/courses/' . $first, [
            'code' => DB::table('courses')->where('id', $first)->value('code'),
            'title' => 'Protected Path Course A',
            'price' => 0,
            'status' => 'archived',
        ])->assertStatus(409);

        $this->actingAs($admin)->delete('/admin/courses/' . $first)->assertStatus(409);
        $this->assertDatabaseHas('courses', ['id' => $first, 'status' => 'published']);
        $this->assertDatabaseHas('learning_path_courses', ['learning_path_id' => $pathId, 'course_id' => $first]);
    }

    public function test_active_learning_path_blocks_later_course_content_until_previous_course_is_completed(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'course-content-studio', 'learning-paths']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $first = $this->course('LP-GATE-A', 'Prerequisite Course');
        $second = $this->course('LP-GATE-B', 'Locked Course');
        foreach ([$first, $second] as $courseId) {
            DB::table('enrollments')->insert([
                'user_id' => $student->id,
                'course_id' => $courseId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $pathId = DB::table('learning_paths')->insertGetId([
            'created_by' => $admin->id,
            'title' => 'Sequential English',
            'slug' => 'sequential-english',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$first, $second] as $index => $courseId) {
            DB::table('learning_path_courses')->insert([
                'learning_path_id' => $pathId,
                'course_id' => $courseId,
                'course_title' => DB::table('courses')->where('id', $courseId)->value('title'),
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('learning_path_enrollments')->insert([
            'learning_path_id' => $pathId,
            'user_id' => $student->id,
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $firstPost = $this->contentPost($admin->id, 'learning-path-first-lesson');
        $secondPost = $this->contentPost($admin->id, 'learning-path-second-lesson');
        DB::table('course_content_posts')->where('id', $secondPost)->update(['access_type' => 'free']);
        $firstItem = DB::table('course_curriculum_items')->insertGetId([
            'course_id' => $first,
            'course_content_post_id' => $firstPost,
            'position' => 1,
            'is_preview' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_curriculum_items')->insert([
            'course_id' => $second,
            'course_content_post_id' => $secondPost,
            'position' => 1,
            'is_preview' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstSlug = DB::table('courses')->where('id', $first)->value('slug');
        $secondSlug = DB::table('courses')->where('id', $second)->value('slug');
        $legacyLesson = DB::table('course_lessons')->insertGetId([
            'course_id' => $second,
            'title' => 'Legacy protected lesson',
            'content' => 'Legacy lesson body.',
            'sort_order' => 1,
            'media_path' => 'private/legacy-lesson.mp4',
            'media_mime' => 'video/mp4',
            'protection_enabled' => true,
            'is_downloadable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->get('/my-learning/courses/' . $second . '/lessons')->assertForbidden();
        $this->actingAs($student)->get('/my-learning/lessons/' . $legacyLesson . '/media-access')->assertForbidden();
        $this->actingAs($student)->get('/courses/' . $secondSlug . '/content/learning-path-second-lesson')->assertForbidden();

        $this->actingAs($student)->post('/courses/' . $firstSlug . '/content/learning-path-first-lesson/complete')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($student)->get('/courses/' . $secondSlug . '/content/learning-path-second-lesson')
            ->assertOk()->assertSee('Learning Path Lesson learning-path-second-lesson');
        $this->actingAs($student)->get('/my-learning/courses/' . $second . '/lessons')->assertOk();
        $this->actingAs($student)->get('/my-learning/lessons/' . $legacyLesson . '/media-access')->assertOk();

        $this->assertDatabaseHas('course_content_progress', [
            'user_id' => $student->id,
            'course_curriculum_item_id' => $firstItem,
        ]);
    }

    public function test_path_branch_isolation_hides_branch_paths_from_unassigned_and_other_branch_students(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'learning-paths']);
        $admin = $this->createUserWithRole('admin');
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'LP-BR-A', 'name' => 'Learning Path Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'LP-BR-B', 'name' => 'Learning Path Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $this->course('LP-BR-A1', 'Branch Course One', $branchA);
        $second = $this->course('LP-BR-A2', 'Branch Course Two', $branchA);
        $pathId = DB::table('learning_paths')->insertGetId([
            'branch_id' => $branchA,
            'created_by' => $admin->id,
            'title' => 'Branch A Journey',
            'slug' => 'branch-a-journey',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$first, $second] as $index => $courseId) {
            DB::table('learning_path_courses')->insert([
                'learning_path_id' => $pathId,
                'course_id' => $courseId,
                'course_title' => DB::table('courses')->where('id', $courseId)->value('title'),
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $unassigned = $this->createUserWithRole('student');
        $otherBranch = $this->createUserWithRole('student', $branchB);
        $sameBranch = $this->createUserWithRole('student', $branchA);

        $this->actingAs($unassigned)->get('/learning-paths')->assertOk()->assertDontSee('Branch A Journey');
        $this->actingAs($unassigned)->get('/learning-paths/branch-a-journey')->assertNotFound();
        $this->actingAs($otherBranch)->get('/learning-paths/branch-a-journey')->assertNotFound();
        $this->actingAs($sameBranch)->get('/learning-paths/branch-a-journey')->assertOk()->assertSee('Branch A Journey');
    }
}
