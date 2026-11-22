<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Core\Plugins\PluginManager;
use Plugins\Modules\StudentTracking\Services\StudentActivityTracker;
use Plugins\Modules\LearningPaths\Services\LearningPathAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use RuntimeException;

class LessonContentProtectionController extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'video/mp4',
        'video/webm',
        'audio/mpeg',
        'audio/mp4',
        'application/pdf',
    ];

    public function upload(Request $request, int $lesson): RedirectResponse
    {
        $validated = $request->validate([
            'media' => ['required', 'file', 'max:512000', 'mimes:mp4,webm,mp3,m4a,pdf'],
        ]);

        $lessonRow = $this->managedLesson($request, $lesson);

        $file = $request->file('media');
        $mime = (string) $file->getMimeType();
        abort_unless(in_array($mime, self::ALLOWED_MIME_TYPES, true), 422, 'Unsupported lesson media type.');

        $root = $this->privateRoot();
        $directory = $root . DIRECTORY_SEPARATOR . 'course-media' . DIRECTORY_SEPARATOR . (int) $lessonRow->course_id;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create private lesson media directory.');
        }
        $resolvedDirectory = realpath($directory);
        abort_unless($resolvedDirectory && str_starts_with($resolvedDirectory, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR), 503, 'Lesson media directory must remain inside private storage.');
        $directory = $resolvedDirectory;

        $extension = strtolower((string) $file->guessExtension());
        abort_unless(in_array($extension, ['mp4', 'webm', 'mp3', 'm4a', 'pdf'], true), 422, 'Unsupported protected lesson media extension.');
        $filename = Str::uuid()->toString() . '.' . $extension;
        $file->move($directory, $filename);
        $storedPath = $directory . DIRECTORY_SEPARATOR . $filename;
        @chmod($storedPath, 0600);

        DB::table('course_lessons')->where('id', $lesson)->update([
            'media_path' => 'course-media/' . (int) $lessonRow->course_id . '/' . $filename,
            'media_mime' => $mime,
            'protection_enabled' => true,
            'is_downloadable' => false,
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Protected media uploaded for lesson #' . $lesson . '. Downloads are disabled by default.');
    }

    public function updatePolicy(Request $request, int $lesson): RedirectResponse
    {
        $validated = $request->validate([
            'protection_enabled' => ['required', 'boolean'],
            'is_downloadable' => ['required', 'boolean'],
        ]);

        $this->managedLesson($request, $lesson);

        DB::table('course_lessons')->where('id', $lesson)->update([
            'protection_enabled' => (bool) $validated['protection_enabled'],
            'is_downloadable' => (bool) $validated['is_downloadable'],
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Lesson protection policy updated.');
    }

    public function adminIndex(Request $request): \Illuminate\View\View
    {
        $branchId = $this->managedBranchId($request);
        $courses = DB::table('courses')
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('title')->get();
        foreach ($courses as $course) {
            $course->lessons = DB::table('course_lessons')
                ->where('course_id', $course->id)
                ->orderBy('sort_order')
                ->get(['id', 'title', 'sort_order', 'media_path', 'media_mime', 'protection_enabled', 'is_downloadable']);
        }

        return view('course-management::content-protection', compact('courses'));
    }

    public function showCourse(Request $request, int $course): \Illuminate\View\View
    {
        $courseRow = DB::table('courses')->where('id', $course)->where('status', 'published')->first();
        abort_unless($courseRow, 404);

        $enrollment = DB::table('enrollments')
            ->where('user_id', $request->user()->id)
            ->where('course_id', $course)
            ->where('status', 'active')
            ->first();
        $this->assertStudentCourseAccess(
            $request,
            $courseRow->branch_id !== null ? (int) $courseRow->branch_id : null,
            $enrollment
        );
        if (app(PluginManager::class)->isEnabled('learning-paths')) {
            app(LearningPathAccess::class)->assertCourseUnlocked((int) $request->user()->id, $course);
        }

        $lessons = DB::table('course_lessons')
            ->where('course_id', $course)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'sort_order', 'media_mime', 'protection_enabled', 'is_downloadable', 'media_path']);

        return view('course-management::learning-player', [
            'course' => $courseRow,
            'lessons' => $lessons,
        ]);
    }

    public function issueAccess(Request $request, int $lesson): JsonResponse
    {
        $lessonRow = $this->authorizedLesson($request, $lesson);
        abort_unless((bool) $lessonRow->protection_enabled, 403, 'Protected media access is disabled for this lesson.');

        $expiresAt = now()->addMinutes(5);
        $url = URL::temporarySignedRoute(
            'plugins.lesson-media.stream',
            $expiresAt,
            ['lesson' => $lesson, 'user' => $request->user()->id]
        );

        $user = $request->user();
        if ($user->hasRole('student') && app(PluginManager::class)->isEnabled('student-tracking')) {
            $courseTitle = DB::table('courses')->where('id', $lessonRow->course_id)->value('title');
            app(StudentActivityTracker::class)->record((int) $user->id, 'lesson_started', 'lesson', $lesson, null, [
                'lesson_title' => $lessonRow->title ?? 'Lesson ' . $lesson,
                'course_title' => $courseTitle ?: null,
                'source' => 'protected-media-access',
            ]);
        }
        $watermark = trim((string) $user->email) . ' | ID ' . $user->id . ' | ' . now()->format('Y-m-d H:i:s');

        return response()->json([
            'url' => $url,
            'download_url' => (bool) $lessonRow->is_downloadable ? URL::temporarySignedRoute('plugins.lesson-media.download', $expiresAt, ['lesson' => $lesson, 'user' => $request->user()->id]) : null,
            'expires_at' => $expiresAt->toIso8601String(),
            'watermark' => $watermark,
            'download_allowed' => (bool) $lessonRow->is_downloadable,
            'notice' => 'Temporary access. Do not share this link.',
        ])->header('Cache-Control', 'no-store, private');
    }

    public function download(Request $request, int $lesson, int $user): BinaryFileResponse
    {
        abort_unless((int) $request->user()->id === $user, 403, 'This download link belongs to another account.');
        $lessonRow = $this->authorizedLesson($request, $lesson);
        abort_unless((bool) $lessonRow->protection_enabled && (bool) $lessonRow->is_downloadable, 403, 'Downloads are disabled for this lesson.');

        $path = $this->resolveMediaPath((string) ($lessonRow->media_path ?? ''));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        abort_unless(in_array($mime, self::ALLOWED_MIME_TYPES, true), 415, 'Unsupported media format.');
        return response()->download($path, 'lesson-' . $lesson . '.' . pathinfo($path, PATHINFO_EXTENSION), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    public function stream(Request $request, int $lesson, int $user): BinaryFileResponse
    {
        abort_unless((int) $request->user()->id === $user, 403, 'This media link belongs to another account.');
        $lessonRow = $this->authorizedLesson($request, $lesson);
        abort_unless((bool) $lessonRow->protection_enabled, 403, 'Protected media access is disabled for this lesson.');

        $path = $this->resolveMediaPath((string) ($lessonRow->media_path ?? ''));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        abort_unless(in_array($mime, self::ALLOWED_MIME_TYPES, true), 415, 'Unsupported media format.');

        $filename = 'lesson-' . $lesson . '.' . pathinfo($path, PATHINFO_EXTENSION);
        $response = response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);

        return $response;
    }

    private function managedBranchId(Request $request): ?int
    {
        $actor = $request->user();
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return null;
        }

        abort_unless(
            $actor->branch_id !== null
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')
                ->where('id', $actor->branch_id)
                ->where('is_active', true)
                ->exists(),
            403,
            'Branch manager access is disabled because the assigned branch is inactive.'
        );

        return (int) $actor->branch_id;
    }

    private function managedLesson(Request $request, int $lesson): object
    {
        $row = DB::table('course_lessons')
            ->join('courses', 'courses.id', '=', 'course_lessons.course_id')
            ->where('course_lessons.id', $lesson)
            ->select('course_lessons.*', 'courses.branch_id as course_branch_id')
            ->first();
        abort_unless($row, 404);

        $branchId = $this->managedBranchId($request);
        if ($branchId !== null) {
            abort_unless((int) $row->course_branch_id === $branchId, 403, 'Branch isolation violation.');
        }

        return $row;
    }

    private function authorizedLesson(Request $request, int $lesson): object
    {
        $row = DB::table('course_lessons')
            ->join('courses', 'courses.id', '=', 'course_lessons.course_id')
            ->where('course_lessons.id', $lesson)
            ->where('courses.status', 'published')
            ->select('course_lessons.*', 'courses.branch_id as course_branch_id')
            ->first();
        abort_unless($row && !empty($row->media_path), 404, 'Published lesson media not found.');

        $enrollment = DB::table('enrollments')
            ->where('user_id', $request->user()->id)
            ->where('course_id', $row->course_id)
            ->where('status', 'active')
            ->first();

        $this->assertStudentCourseAccess(
            $request,
            $row->course_branch_id !== null ? (int) $row->course_branch_id : null,
            $enrollment
        );
        if (app(PluginManager::class)->isEnabled('learning-paths')) {
            app(LearningPathAccess::class)->assertCourseUnlocked((int) $request->user()->id, (int) $row->course_id);
        }
        return $row;
    }

    private function assertStudentCourseAccess(Request $request, ?int $courseBranchId, ?object $enrollment): void
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403, 'An active student account is required to view this course.');
        abort_unless($enrollment, 403, 'An active enrollment is required to view this course.');

        $account = DB::table('users')->where('id', $student->id)->first();
        abort_unless($account, 403, 'Student account is unavailable.');
        if (Schema::hasColumn('users', 'is_active')) {
            abort_unless((bool) $account->is_active, 403, 'This student account is inactive.');
        }

        $studentBranchId = $this->studentBranchId($student, $account);
        if ($courseBranchId !== null) {
            abort_unless(
                $studentBranchId !== null && $studentBranchId === $courseBranchId,
                403,
                'Course is outside your current student branch.'
            );
        }

        if ($enrollment->branch_id !== null) {
            abort_unless(
                $studentBranchId !== null && $studentBranchId === (int) $enrollment->branch_id,
                403,
                'Enrollment is outside your current student branch.'
            );
        }
    }

    private function studentBranchId(object $student, object $account): ?int
    {
        if (app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'user_id')) {
            $profileQuery = DB::table('students')->where('user_id', $student->id);
            if (Schema::hasColumn('students', 'deleted_at')) {
                $profileQuery->whereNull('deleted_at');
            }
            $profile = $profileQuery->first();
            abort_unless($profile, 403, 'An active student profile is required to view this course.');
            if (Schema::hasColumn('students', 'status')) {
                abort_unless($profile->status === 'active', 403, 'This student profile is inactive.');
            }
            $profileBranchId = Schema::hasColumn('students', 'branch_id') && $profile->branch_id !== null
                ? (int) $profile->branch_id
                : null;
        } else {
            $profileBranchId = null;
        }

        $accountBranchId = Schema::hasColumn('users', 'branch_id') && $account->branch_id !== null
            ? (int) $account->branch_id
            : null;

        // A conflicting account/profile assignment is ambiguous. Never choose one
        // identity opportunistically: branch-scoped course access must fail closed.
        if ($profileBranchId !== null && $accountBranchId !== null) {
            abort_unless(
                $profileBranchId === $accountBranchId,
                403,
                'Your account and student profile branch assignments do not match.'
            );
        }

        return $profileBranchId ?? $accountBranchId;
    }

    private function privateRoot(): string
    {
        $configured = (string) config('lms.private_storage_path');
        abort_if($configured === '', 503, 'Private storage is not configured.');

        $root = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured);
        $normalized = rtrim($root, DIRECTORY_SEPARATOR);

        if (!is_dir($normalized) && !mkdir($normalized, 0700, true) && !is_dir($normalized)) {
            throw new RuntimeException('Unable to initialize private storage.');
        }

        $resolvedRoot = realpath($normalized) ?: $normalized;
        $public = realpath(public_path());
        if ($public !== false && ($resolvedRoot === $public || str_starts_with($resolvedRoot . DIRECTORY_SEPARATOR, rtrim($public, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            abort(503, 'Private storage must be outside the public web root.');
        }

        return $resolvedRoot;
    }

    private function resolveMediaPath(string $relativePath): string
    {
        abort_if($relativePath === '' || str_contains($relativePath, "\0") || str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:/', $relativePath), 404);
        $segments = explode('/', str_replace(chr(92), '/', $relativePath));
        abort_if(in_array('..', $segments, true) || in_array('.', $segments, true), 404);

        $root = $this->privateRoot();
        $path = realpath($root . DIRECTORY_SEPARATOR . $relativePath);
        abort_unless($path && is_file($path), 404, 'Lesson media file is unavailable.');
        abort_unless(str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR), 404, 'Media path is outside private storage.');

        return $path;
    }
}
