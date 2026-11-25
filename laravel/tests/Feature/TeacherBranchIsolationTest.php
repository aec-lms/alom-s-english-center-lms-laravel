<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherBranchIsolationTest extends TestCase
{
    public function test_teacher_without_a_branch_cannot_access_a_branch_scoped_live_class_by_name_collision(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management']);

        $branchId = DB::table('branches')->insertGetId([
            'code' => 'TEACHER-SCOPE-' . strtoupper(substr(uniqid(), -8)),
            'name' => 'Teacher Scope Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacher = $this->createUserWithRole('teacher');
        $teacher->forceFill(['name' => 'Shared Instructor Name'])->save();

        $courseId = DB::table('courses')->insertGetId([
            'code' => 'TEACHER-SCOPE-' . strtoupper(substr(uniqid(), -8)),
            'title' => 'Branch Restricted Course',
            'slug' => 'teacher-scope-' . uniqid(),
            'description' => 'A branch-scoped course used for authorization regression coverage.',
            'price' => 0,
            'status' => 'published',
            'branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $liveClassId = DB::table('live_classes')->insertGetId([
            'course_id' => $courseId,
            'title' => 'Branch Restricted Live Class',
            'instructor_name' => 'Shared Instructor Name',
            'platform' => 'zoom',
            'meeting_url' => 'https://example.test/meeting',
            'starts_at' => now()->subMinute(),
            'capacity' => 30,
            'status' => 'live',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($teacher)
            ->get(route('plugins.live-classes.instructor', $liveClassId))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->postJson(route('plugins.learning-interactions.live.announcements.store', $liveClassId), [
                'body' => 'Unauthorized branch announcement.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('live_class_discussions', [
            'live_class_id' => $liveClassId,
            'body' => 'Unauthorized branch announcement.',
        ]);
    }
}
