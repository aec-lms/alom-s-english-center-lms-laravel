<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LessonContentProtectionTest extends TestCase
{
    private string $privateMediaRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);
        $this->privateMediaRoot = sys_get_temp_dir() . '/aec-content-protection-' . uniqid('', true);
        config(['lms.private_storage_path' => $this->privateMediaRoot]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->privateMediaRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->privateMediaRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->privateMediaRoot);
        }
        parent::tearDown();
    }

    public function test_active_enrolled_student_receives_short_lived_signed_media_access(): void
    {
        $this->freezeTime();
        [$student, $lessonId] = $this->createProtectedLesson('active');

        $response = $this->actingAs($student)->getJson(route('plugins.lesson-media.access', $lessonId));

        $response->assertOk()->assertJsonPath('download_allowed', false)->assertJsonPath('download_url', null);
        $this->assertSame(
            $student->email . ' | ID ' . $student->id . ' | ' . now()->format('Y-m-d H:i:s'),
            $response->json('watermark')
        );

        $url = $response->json('url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        $stream = $this->get($url);
        $stream->assertOk();
        $stream->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Disposition')
            ->assertHeader('Cache-Control');
    }

    public function test_revoked_student_role_cannot_issue_or_stream_protected_media(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        $url = $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertOk()
            ->json('url');

        $student->roles()->detach();

        $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertForbidden();
        $this->get($url)->assertForbidden();
    }

    public function test_deactivated_user_cannot_access_protected_lesson_media_with_active_enrollment(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        DB::table('users')->where('id', $student->id)->update(['is_active' => false]);

        $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertForbidden();
    }

    public function test_inactive_or_soft_deleted_student_profile_cannot_access_protected_media(): void
    {
        $this->activatePlugins(['student-management']);

        [$inactiveStudent, $inactiveLessonId] = $this->createProtectedLesson('active');
        DB::table('students')->insert([
            'user_id' => $inactiveStudent->id,
            'branch_id' => null,
            'student_code' => 'STU-INACTIVE-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'admission_date' => now()->toDateString(),
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($inactiveStudent)
            ->getJson(route('plugins.lesson-media.access', $inactiveLessonId))
            ->assertForbidden();

        [$deletedStudent, $deletedLessonId] = $this->createProtectedLesson('active');
        DB::table('students')->insert([
            'user_id' => $deletedStudent->id,
            'branch_id' => null,
            'student_code' => 'STU-DELETED-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $this->actingAs($deletedStudent)
            ->getJson(route('plugins.lesson-media.access', $deletedLessonId))
            ->assertForbidden();
    }

    public function test_deactivated_account_cannot_use_previously_issued_stream_or_download_urls(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        DB::table('course_lessons')->where('id', $lessonId)->update(['is_downloadable' => true]);

        $access = $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertOk();
        $streamUrl = $access->json('url');
        $downloadUrl = $access->json('download_url');
        $this->assertNotEmpty($downloadUrl);

        DB::table('users')->where('id', $student->id)->update(['is_active' => false]);

        $this->get($streamUrl)->assertForbidden();
        $this->get($downloadUrl)->assertForbidden();
    }

    public function test_inactive_or_deleted_profile_revokes_previously_issued_media_urls(): void
    {
        $this->activatePlugins(['student-management']);

        // Issue the URLs before the profile becomes invalid to prove that stream
        // and download requests re-authorize instead of trusting the signed URL.
        [$inactiveStudent, $inactiveLessonId] = $this->createProtectedLesson('active');
        DB::table('course_lessons')->where('id', $inactiveLessonId)->update(['is_downloadable' => true]);
        $inactiveAccess = $this->actingAs($inactiveStudent)
            ->getJson(route('plugins.lesson-media.access', $inactiveLessonId))
            ->assertOk();
        $inactiveStreamUrl = $inactiveAccess->json('url');
        $inactiveDownloadUrl = $inactiveAccess->json('download_url');

        DB::table('students')->insert([
            'user_id' => $inactiveStudent->id,
            'branch_id' => null,
            'student_code' => 'STU-INACTIVE-URL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'admission_date' => now()->toDateString(),
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get($inactiveStreamUrl)->assertForbidden();
        $this->get($inactiveDownloadUrl)->assertForbidden();

        [$deletedStudent, $deletedLessonId] = $this->createProtectedLesson('active');
        DB::table('course_lessons')->where('id', $deletedLessonId)->update(['is_downloadable' => true]);
        $deletedAccess = $this->actingAs($deletedStudent)
            ->getJson(route('plugins.lesson-media.access', $deletedLessonId))
            ->assertOk();
        $deletedStreamUrl = $deletedAccess->json('url');
        $deletedDownloadUrl = $deletedAccess->json('download_url');

        DB::table('students')->insert([
            'user_id' => $deletedStudent->id,
            'branch_id' => null,
            'student_code' => 'STU-DELETED-URL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $this->get($deletedStreamUrl)->assertForbidden();
        $this->get($deletedDownloadUrl)->assertForbidden();
    }

    public function test_cancelled_enrollment_revokes_previously_issued_stream_and_download_urls(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        DB::table('course_lessons')->where('id', $lessonId)->update(['is_downloadable' => true]);

        $access = $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertOk();
        $streamUrl = $access->json('url');
        $downloadUrl = $access->json('download_url');
        $this->assertNotEmpty($downloadUrl);

        $courseId = (int) DB::table('course_lessons')->where('id', $lessonId)->value('course_id');
        DB::table('enrollments')
            ->where('user_id', $student->id)
            ->where('course_id', $courseId)
            ->update(['status' => 'cancelled']);

        $this->get($streamUrl)->assertForbidden();
        $this->get($downloadUrl)->assertForbidden();
    }

    public function test_unpublished_course_media_is_denied_even_when_enrollment_is_active(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        $courseId = (int) DB::table('course_lessons')->where('id', $lessonId)->value('course_id');
        DB::table('courses')->where('id', $courseId)->update(['status' => 'draft']);

        $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertNotFound();
    }

    public function test_pending_or_cancelled_enrollment_cannot_obtain_media_access(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('pending');

        $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertForbidden();
    }

    public function test_signed_media_url_is_bound_to_the_issuing_student_account(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        $otherStudent = $this->createUserWithRole('student');

        $url = $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertOk()
            ->json('url');

        $this->actingAs($otherStudent)->get($url)->assertForbidden();
    }

    public function test_expired_signed_media_url_is_rejected(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        $url = $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertOk()
            ->json('url');

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_media_path_traversal_is_rejected_even_for_enrolled_students(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        DB::table('course_lessons')->where('id', $lessonId)->update(['media_path' => '../outside.mp4']);

        $url = $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertOk()
            ->json('url');

        $this->get($url)->assertNotFound();
    }


    public function test_download_link_is_only_issued_when_lesson_policy_allows_downloads(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');

        $blocked = $this->actingAs($student)
            ->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertOk()
            ->assertJsonPath('download_url', null);

        $blockedUrl = URL::temporarySignedRoute(
            'plugins.lesson-media.download',
            now()->addMinutes(5),
            ['lesson' => $lessonId, 'user' => $student->id]
        );
        $this->get($blockedUrl)->assertForbidden();

        DB::table('course_lessons')->where('id', $lessonId)->update(['is_downloadable' => true]);
        $allowedUrl = $this->getJson(route('plugins.lesson-media.access', $lessonId))
            ->assertOk()
            ->json('download_url');

        $this->assertNotEmpty($allowedUrl);
        $download = $this->get($allowedUrl);
        $download->assertOk();
        $download->assertHeader('Content-Disposition');
    }

    public function test_only_active_enrolled_student_can_open_course_player(): void
    {
        [$student, $lessonId] = $this->createProtectedLesson('active');
        $courseId = (int) DB::table('course_lessons')->where('id', $lessonId)->value('course_id');

        $this->actingAs($student)
            ->get(route('plugins.lessons.course', $courseId))
            ->assertOk()
            ->assertSee('Protected Course')
            ->assertSee('Open Lesson');

        $otherStudent = $this->createUserWithRole('student');
        $this->actingAs($otherStudent)
            ->get(route('plugins.lessons.course', $courseId))
            ->assertForbidden();
    }

    private function createProtectedLesson(string $enrollmentStatus): array
    {
        $student = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'code' => 'CP-' . strtoupper(substr(uniqid(), -8)),
            'title' => 'Protected Course',
            'slug' => 'protected-course-' . uniqid(),
            'description' => 'Test course',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lessonId = DB::table('course_lessons')->insertGetId([
            'course_id' => $courseId,
            'title' => 'Protected Lesson',
            'content' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'media_path' => 'course-media/' . $courseId . '/sample.mp4',
            'media_mime' => 'video/mp4',
            'protection_enabled' => true,
            'is_downloadable' => false,
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'status' => $enrollmentStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $directory = $this->privateMediaRoot . '/course-media/' . $courseId;
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/sample.mp4', "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2");

        return [$student, $lessonId];
    }
}
