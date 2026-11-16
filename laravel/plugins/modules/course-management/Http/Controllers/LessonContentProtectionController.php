<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
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

    public function upload(Request $request, int $lesson): JsonResponse
    {
        $validated = $request->validate([
            'media' => ['required', 'file', 'max:512000', 'mimes:mp4,webm,mp3,m4a,pdf'],
        ]);

        $lessonRow = DB::table('course_lessons')->where('id', $lesson)->first();
        abort_unless($lessonRow, 404);

        $file = $request->file('media');
        $mime = (string) $file->getMimeType();
        abort_unless(in_array($mime, self::ALLOWED_MIME_TYPES, true), 422, 'Unsupported lesson media type.');

        $root = $this->privateRoot();
        $directory = $root . DIRECTORY_SEPARATOR . 'course-media' . DIRECTORY_SEPARATOR . (int) $lessonRow->course_id;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create private lesson media directory.');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
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

        return response()->json([
            'message' => 'Lesson media stored in private storage with protection enabled.',
            'lesson_id' => $lesson,
            'mime_type' => $mime,
            'download_allowed' => false,
        ], 201);
    }

    public function updatePolicy(Request $request, int $lesson): JsonResponse
    {
        $validated = $request->validate([
            'protection_enabled' => ['required', 'boolean'],
            'is_downloadable' => ['required', 'boolean'],
        ]);

        $exists = DB::table('course_lessons')->where('id', $lesson)->exists();
        abort_unless($exists, 404);

        DB::table('course_lessons')->where('id', $lesson)->update([
            'protection_enabled' => (bool) $validated['protection_enabled'],
            'is_downloadable' => (bool) $validated['is_downloadable'],
            'updated_at' => now(),
        ]);

        return response()->json([
            'lesson_id' => $lesson,
            'protection_enabled' => (bool) $validated['protection_enabled'],
            'download_allowed' => (bool) $validated['is_downloadable'],
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
        $watermark = trim((string) $user->email) . ' | ID ' . $user->id . ' | ' . now()->format('Y-m-d H:i:s');

        return response()->json([
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
            'watermark' => $watermark,
            'download_allowed' => (bool) $lessonRow->is_downloadable,
            'notice' => 'Temporary access. Do not share this link.',
        ])->header('Cache-Control', 'no-store, private');
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

    private function authorizedLesson(Request $request, int $lesson): object
    {
        $row = DB::table('course_lessons')->where('id', $lesson)->first();
        abort_unless($row && !empty($row->media_path), 404, 'Lesson media not found.');

        $enrolled = DB::table('enrollments')
            ->where('user_id', $request->user()->id)
            ->where('course_id', $row->course_id)
            ->where('status', 'active')
            ->exists();

        abort_unless($enrolled, 403, 'An active enrollment is required to view this lesson.');
        return $row;
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
