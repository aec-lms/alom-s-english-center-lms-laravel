<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScormRuntimeControllerTest extends TestCase
{
    private function setupRuntime(): array
    {
        config([
            'app.url' => 'https://lms.example.test',
            'scorm.content_origin' => 'https://scorm.example.test',
            'scorm.launch_ttl_minutes' => 240,
            'session.domain' => null,
        ]);
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'scorm']);

        $student = $this->createUserWithRole('student');
        $courseId = DB::table('courses')->insertGetId([
            'branch_id' => null,
            'code' => 'SCORM-RUN-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'title' => 'SCORM Runtime English Course',
            'slug' => 'scorm-runtime-' . bin2hex(random_bytes(3)),
            'description' => 'Runtime integration test course.',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollmentId = DB::table('enrollments')->insertGetId([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'branch_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $directory = bin2hex(random_bytes(12));
        $packageRoot = storage_path('app/scorm-packages/' . $directory);
        File::ensureDirectoryExists($packageRoot, 0700, true);
        file_put_contents($packageRoot . '/index.html', '<!doctype html><title>SCORM unit</title>');
        $packageId = DB::table('scorm_packages')->insertGetId([
            'course_id' => $courseId,
            'branch_id' => null,
            'created_by' => null,
            'title' => 'SCORM Runtime Unit',
            'identifier' => 'RUNTIME-UNIT',
            'version' => '1.2',
            'storage_directory' => $directory,
            'launch_file' => 'index.html',
            'manifest_file' => 'imsmanifest.xml',
            'package_sha256' => str_repeat('a', 64),
            'file_count' => 2,
            'expanded_bytes' => 128,
            'status' => 'published',
            'published_at' => now(),
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$student, $courseId, $enrollmentId, $packageId, $packageRoot];
    }

    private function launchTokenFromUrl(string $url): string
    {
        $segments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));
        $this->assertSame('scorm-player', $segments[0] ?? null);

        return (string) ($segments[2] ?? '');
    }

    private function commitPayload(int $attemptId, string $token, int $version, array $data, bool $finish = false): string
    {
        return json_encode([
            'attempt_id' => $attemptId,
            'token' => $token,
            'request_id' => (string) Str::uuid(),
            'version' => $version,
            'data' => $data,
            'finish' => $finish,
        ], JSON_THROW_ON_ERROR);
    }

    public function test_enrolled_student_launches_private_scorm_player_and_commits_progress(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();
        $this->actingAs($student)->get(route('plugins.scorm.student.index'))->assertOk()->assertSee('SCORM Runtime Unit');

        $response = $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId));
        $response->assertOk()->assertSee('SCORM Runtime Unit');
        $playerUrl = $response->viewData('playerUrl');
        $token = $this->launchTokenFromUrl($playerUrl);
        $attempt = DB::table('scorm_attempts')->where('scorm_package_id', $packageId)->where('user_id', $student->id)->first();

        $this->assertNotEmpty($token);
        $this->assertNotNull($attempt);
        $this->assertSame('in_progress', $attempt->completion_state);
        $this->assertSame(1, DB::table('scorm_attempt_sessions')->where('scorm_attempt_id', $attempt->id)->count());

        $this->assertSame('lms.example.test', parse_url($playerUrl, PHP_URL_HOST));
        $this->assertSame('scorm.example.test', parse_url(route('plugins.scorm.content.file', [
            'attempt' => $attempt->id, 'token' => $token, 'path' => 'index.html',
        ]), PHP_URL_HOST));

        $player = $this->get($playerUrl);
        $player->assertOk()->assertSee('scorm:status')->assertSee('contentOrigin')
            ->assertDontSee('window.API');

        $contentUrl = route('plugins.scorm.content.file', [
            'attempt' => $attempt->id,
            'token' => $token,
            'path' => 'index.html',
        ]);
        $contentResponse = $this->get($contentUrl);
        $contentResponse->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('window.API')->assertSee('LMSCommit')->assertSee($token)
            ->assertSee('https://lms.example.test/api/scorm-runtime/commit');
        $cacheControl = strtolower((string) $contentResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $origin = ['HTTP_ORIGIN' => 'https://scorm.example.test', 'CONTENT_TYPE' => 'text/plain'];
        $firstPayload = $this->commitPayload($attempt->id, $token, 0, [
            'cmi.core.lesson_location' => 'unit-1/page-2',
            'cmi.core.lesson_status' => 'incomplete',
            'cmi.core.score.raw' => '75',
            'cmi.core.session_time' => '0000:00:10.00',
            'cmi.suspend_data' => 'checkpoint-1',
        ]);
        $first = $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin, $firstPayload);
        $first->assertOk()->assertJsonPath('version', 1)->assertHeader('Access-Control-Allow-Origin', 'https://scorm.example.test');
        $replay = $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin, $firstPayload);
        $replay->assertOk()->assertJsonPath('version', 1)->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('scorm_attempts', ['id' => $attempt->id, 'total_time_seconds' => 10]);
        $this->assertDatabaseHas('scorm_attempts', [
            'id' => $attempt->id,
            'state_version' => 1,
            'lesson_location' => 'unit-1/page-2',
            'score_raw' => 75,
            'total_time_seconds' => 10,
        ]);

        $second = $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin,
            $this->commitPayload($attempt->id, $token, 1, [
                'cmi.core.lesson_location' => 'unit-1/page-3',
                'cmi.core.lesson_status' => 'completed',
                'cmi.core.score.raw' => '92',
                'cmi.core.session_time' => '0000:00:15.00',
                'cmi.suspend_data' => 'checkpoint-2',
            ], true));
        $second->assertOk()->assertJsonPath('version', 2)->assertJsonPath('completion_state', 'completed');
        $this->assertDatabaseHas('scorm_attempts', [
            'id' => $attempt->id,
            'state_version' => 2,
            'lesson_status' => 'completed',
            'completion_state' => 'completed',
            'total_time_seconds' => 15,
        ]);

        $this->get($contentUrl)->assertOk();
        $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin,
            $this->commitPayload($attempt->id, $token, 2, ['cmi.core.lesson_location' => 'late-write']))
            ->assertStatus(409);
        File::deleteDirectory($packageRoot);
    }

    public function test_stale_runtime_version_cannot_overwrite_a_newer_commit(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();
        $response = $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId));
        $token = $this->launchTokenFromUrl($response->viewData('playerUrl'));
        $attempt = DB::table('scorm_attempts')->where('scorm_package_id', $packageId)->first();
        $origin = ['HTTP_ORIGIN' => 'https://scorm.example.test', 'CONTENT_TYPE' => 'text/plain'];

        $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin,
            $this->commitPayload($attempt->id, $token, 0, ['cmi.core.lesson_location' => 'newer']))
            ->assertOk();

        $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin,
            $this->commitPayload($attempt->id, $token, 0, ['cmi.core.lesson_location' => 'stale']))
            ->assertStatus(409);

        $this->assertDatabaseHas('scorm_attempts', ['id' => $attempt->id, 'lesson_location' => 'newer', 'state_version' => 1]);
        File::deleteDirectory($packageRoot);
    }

    // A stale launch/session must not let a revoked Student role commit SCORM progress.
    public function test_runtime_rejects_commit_after_student_role_is_revoked(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();
        $response = $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId));
        $token = $this->launchTokenFromUrl($response->viewData('playerUrl'));
        $attempt = DB::table('scorm_attempts')->where('scorm_package_id', $packageId)->first();

        DB::table('role_user')->where('user_id', $student->id)->delete();
        $this->call('POST', '/api/scorm-runtime/commit', [], [], [], [
            'HTTP_ORIGIN' => 'https://scorm.example.test',
            'CONTENT_TYPE' => 'text/plain',
        ], $this->commitPayload($attempt->id, $token, 0, ['cmi.core.lesson_location' => 'revoked']))
            ->assertStatus(403);

        $this->assertDatabaseHas('scorm_attempts', ['id' => $attempt->id, 'state_version' => 0]);
        File::deleteDirectory($packageRoot);
    }

    public function test_scorm_launch_refuses_shared_lms_session_cookie_domain(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();
        config(['session.domain' => '.example.test']);

        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertStatus(503);
        $this->assertDatabaseCount('scorm_attempts', 0);
        File::deleteDirectory($packageRoot);
    }

    public function test_paid_course_scorm_launch_requires_a_fully_settled_invoice(): void
    {
        [$student, $courseId, , $packageId, $packageRoot] = $this->setupRuntime();
        DB::table('courses')->where('id', $courseId)->update(['price' => 1500]);

        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertForbidden();
        $this->assertDatabaseCount('scorm_attempts', 0);
        File::deleteDirectory($packageRoot);
    }

    public function test_completed_enrollment_can_resume_or_review_scorm_activity(): void
    {
        [$student, , $enrollmentId, $packageId, $packageRoot] = $this->setupRuntime();
        DB::table('enrollments')->where('id', $enrollmentId)->update(['status' => 'completed']);

        $this->actingAs($student)
            ->get(route('plugins.scorm.launch', $packageId))
            ->assertOk()
            ->assertSee('SCORM Runtime Unit');

        $this->assertDatabaseHas('scorm_attempts', [
            'scorm_package_id' => $packageId,
            'user_id' => $student->id,
            'completion_state' => 'in_progress',
        ]);
        File::deleteDirectory($packageRoot);
    }

    public function test_pending_enrollment_and_cross_branch_package_are_denied(): void
    {
        [$student, $courseId, $enrollmentId, $packageId, $packageRoot] = $this->setupRuntime();
        DB::table('enrollments')->where('id', $enrollmentId)->update(['status' => 'pending']);

        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertForbidden();
        $this->assertDatabaseCount('scorm_attempts', 0);

        DB::table('enrollments')->where('id', $enrollmentId)->update(['status' => 'active', 'branch_id' => 22]);
        DB::table('courses')->where('id', $courseId)->update(['branch_id' => 22]);
        DB::table('scorm_packages')->where('id', $packageId)->update(['branch_id' => 22]);
        DB::table('users')->where('id', $student->id)->update(['branch_id' => 11]);

        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertForbidden();
        $this->assertDatabaseCount('scorm_attempts', 0);
        File::deleteDirectory($packageRoot);
    }

    public function test_inactive_student_archived_package_and_direct_private_file_access_are_denied(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();

        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertOk();
        $attempt = DB::table('scorm_attempts')->where('scorm_package_id', $packageId)->first();
        $this->get(route('plugins.scorm.content.file', [
            'attempt' => $attempt->id,
            'token' => str_repeat('0', 64),
            'path' => 'index.html',
        ]))->assertForbidden();

        DB::table('scorm_packages')->where('id', $packageId)->update(['status' => 'archived']);
        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertNotFound();

        DB::table('scorm_packages')->where('id', $packageId)->update(['status' => 'published']);
        DB::table('users')->where('id', $student->id)->update(['is_active' => false]);
        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertForbidden();
        File::deleteDirectory($packageRoot);
    }

}
