<?php

declare(strict_types=1);

namespace Plugins\Modules\Scorm\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Plugins\Modules\Scorm\Services\Scorm12Runtime;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ScormRuntimeController extends Controller
{
    public function launch(Request $request, int $package): View
    {
        $actor = $request->user();
        abort_unless($actor && $actor->is_active && $actor->hasRole('student'), 403, 'An active student account is required.');
        abort_unless(Schema::hasTable('courses') && Schema::hasTable('enrollments'), 503, 'Course Management is required to launch SCORM content.');

        $contentOrigin = rtrim((string) config('scorm.content_origin', ''), '/');
        $contentHost = parse_url($contentOrigin, PHP_URL_HOST);
        $appOrigin = rtrim((string) config('app.url'), '/');
        $appHost = parse_url($appOrigin, PHP_URL_HOST);
        abort_unless(is_string($contentHost) && $contentHost !== '' && $contentHost !== $appHost, 503,
            'Configure SCORM_CONTENT_ORIGIN on a separate content subdomain before launching SCORM packages.');

        $packageRecord = DB::table('scorm_packages')->where('id', $package)->where('status', 'published')->first();
        abort_unless($packageRecord, 404, 'Published SCORM package not found.');

        $enrollment = $this->eligibleEnrollment($actor, $packageRecord);
        abort_unless($enrollment, 403, 'An active or completed enrollment with settled payment is required.');

        [$attempt, $token] = DB::transaction(function () use ($actor, $packageRecord, $enrollment): array {
            $lockedUser = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($lockedUser && (bool) $lockedUser->is_active, 403, 'Student account is inactive.');

            $studentRole = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $actor->id)
                ->where('roles.slug', 'student')
                ->lockForUpdate()
                ->exists();
            abort_unless($studentRole, 403, 'Current student role is required.');

            $lockedPackage = DB::table('scorm_packages')->where('id', $packageRecord->id)->lockForUpdate()->first();
            abort_unless($lockedPackage && $lockedPackage->status === 'published', 404, 'SCORM package is no longer published.');

            $lockedEnrollment = DB::table('enrollments')->where('id', $enrollment->id)->lockForUpdate()->first();
            abort_unless($lockedEnrollment && in_array($lockedEnrollment->status, ['active', 'completed'], true), 403, 'Course enrollment is no longer active.');

            $latest = DB::table('scorm_attempts')
                ->where('scorm_package_id', $lockedPackage->id)
                ->where('user_id', $actor->id)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if ($latest && in_array($latest->completion_state, ['in_progress', 'suspended'], true)) {
                $attemptId = $latest->id;
            } else {
                $attemptId = DB::table('scorm_attempts')->insertGetId([
                    'scorm_package_id' => $lockedPackage->id,
                    'user_id' => $actor->id,
                    'enrollment_id' => $lockedEnrollment->id,
                    'attempt_number' => (int) ($latest->attempt_number ?? 0) + 1,
                    'state_version' => 0,
                    'lesson_status' => 'not attempted',
                    'entry_mode' => 'ab-initio',
                    'lesson_location' => '',
                    'suspend_data' => null,
                    'score_raw' => null,
                    'score_min' => null,
                    'score_max' => null,
                    'total_time_seconds' => 0,
                    'session_time_seconds' => 0,
                    'cmi_data' => '{}',
                    'completion_state' => 'in_progress',
                    'started_at' => now(),
                    'last_commit_at' => null,
                    'finished_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $token = bin2hex(random_bytes(32));
            DB::table('scorm_attempt_sessions')->insert([
                'scorm_attempt_id' => $attemptId,
                'token_sha256' => hash('sha256', $token),
                'expires_at' => now()->addMinutes((int) config('scorm.launch_ttl_minutes', 240)),
                'last_session_seconds' => 0,
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [DB::table('scorm_attempts')->where('id', $attemptId)->first(), $token];
        });

        $playerUrl = URL::temporarySignedRoute(
            'plugins.scorm.content.player',
            now()->addMinutes((int) config('scorm.launch_ttl_minutes', 240)),
            ['attempt' => $attempt->id, 'token' => $token]
        );

        return view('scorm::launch', [
            'title' => $packageRecord->title,
            'playerUrl' => $playerUrl,
            'attemptId' => $attempt->id,
        ]);
    }

    public function contentPlayer(Request $request, int $attempt, string $token): Response
    {
        $session = $this->validSession($attempt, $token);
        $record = DB::table('scorm_attempts')->where('id', $attempt)->first();
        $package = DB::table('scorm_packages')->where('id', $record->scorm_package_id)->first();
        abort_unless($package, 404);

        $launchUrl = route('plugins.scorm.content.file', [
            'attempt' => $attempt,
            'token' => $token,
            'path' => $package->launch_file,
        ]);
        $user = DB::table('users')->where('id', $record->user_id)->first();
        $appOrigin = rtrim((string) config('app.url'), '/');
        $runtimeUrl = $appOrigin . '/api/scorm-runtime/commit';
        $savedData = json_decode((string) $record->cmi_data, true);
        if (!is_array($savedData)) {
            $savedData = [];
        }

        $response = response()->view('scorm::content-player', [
            'title' => $package->title,
            'launchUrl' => $launchUrl,
            'runtimeUrl' => $runtimeUrl,
            'attemptId' => $attempt,
            'token' => $token,
            'stateVersion' => (int) $record->state_version,
            'studentId' => (string) $record->user_id,
            'studentName' => (string) ($user->name ?? 'Learner'),
            'entryMode' => $record->entry_mode,
            'totalTime' => $this->formatScormTime((int) $record->total_time_seconds),
            'savedData' => $savedData,
        ]);

        return $response
            ->header('Content-Security-Policy', $this->contentSecurityPolicy())
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'private, no-store');
    }

    public function contentFile(Request $request, int $attempt, string $token, ?string $path = null): Response
    {
        $session = $this->validSession($attempt, $token);
        abort_unless(is_string($path) && $path !== '' && !$this->unsafeRelativePath($path), 404);

        $record = DB::table('scorm_attempts')->where('id', $attempt)->first();
        $package = DB::table('scorm_packages')->where('id', $record->scorm_package_id)->first();
        abort_unless($package, 404);

        $rootCandidate = storage_path('app/scorm-packages/' . $package->storage_directory);
        abort_if(is_link($rootCandidate), 404);
        $root = realpath($rootCandidate);
        $targetCandidate = $root !== false ? $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) : '';
        $target = $targetCandidate !== '' ? realpath($targetCandidate) : false;
        abort_unless($root !== false && $target !== false && is_file($target) && !is_link($target)
            && str_starts_with($target, $root . DIRECTORY_SEPARATOR), 404);

        return response()->file($target, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
        ]);
    }

    public function preflight(Request $request): Response
    {
        abort_unless($this->isAllowedRuntimeOrigin($request), 403);

        return response('', 204)->withHeaders($this->corsHeaders($request));
    }

    public function commit(Request $request): JsonResponse
    {
        if (!$this->isAllowedRuntimeOrigin($request)) {
            return response()->json(['message' => 'SCORM runtime origin is not allowed.'], 403);
        }

        $validated = $request->validate([
            'attempt_id' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'size:64'],
            'version' => ['required', 'integer', 'min:0'],
            'data' => ['required', 'array', 'max:300'],
            'finish' => ['sometimes', 'boolean'],
        ]);
        abort_unless(ctype_xdigit($validated['token']), 422, 'Invalid runtime token.');

        try {
            $result = DB::transaction(function () use ($validated): array {
                $session = DB::table('scorm_attempt_sessions')
                    ->where('scorm_attempt_id', $validated['attempt_id'])
                    ->where('token_sha256', hash('sha256', $validated['token']))
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->first();
                abort_unless($session, 403, 'SCORM launch session expired or is invalid.');

                $attempt = DB::table('scorm_attempts')->where('id', $validated['attempt_id'])->lockForUpdate()->first();
                abort_unless($attempt && in_array($attempt->completion_state, ['in_progress', 'suspended'], true), 409, 'SCORM attempt is no longer writable.');
                abort_unless((int) $attempt->state_version === (int) $validated['version'], 409, 'This SCORM attempt changed in another tab. Reload the learner session before continuing.');

                $user = DB::table('users')->where('id', $attempt->user_id)->first();
                abort_unless($user && (bool) $user->is_active, 403, 'Student account is inactive.');

                $savedData = json_decode((string) $attempt->cmi_data, true);
                $runtime = new Scorm12Runtime(
                    (string) $user->id,
                    (string) $user->name,
                    '',
                    (string) $attempt->entry_mode,
                    'normal',
                    $this->formatScormTime((int) $attempt->total_time_seconds),
                    is_array($savedData) ? $savedData : []
                );
                abort_unless($runtime->initialize() === 'true', 409, 'SCORM runtime could not initialize.');

                foreach ($validated['data'] as $element => $value) {
                    if (!is_string($element) || !is_string($value) || $runtime->setValue($element, $value) !== 'true') {
                        throw new RuntimeException('SCORM runtime rejected a data-model value: ' . (is_string($element) ? $element : 'invalid-key') . ' (error ' . $runtime->getLastError() . ').');
                    }
                }

                $data = $runtime->exportData();
                $sessionSeconds = $this->parseScormTime((string) ($data['cmi.core.session_time'] ?? '0000:00:00.00'));
                $previousSessionSeconds = (int) ($session->last_session_seconds ?? 0);
                abort_if($sessionSeconds < $previousSessionSeconds, 422, 'SCORM session time cannot move backwards.');
                $totalSeconds = (int) $attempt->total_time_seconds + ($sessionSeconds - $previousSessionSeconds);
                $finish = (bool) ($validated['finish'] ?? false);
                if ($finish) {
                    abort_unless($runtime->finish() === 'true', 409, 'SCORM runtime could not finish.');
                }

                $lessonStatus = (string) ($data['cmi.core.lesson_status'] ?? 'not attempted');
                $terminalStatus = in_array($lessonStatus, ['passed', 'completed', 'failed', 'browsed'], true);
                $completionState = $finish ? ($terminalStatus ? 'completed' : 'suspended') : 'in_progress';
                $version = (int) $attempt->state_version + 1;

                DB::table('scorm_attempts')->where('id', $attempt->id)->update([
                    'state_version' => $version,
                    'lesson_status' => $lessonStatus,
                    'lesson_location' => (string) ($data['cmi.core.lesson_location'] ?? ''),
                    'suspend_data' => (string) ($data['cmi.suspend_data'] ?? ''),
                    'score_raw' => is_numeric($data['cmi.core.score.raw'] ?? null) ? $data['cmi.core.score.raw'] : null,
                    'total_time_seconds' => $totalSeconds,
                    'session_time_seconds' => $sessionSeconds,
                    'cmi_data' => json_encode($data, JSON_THROW_ON_ERROR),
                    'completion_state' => $completionState,
                    'last_commit_at' => now(),
                    'finished_at' => $completionState === 'completed' ? now() : null,
                    'updated_at' => now(),
                ]);
                DB::table('scorm_attempt_sessions')->where('id', $session->id)->update([
                    'last_session_seconds' => $sessionSeconds,
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['version' => $version, 'completion_state' => $completionState, 'lesson_status' => $lessonStatus];
            });

            return response()->json(['ok' => true] + $result)->withHeaders($this->corsHeaders($request));
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422)
                ->withHeaders($this->corsHeaders($request));
        }
    }

    private function eligibleEnrollment(object $actor, object $package): ?object
    {
        $query = DB::table('enrollments as e')
            ->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.user_id', $actor->id)
            ->where('e.course_id', $package->course_id)
            ->whereIn('e.status', ['active', 'completed']);
        if (Schema::hasColumn('courses', 'deleted_at')) {
            $query->whereNull('c.deleted_at');
        }
        $enrollment = $query->first([
            'e.id', 'e.branch_id', 'e.status', 'c.branch_id as course_branch_id',
            'c.price as course_price', 'c.status as course_status',
            Schema::hasColumn('enrollments', 'invoice_id') ? 'e.invoice_id' : DB::raw('NULL as invoice_id'),
        ]);
        if (!$enrollment || $enrollment->course_status !== 'published') {
            return null;
        }

        if ($actor->branch_id !== null && $enrollment->course_branch_id !== null
            && (int) $actor->branch_id !== (int) $enrollment->course_branch_id) {
            return null;
        }
        if ($enrollment->branch_id !== null && $actor->branch_id !== null
            && (int) $enrollment->branch_id !== (int) $actor->branch_id) {
            return null;
        }
        if ($package->branch_id !== null
            && (int) $package->branch_id !== (int) ($actor->branch_id ?? $enrollment->branch_id ?? 0)) {
            return null;
        }

        if ((float) $enrollment->course_price > 0) {
            if (!Schema::hasTable('fee_invoices') || !$enrollment->invoice_id) {
                return null;
            }
            $invoice = DB::table('fee_invoices')->where('id', $enrollment->invoice_id)->first();
            if (!$invoice || $invoice->status !== 'paid'
                || (float) $invoice->paid_amount + 0.01 < (float) $invoice->net_amount) {
                return null;
            }
        }

        return $enrollment;
    }

    private function validSession(int $attemptId, string $token): object
    {
        abort_unless(ctype_xdigit($token) && strlen($token) === 64, 403);
        $session = DB::table('scorm_attempt_sessions')
            ->where('scorm_attempt_id', $attemptId)
            ->where('token_sha256', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
        abort_unless($session, 403, 'SCORM launch session expired or is invalid.');

        return $session;
    }

    private function unsafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, chr(0)) || str_contains($path, '\')
            || str_contains($path, '%') || str_starts_with($path, '/')) {
            return true;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function isAllowedRuntimeOrigin(Request $request): bool
    {
        return hash_equals(rtrim((string) config('scorm.content_origin', ''), '/'), rtrim((string) $request->headers->get('Origin', ''), '/'));
    }

    private function corsHeaders(Request $request): array
    {
        return [
            'Access-Control-Allow-Origin' => rtrim((string) $request->headers->get('Origin', ''), '/'),
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }

    private function contentSecurityPolicy(): string
    {
        $appOrigin = rtrim((string) config('app.url'), '/');

        return "default-src 'self' data: blob: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data: https:; connect-src 'self' {$appOrigin} https:; frame-src 'self' https:; frame-ancestors 'self' {$appOrigin}; object-src 'none'; base-uri 'self'";
    }

    private function parseScormTime(string $value): int
    {
        if (preg_match('/^(\d{4}):([0-5]\d):([0-5]\d)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new RuntimeException('SCORM session time has an invalid format.');
        }

        return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
    }

    private function formatScormTime(int $seconds): string
    {
        $hours = intdiv(max(0, $seconds), 3600);
        $minutes = intdiv(max(0, $seconds) % 3600, 60);
        $remaining = max(0, $seconds) % 60;

        return sprintf('%04d:%02d:%02d.00', $hours, $minutes, $remaining);
    }
}
