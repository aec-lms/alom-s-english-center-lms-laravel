<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
            'version' => $version,
            'data' => $data,
            'finish' => $finish,
        ], JSON_THROW_ON_ERROR);
    }

    public function test_enrolled_student_launches_private_scorm_player_and_commits_progress(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();

        $response = $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId));
        $response->assertOk()->assertSee('SCORM Runtime Unit');
        $playerUrl = $response->viewData('playerUrl');
        $token = $this->launchTokenFromUrl($playerUrl);
        $attempt = DB::table('scorm_attempts')->where('scorm_package_id', $packageId)->where('user_id', $student->id)->first();

        $this->assertNotEmpty($token);
        $this->assertNotNull($attempt);
        $this->assertSame('in_progress', $attempt->completion_state);
        $this->assertSame(1, DB::table('scorm_attempt_sessions')->where('scorm_attempt_id', $attempt->id)->count());

        $player = $this->get($playerUrl);
        $player->assertOk()->assertSee('window.API')->assertSee('LMSCommit');

        $contentUrl = route('plugins.scorm.content.file', [
            'attempt' => $attempt->id,
            'token' => $token,
            'path' => 'index.html',
        ]);
        $this->get($contentUrl)->assertOk()->assertSee('SCORM unit');

        $origin = ['HTTP_ORIGIN' => 'https://scorm.example.test', 'CONTENT_TYPE' => 'text/plain'];
        $first = $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin,
            $this->commitPayload($attempt->id, $token, 0, [
                'cmi.core.lesson_location' => 'unit-1/page-2',
                'cmi.core.lesson_status' => 'incomplete',
                'cmi.core.score.raw' => '75',
                'cmi.core.session_time' => '0000:00:10.00',
                'cmi.suspend_data' => 'checkpoint-1',
            ]));
        $first->assertOk()->assertJsonPath('version', 1)->assertHeader('Access-Control-Allow-Origin', 'https://scorm.example.test');
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

        $this->get($contentUrl)->assertForbidden();
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
}
