<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Plugins\Modules\BranchManagement\Models\Branch;
use Plugins\Modules\Scorm\Http\Controllers\ScormRuntimeController;
use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class ScormRuntimeControllerTest extends TestCase
{
    private function scormSessionCookieName(int $attemptId): string
    {
        return 'aec_scorm_' . $attemptId;
    }

    private function scormBridgeCookieName(int $attemptId): string
    {
        return 'aec_scorm_bridge_' . $attemptId;
    }

    private function setupRuntime(): array
    {
        URL::forceRootUrl('https://lms.example.test');
        config([
            'app.url' => 'https://lms.example.test',
            'scorm.player_origin' => 'https://scorm-player.example.test',
            'scorm.content_origin' => 'https://scorm-content.example.test',
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
        $this->assertSame('scorm-player.example.test', parse_url($playerUrl, PHP_URL_HOST));

        $player = $this->get($playerUrl);
        $player->assertOk()
            ->assertSee('id="scorm-sco"', false)
            ->assertSee('sandbox="allow-scripts"', false)
            ->assertSee($token);
        $playerPolicy = (string) $player->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-src https://scorm-content.example.test', $playerPolicy);
        $this->assertStringContainsString('frame-ancestors https://lms.example.test', $playerPolicy);
        $this->assertStringNotContainsString('https:;', $playerPolicy);

        $bootstrapUrl = (string) $player->viewData('launchUrl');
        $this->assertSame('scorm-content.example.test', parse_url($bootstrapUrl, PHP_URL_HOST));
        $this->assertStringContainsString('/scorm-bootstrap/' . $attempt->id . '/' . $token . '/', $bootstrapUrl);

        $bootstrap = $this->get($bootstrapUrl);
        $bootstrap->assertRedirect();
        $cleanContentUrl = (string) $bootstrap->headers->get('Location');
        $this->assertSame('scorm-content.example.test', parse_url($cleanContentUrl, PHP_URL_HOST));
        $this->assertStringContainsString('/scorm-content/' . $attempt->id . '/index.html', $cleanContentUrl);
        $this->assertStringNotContainsString($token, $cleanContentUrl);

        $cookies = collect($bootstrap->headers->getCookies());
        $cookie = $cookies->first(fn ($candidate) => $candidate->getName() === 'aec_scorm_' . $attempt->id);
        $bridgeCookie = $cookies->first(fn ($candidate) => $candidate->getName() === 'aec_scorm_bridge_' . $attempt->id);
        $this->assertNotNull($cookie);
        $this->assertNotNull($bridgeCookie);
        foreach ([$cookie, $bridgeCookie] as $scormCookie) {
            $this->assertTrue($scormCookie->isHttpOnly());
            $this->assertSame('/scorm-content/' . $attempt->id . '/', $scormCookie->getPath());
            $this->assertSame('none', strtolower((string) $scormCookie->getSameSite()));
        }
        $this->assertNotSame($cookie->getValue(), $bridgeCookie->getValue());

        parse_str((string) parse_url($bootstrapUrl, PHP_URL_QUERY), $bootstrapQuery);
        $bridgeNonce = (string) ($bootstrapQuery['bridge_nonce'] ?? '');
        $this->assertSame(64, strlen($bridgeNonce));

        // Call the content handler with the values issued by the bootstrap
        // endpoint. HTTP-cookie encryption is exercised by middleware in the
        // browser E2E; here we isolate the handler's host/token/nonce checks.
        $contentRequest = Request::create($cleanContentUrl, 'GET');
        $contentRequest->cookies->set($this->scormSessionCookieName((int) $attempt->id), $token);
        $contentRequest->cookies->set($this->scormBridgeCookieName((int) $attempt->id), $bridgeNonce);
        $contentResponse = app(ScormRuntimeController::class)->contentFile(
            $contentRequest,
            (int) $attempt->id,
            'index.html'
        );
        $this->assertSame(200, $contentResponse->getStatusCode());
        $contentBody = (string) $contentResponse->getContent();
        $this->assertStringContainsString('window.API', $contentBody);
        $this->assertStringContainsString('LMSCommit', $contentBody);
        $this->assertStringNotContainsString($token, $contentBody);
        $this->assertStringContainsString('postMessage', $contentBody);
        $this->assertStringContainsString('bridge_nonce', $contentBody);
        $this->assertStringContainsString('element.match(/^cmi\\.interactions\\.\\d+\\.(.+)$/)', $contentBody);
        $this->assertStringContainsString("if (field === 'time') return /^\\d{8}T\\d{6}(?:\\.\\d{1,2})?$/", $contentBody);
        $this->assertSame('nosniff', $contentResponse->headers->get('X-Content-Type-Options'));
        $contentPolicy = (string) $contentResponse->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("connect-src 'self'", $contentPolicy);
        $this->assertStringContainsString('frame-ancestors https://lms.example.test https://scorm-player.example.test', $contentPolicy);
        $this->assertStringContainsString("form-action 'none'", $contentPolicy);
        $this->assertStringContainsString("form-action 'none'", $contentPolicy);
        $cacheControl = strtolower((string) $contentResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $origin = ['HTTP_ORIGIN' => 'https://scorm-player.example.test', 'CONTENT_TYPE' => 'text/plain'];
        $firstPayload = $this->commitPayload($attempt->id, $token, 0, [
            'cmi.core.lesson_location' => 'unit-1/page-2',
            'cmi.core.lesson_status' => 'incomplete',
            'cmi.core.score.raw' => '750',
            'cmi.core.score.min' => '-100',
            'cmi.core.score.max' => '1000',
            'cmi.core.session_time' => '0000:00:10.00',
            'cmi.suspend_data' => 'checkpoint-1',
        ]);
        $first = $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin, $firstPayload);
        $first->assertOk()->assertJsonPath('version', 1)->assertHeader('Access-Control-Allow-Origin', 'https://scorm-player.example.test');
        $replay = $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin, $firstPayload);
        $replay->assertOk()->assertJsonPath('version', 1)->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('scorm_attempts', ['id' => $attempt->id, 'total_time_seconds' => 10]);
        $this->assertDatabaseHas('scorm_attempts', [
            'id' => $attempt->id,
            'state_version' => 1,
            'lesson_location' => 'unit-1/page-2',
            'score_raw' => 750,
            'score_min' => -100,
            'score_max' => 1000,
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

        // Revalidate the private-content handler with the issued host-only
        // cookie values directly. The real cross-host cookie transport and
        // encryption are exercised by the browser E2E suite.
        $contentAfterFinishRequest = Request::create($cleanContentUrl, 'GET');
        $contentAfterFinishRequest->cookies->set($this->scormSessionCookieName((int) $attempt->id), $token);
        $contentAfterFinishRequest->cookies->set($this->scormBridgeCookieName((int) $attempt->id), $bridgeNonce);
        $contentAfterFinish = app(ScormRuntimeController::class)->contentFile(
            $contentAfterFinishRequest,
            (int) $attempt->id,
            'index.html'
        );
        $this->assertSame(200, $contentAfterFinish->getStatusCode());
        $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $origin,
            $this->commitPayload($attempt->id, $token, 2, ['cmi.core.lesson_location' => 'late-write']))
            ->assertStatus(409);
        File::deleteDirectory($packageRoot);
    }

    public function test_runtime_commit_and_preflight_reject_untrusted_origins(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();
        $response = $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId));
        $token = $this->launchTokenFromUrl($response->viewData('playerUrl'));
        $attempt = DB::table('scorm_attempts')->where('scorm_package_id', $packageId)->first();

        $untrustedOrigin = ['HTTP_ORIGIN' => 'https://attacker.example.test', 'CONTENT_TYPE' => 'text/plain'];
        $preflight = $this->call('OPTIONS', '/api/scorm-runtime/commit', [], [], [], $untrustedOrigin);
        $preflight->assertForbidden();
        $this->assertNotSame('https://attacker.example.test', $preflight->headers->get('Access-Control-Allow-Origin'));

        $forged = $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $untrustedOrigin,
            $this->commitPayload($attempt->id, $token, 0, ['cmi.core.lesson_location' => 'forged']));
        $forged->assertForbidden();
        $this->assertNotSame('https://attacker.example.test', $forged->headers->get('Access-Control-Allow-Origin'));

        $this->assertDatabaseHas('scorm_attempts', [
            'id' => $attempt->id,
            'state_version' => 0,
            'lesson_location' => '',
        ]);

        $trustedOrigin = ['HTTP_ORIGIN' => 'https://scorm-player.example.test'];
        $this->call('OPTIONS', '/api/scorm-runtime/commit', [], [], [], $trustedOrigin)
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://scorm-player.example.test')
            ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->assertHeader('Access-Control-Allow-Headers', 'Content-Type');

        $this->call('POST', '/api/scorm-runtime/commit', [], [], [], $trustedOrigin + [
            'CONTENT_TYPE' => 'text/plain',
        ], str_repeat('x', 262145))
            ->assertStatus(413)
            ->assertHeader('Access-Control-Allow-Origin', 'https://scorm-player.example.test');

        $this->assertDatabaseHas('scorm_attempts', [
            'id' => $attempt->id,
            'state_version' => 0,
            'lesson_location' => '',
        ]);

        File::deleteDirectory($packageRoot);
    }

    public function test_stale_runtime_version_cannot_overwrite_a_newer_commit(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();
        $response = $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId));
        $token = $this->launchTokenFromUrl($response->viewData('playerUrl'));
        $attempt = DB::table('scorm_attempts')->where('scorm_package_id', $packageId)->first();
        $origin = ['HTTP_ORIGIN' => 'https://scorm-player.example.test', 'CONTENT_TYPE' => 'text/plain'];

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
            'HTTP_ORIGIN' => 'https://scorm-player.example.test',
            'CONTENT_TYPE' => 'text/plain',
        ], $this->commitPayload($attempt->id, $token, 0, ['cmi.core.lesson_location' => 'revoked']))
            ->assertStatus(403);

        $this->assertDatabaseHas('scorm_attempts', ['id' => $attempt->id, 'state_version' => 0]);
        File::deleteDirectory($packageRoot);
    }

    public function test_scorm_launch_fails_closed_for_conflicting_inactive_and_deleted_student_profiles(): void
    {
        [$student, , , $packageId, $packageRoot] = $this->setupRuntime();
        $this->activatePlugins(['branch-management', 'student-management']);

        $branchA = Branch::query()->create([
            'code' => 'SCORM-A-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'SCORM Branch A',
            'is_active' => true,
        ]);
        $branchB = Branch::query()->create([
            'code' => 'SCORM-B-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'SCORM Branch B',
            'is_active' => true,
        ]);

        DB::table('users')->where('id', $student->id)->update(['branch_id' => $branchA->id]);
        Student::query()->create([
            'user_id' => $student->id,
            'branch_id' => $branchB->id,
            'student_code' => 'SCORM-ST-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertForbidden();
        $this->assertDatabaseCount('scorm_attempts', 0);

        DB::table('users')->where('id', $student->id)->update(['branch_id' => $branchB->id]);
        DB::table('students')->where('user_id', $student->id)->update(['status' => 'inactive']);
        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertForbidden();
        $this->assertDatabaseCount('scorm_attempts', 0);

        DB::table('students')->where('user_id', $student->id)->update([
            'status' => 'active',
            'deleted_at' => now(),
        ]);
        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertForbidden();
        $this->assertDatabaseCount('scorm_attempts', 0);

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
            'path' => 'index.html',
        ]))->assertNotFound();

        DB::table('scorm_packages')->where('id', $packageId)->update(['status' => 'archived']);
        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertNotFound();

        DB::table('scorm_packages')->where('id', $packageId)->update(['status' => 'published']);
        DB::table('users')->where('id', $student->id)->update(['is_active' => false]);
        $this->actingAs($student)->get(route('plugins.scorm.launch', $packageId))->assertForbidden();
        File::deleteDirectory($packageRoot);
    }


    public function test_student_management_requires_an_active_student_profile_for_scorm_launch(): void
    {
        [$student, $courseId, $enrollmentId, $packageId] = $this->setupRuntime();
        $this->activatePlugins(['student-management']);

        $this->actingAs($student)
            ->get(route('plugins.scorm.launch', $packageId))
            ->assertForbidden();

        $this->assertDatabaseMissing('scorm_attempts', [
            'user_id' => $student->id,
            'scorm_package_id' => $packageId,
        ]);
    }

    public function test_scorm_fails_closed_when_student_profile_storage_is_missing(): void
    {
        [$student, $courseId, $enrollmentId, $packageId] = $this->setupRuntime();
        $this->activatePlugins(['student-management']);
        Schema::dropIfExists('students');

        $this->actingAs($student)
            ->get(route('plugins.scorm.launch', $packageId))
            ->assertStatus(503);

        $this->assertDatabaseMissing('scorm_attempts', [
            'user_id' => $student->id,
            'scorm_package_id' => $packageId,
        ]);
    }

}
