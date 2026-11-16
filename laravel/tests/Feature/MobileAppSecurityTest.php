<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\NoticeBoard\Models\Notice;
use Tests\TestCase;

class MobileAppSecurityTest extends TestCase
{
    public function test_public_mobile_api_hides_paid_course_content_and_scopes_notices(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-management', 'student-management', 'notice-board', 'mobile-app-api']);

        $course = Course::query()->create([
            'code' => 'MOB-SEC-001',
            'title' => 'Private Lesson Course',
            'slug' => 'private-lesson-course',
            'description' => 'Public course summary',
            'price' => 1200,
            'status' => 'published',
        ]);
        DB::table('course_lessons')->insert([
            'course_id' => $course->id,
            'title' => 'Paid Lesson',
            'content' => 'Secret paid lesson content',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Notice::query()->create([
            'branch_id' => null,
            'title' => 'Public Notice',
            'body' => 'Public notice body',
            'audience_role' => 'all',
            'is_pinned' => false,
            'published_at' => now()->subMinute(),
        ]);
        Notice::query()->create([
            'branch_id' => null,
            'title' => 'Student Only Notice',
            'body' => 'Private audience notice',
            'audience_role' => 'student',
            'is_pinned' => false,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/mobile/courses/' . $course->slug)
            ->assertOk()
            ->assertJsonCount(0, 'lessons')
            ->assertJsonMissingPath('data.pdf_path')
            ->assertJsonMissingPath('data.lessons');

        $this->getJson('/api/mobile/notices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Public Notice');

        $student = $this->createUserWithRole('student');
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentToken = $student->createToken('mobile-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->getJson('/api/mobile/courses/' . $course->slug . '/lessons')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Secret paid lesson content');

        $otherStudent = $this->createUserWithRole('student');
        $otherToken = $otherStudent->createToken('mobile-test-other')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->getJson('/api/mobile/courses/' . $course->slug . '/lessons')
            ->assertForbidden();
    }
}
