<?php
declare(strict_types=1);

namespace Plugins\Modules\Phonetics\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Plugins\Modules\Phonetics\Models\PhoneticAuditEvent;
use Plugins\Modules\Phonetics\Models\PhoneticLearningAssignment;
use Plugins\Modules\Phonetics\Models\PhoneticSpeakingRecording;
use Plugins\Modules\Phonetics\Models\PhoneticSetting;
use Plugins\Modules\Phonetics\Services\CourseCatalog;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final class PhoneticsSpeakingRecordingController extends Controller
{
    public function __construct(private readonly CourseCatalog $catalog) {}

    public function store(Request $request, string $courseSlug)
    {
        $course = $this->catalog->course($courseSlug);
        $this->catalog->assertCourseAvailable($course);
        $this->catalog->assertDedicatedCourse($course);
        $this->catalog->assertAccess($request->user(), $this->catalog->courseId($course));
        abort_unless(PhoneticSetting::current()->enabled, 404);

        $data = $request->validate([
            'entry_id' => ['required', 'integer', 'exists:alom_english_center_phonetic_entries,id'],
            'recording' => ['required', 'file', 'mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/wav,audio/x-wav,audio/mpeg,audio/mp4,video/mp4', 'max:10240'],
            'reported_duration_seconds' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $entry = $this->assignedEntry($request, $course, (int) $data['entry_id']);
        $userId = (string) $request->user()->getAuthIdentifier();
        $userMap = config('phonetics.users', []);
        $userTable = $userMap['table'] ?? 'users';
        $userIdColumn = $userMap['id'] ?? 'id';
        abort_unless(
            is_string($userTable) && is_string($userIdColumn)
                && Schema::hasTable($userTable) && Schema::hasColumn($userTable, $userIdColumn),
            503,
            'The connected LMS user lock mapping is unavailable.'
        );

        $disk = $this->privateDisk();
        $directory = 'students/'.hash('sha256', $userId);
        $path = null;

        try {
            $recording = DB::transaction(function () use (
                $request, $course, $data, $entry, $userId, $userTable, $userIdColumn, $disk, $directory, &$path
            ): PhoneticSpeakingRecording {
                // Serialize per-student quota checks so parallel uploads cannot
                // bypass the ten-recordings-per-day limit.
                $lockedUser = DB::table($userTable)->where($userIdColumn, $userId)->lockForUpdate()->first();
                abort_unless($lockedUser, 404, 'The student account is no longer available.');

                $todayCount = PhoneticSpeakingRecording::query()
                    ->where('lms_driver', $this->catalog->driver())
                    ->where('user_id', $userId)
                    ->where('created_at', '>=', now()->startOfDay())
                    ->count();
                abort_if($todayCount >= 10, 429, 'Daily speaking recording limit reached. Please continue tomorrow.');

                $file = $data['recording'];
                $path = $disk->putFileAs($directory, $file, $file->hashName());
                abort_unless(is_string($path) && $path !== '', 500, 'The private recording could not be stored.');

                return PhoneticSpeakingRecording::query()->create([
                    'lms_driver' => $this->catalog->driver(),
                    'user_id' => $userId,
                    'entry_id' => $entry->id,
                    'course_ref' => $this->catalog->courseId($course),
                    'storage_path' => $path,
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'reported_duration_seconds' => (int) $data['reported_duration_seconds'],
                    'status' => 'pending',
                ]);
            });
        } catch (Throwable $exception) {
            if (is_string($path) && $path !== '') {
                try { $disk->delete($path); } catch (Throwable) {}
            }
            throw $exception;
        }

        return response()->json([
            'saved' => true,
            'recording_id' => $recording->id,
            'status' => $recording->status,
            'message' => 'Recording submitted privately for teacher review.',
        ], 201);
    }

    public function history(Request $request, string $courseSlug)
    {
        $course = $this->catalog->course($courseSlug);
        $this->catalog->assertCourseAvailable($course);
        $this->catalog->assertDedicatedCourse($course);
        $this->catalog->assertAccess($request->user(), $this->catalog->courseId($course));

        $items = PhoneticSpeakingRecording::query()
            ->with('word:id,word,category')
            ->where('lms_driver', $this->catalog->driver())
            ->where('course_ref', $this->catalog->courseId($course))
            ->where('user_id', (string) $request->user()->getAuthIdentifier())
            ->latest()
            ->limit(50)
            ->get(['id', 'entry_id', 'status', 'score', 'feedback', 'reported_duration_seconds', 'created_at', 'reviewed_at']);

        $items->each(function (PhoneticSpeakingRecording $item) use ($courseSlug): void {
            $item->setAttribute('audio_url', route('plugins.phonetics.learning.recordings.audio', [
                'courseSlug' => $courseSlug,
                'recording' => $item->id,
            ]));
            $item->setAttribute('delete_url', route('plugins.phonetics.learning.recordings.destroy', [
                'courseSlug' => $courseSlug,
                'recording' => $item->id,
            ]));
        });

        return response()->json(['recordings' => $items]);
    }

    public function stream(Request $request, string $courseSlug, PhoneticSpeakingRecording $recording)
    {
        $course = $this->catalog->course($courseSlug);
        $this->catalog->assertCourseAvailable($course);
        $this->catalog->assertDedicatedCourse($course);
        $this->catalog->assertAccess($request->user(), $this->catalog->courseId($course));
        abort_unless(
            $recording->lms_driver === $this->catalog->driver()
            && $recording->course_ref === $this->catalog->courseId($course)
            && hash_equals($recording->user_id, (string) $request->user()->getAuthIdentifier()),
            404
        );

        return $this->recordingFileResponse($recording);
    }

    public function adminStream(Request $request, PhoneticSpeakingRecording $recording)
    {
        abort_unless($recording->lms_driver === $this->catalog->driver(), 404);
        $course = $this->catalog->course($recording->course_ref);
        $this->catalog->assertCourseAvailable($course);
        $this->catalog->assertDedicatedCourse($course);
        $this->assertReviewerBranchAccess($request, $recording, $course);
        return $this->recordingFileResponse($recording);
    }

    private function recordingFileResponse(PhoneticSpeakingRecording $recording)
    {
        $disk = $this->privateDisk();
        abort_unless($disk->exists($recording->storage_path), 404);
        return response()->file($disk->path($recording->storage_path), [
            'Content-Type' => $recording->mime_type,
            'Content-Disposition' => 'inline; filename="phonetics-practice.'.strtolower(pathinfo($recording->storage_path, PATHINFO_EXTENSION)).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function destroy(Request $request, string $courseSlug, PhoneticSpeakingRecording $recording)
    {
        $course = $this->catalog->course($courseSlug);
        $this->catalog->assertCourseAvailable($course);
        $this->catalog->assertDedicatedCourse($course);
        $this->catalog->assertAccess($request->user(), $this->catalog->courseId($course));
        abort_unless(
            $recording->lms_driver === $this->catalog->driver()
            && $recording->course_ref === $this->catalog->courseId($course)
            && hash_equals($recording->user_id, (string) $request->user()->getAuthIdentifier()),
            404
        );
        abort_unless($recording->status === 'pending', 409, 'A reviewed recording cannot be deleted by the student.');

        $storagePath = DB::transaction(function () use ($recording): string {
            $locked = PhoneticSpeakingRecording::query()->whereKey($recording->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $locked->lms_driver === $this->catalog->driver()
                && $locked->course_ref === $recording->course_ref
                && $locked->user_id === $recording->user_id,
                404
            );
            abort_unless($locked->status === 'pending', 409, 'A reviewed recording cannot be deleted by the student.');

            $path = $locked->storage_path;
            PhoneticAuditEvent::query()->create([
                'user_id' => request()->user()->getAuthIdentifier(),
                'action' => 'phonetics.recording.deleted',
                'entity_type' => 'speaking_recording',
                'entity_id' => (string) $locked->id,
                'ip_address' => request()->ip(),
                'metadata' => ['student_id' => $locked->user_id, 'reason' => 'student_requested_pending_deletion'],
            ]);
            $locked->delete();

            return $path;
        });

        // Delete the private file only after the database transaction commits.
        $this->privateDisk()->delete($storagePath);

        return response()->json(['deleted' => true]);
    }

    public function reviewQueue(Request $request)
    {
        $query = PhoneticSpeakingRecording::query()
            ->with('word:id,word,category')
            ->where('lms_driver', $this->catalog->driver());

        if (!$this->isGlobalPhoneticsAdmin($request->user())) {
            $branchId = data_get($request->user(), 'branch_id');
            abort_unless($branchId !== null, 403, 'A branch assignment is required to review student recordings.');
            $userMap = config('phonetics.users', []);
            $userTable = $userMap['table'] ?? 'users';
            $userIdColumn = $userMap['id'] ?? 'id';
            abort_unless(
                is_string($userTable) && is_string($userIdColumn)
                    && Schema::hasTable($userTable)
                    && Schema::hasColumn($userTable, $userIdColumn)
                    && Schema::hasColumn($userTable, 'branch_id'),
                503,
                'The connected LMS branch mapping is unavailable for recording review.'
            );
            $query->whereIn('user_id', DB::table($userTable)
                ->select($userIdColumn)
                ->where('branch_id', $branchId));

            // Do not leak submission metadata for a same-branch student who
            // is enrolled in a course owned by another branch. Both sides of
            // the relationship must be scoped before pagination.
            $courseMap = config('phonetics.catalog.courses', []);
            $courseTable = $courseMap['table'] ?? 'courses';
            $courseIdColumn = $courseMap['id'] ?? 'id';
            $courseBranchColumn = $courseMap['branch_id'] ?? 'branch_id';
            abort_unless(
                is_string($courseTable) && is_string($courseIdColumn) && is_string($courseBranchColumn)
                    && Schema::hasTable($courseTable)
                    && Schema::hasColumn($courseTable, $courseIdColumn)
                    && Schema::hasColumn($courseTable, $courseBranchColumn),
                503,
                'The connected LMS course branch mapping is unavailable for recording review.'
            );

            $visibleCourseRefs = DB::table($courseTable)
                ->select($courseIdColumn)
                ->where(fn ($scope) => $scope
                    ->whereNull($courseBranchColumn)
                    ->orWhere($courseBranchColumn, $branchId));
            $query->whereIn('course_ref', $visibleCourseRefs);
        }

        return view('phonetics::admin.recordings', [
            'recordings' => $query->latest()->paginate(30),
        ]);
    }

    public function review(Request $request, PhoneticSpeakingRecording $recording)
    {
        abort_unless($recording->lms_driver === $this->catalog->driver(), 404);
        $course = $this->catalog->course($recording->course_ref);
        $this->catalog->assertCourseAvailable($course);
        $this->catalog->assertDedicatedCourse($course);
        $this->assertReviewerBranchAccess($request, $recording, $course);
        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $recording, $data, $course): void {
            // Serialize concurrent teacher reviews and retain an audit trail for
            // both the first review and any later correction.
            $locked = PhoneticSpeakingRecording::query()
                ->whereKey($recording->id)
                ->where('lms_driver', $this->catalog->driver())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check branch authorization under the same transaction and
            // lock the student's host-LMS row to close the branch-move race
            // between the initial request check and the review write.
            $this->assertReviewerBranchAccess($request, $locked, $course, true, true, true);
            $wasReviewed = $locked->status === 'reviewed';
            $previousScore = $locked->score;

            $locked->update([
                'status' => 'reviewed',
                'reviewer_user_id' => (string) $request->user()->getAuthIdentifier(),
                'score' => $data['score'],
                'feedback' => $data['feedback'],
                'reviewed_at' => now(),
            ]);
            PhoneticAuditEvent::query()->create([
                'user_id' => $request->user()->getAuthIdentifier(),
                'action' => $wasReviewed ? 'phonetics.recording.review.updated' : 'phonetics.recording.reviewed',
                'entity_type' => 'speaking_recording',
                'entity_id' => (string) $locked->id,
                'ip_address' => $request->ip(),
                'metadata' => [
                    'student_id' => $locked->user_id,
                    'score' => $data['score'],
                    'previous_score' => $previousScore,
                ],
            ]);
        });

        return back()->with('status', 'Pronunciation recording reviewed.');
    }

    private function isGlobalPhoneticsAdmin(?object $user): bool
    {
        if (!$user) {
            return false;
        }

        if ((bool) data_get($user, 'is_admin', false)) {
            return true;
        }

        $roles = config('phonetics.access.admin_roles', ['admin', 'super_admin']);
        if (method_exists($user, 'hasRole')) {
            foreach ($roles as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Global administrators may review all submissions. Branch-scoped staff
     * can only play/review recordings submitted by students in their branch.
     */
    private function assertReviewerBranchAccess(
        Request $request,
        PhoneticSpeakingRecording $recording,
        object $course,
        bool $lockStudent = false,
        bool $lockCourse = false,
        bool $lockReviewer = false
    ): void {
        $reviewer = $request->user();
        abort_unless($reviewer, 401);

        // A learner account must never grade its own submission, even when
        // that account also has staff permissions or an administrator role.
        abort_unless(
            !hash_equals((string) $recording->user_id, (string) $reviewer->getAuthIdentifier()),
            403,
            'You cannot review your own speaking recording.'
        );

        $userMap = config('phonetics.users', []);
        $userTable = $userMap['table'] ?? 'users';
        $userIdColumn = $userMap['id'] ?? 'id';
        abort_unless(
            is_string($userTable) && is_string($userIdColumn)
                && Schema::hasTable($userTable)
                && Schema::hasColumn($userTable, $userIdColumn)
                && Schema::hasColumn($userTable, 'branch_id'),
            503,
            'The connected LMS branch mapping is unavailable for recording review.'
        );

        $lockedUsers = null;
        $lockedReviewerRow = null;
        if ($lockReviewer) {
            abort_unless(Schema::hasColumn($userTable, 'is_active'), 503, 'Reviewer active-state mapping is unavailable.');
            // Lock both account rows in deterministic order. This closes the
            // reviewer/student branch reassignment race without acquiring
            // user-row locks in opposite orders across concurrent reviews.
            $userIds = array_values(array_unique([
                (string) $reviewer->getAuthIdentifier(),
                (string) $recording->user_id,
            ]));
            sort($userIds, SORT_STRING);
            $lockedUsers = DB::table($userTable)
                ->whereIn($userIdColumn, $userIds)
                ->orderBy($userIdColumn)
                ->lockForUpdate()
                ->get([$userIdColumn, 'branch_id', 'is_active']);
            $lockedReviewerRow = $lockedUsers->first(
                fn ($row) => (string) $row->{$userIdColumn} === (string) $reviewer->getAuthIdentifier()
            );
            abort_unless($lockedReviewerRow && (bool) $lockedReviewerRow->is_active, 403, 'Reviewer account is inactive.');

            // Re-resolve role/permission state after the account row lock. The
            // route middleware is an early gate; this is the final-write gate.
            $reviewer = $reviewer->newQuery()
                ->whereKey($reviewer->getAuthIdentifier())
                ->firstOrFail();
            abort_unless(
                method_exists($reviewer, 'hasPermission')
                    && $reviewer->hasPermission('phonetics.manage'),
                403,
                'Reviewer no longer has permission to review Phonetics recordings.'
            );
        }

        if ($this->isGlobalPhoneticsAdmin($reviewer)) {
            return;
        }

        $reviewerBranchId = $lockReviewer
            ? $lockedReviewerRow->branch_id
            : data_get($reviewer, 'branch_id');
        abort_unless($reviewerBranchId !== null, 403, 'A branch assignment is required to review student recordings.');

        $studentQuery = DB::table($userTable)->where($userIdColumn, $recording->user_id);
        if ($lockStudent && !$lockReviewer) {
            $studentQuery->lockForUpdate();
        }
        $student = $lockReviewer
            ? $lockedUsers->first(fn ($row) => (string) $row->{$userIdColumn} === (string) $recording->user_id)
            : $studentQuery->first(['branch_id']);
        abort_unless(
            $student && $student->branch_id !== null
                && (string) $student->branch_id === (string) $reviewerBranchId,
            404,
            'Recording not found in your branch.'
        );

        $courseMap = config('phonetics.catalog.courses', []);
        $courseTable = $courseMap['table'] ?? 'courses';
        $courseIdColumn = $courseMap['id'] ?? 'id';
        $courseBranchColumn = $courseMap['branch_id'] ?? 'branch_id';
        abort_unless(
            is_string($courseTable) && is_string($courseIdColumn) && is_string($courseBranchColumn)
                && Schema::hasTable($courseTable)
                && Schema::hasColumn($courseTable, $courseIdColumn)
                && Schema::hasColumn($courseTable, $courseBranchColumn),
            503,
            'The connected LMS course branch mapping is unavailable for recording review.'
        );
        $courseBranchId = data_get($course, $courseBranchColumn);
        if ($lockCourse) {
            // The course branch is part of the authorization boundary too.
            // Lock its host-LMS row in the review transaction so a concurrent
            // branch reassignment cannot race the final permission check.
            $courseRow = DB::table($courseTable)
                ->where($courseIdColumn, $this->catalog->courseId($course))
                ->lockForUpdate()
                ->first([$courseBranchColumn]);
            abort_unless($courseRow, 404, 'Course is no longer available.');
            $courseBranchId = $courseRow->{$courseBranchColumn};
        }
        abort_unless(
            $courseBranchId === null || (string) $courseBranchId === (string) $reviewerBranchId,
            404,
            'Course is not available to your branch.'
        );
    }

    private function assignedEntry(Request $request, object $course, int $entryId)
    {
        $courseRef = $this->catalog->courseId($course);
        $categoryRef = $this->catalog->courseCategoryId($course);
        $isPaid = $this->catalog->isPaid($course);
        $assignments = PhoneticLearningAssignment::query()
            ->where('lms_driver', $this->catalog->driver())
            ->where('word_id', $entryId)
            ->where(fn ($scope) => $scope->whereNull('course_ref')->orWhere('course_ref', $courseRef))
            ->where(fn ($scope) => $scope->whereNull('course_category_ref')->orWhere('course_category_ref', $categoryRef ?? '__none__'))
            ->whereIn('audience', ['both', $isPaid ? 'paid' : 'free'])
            ->where(fn ($dates) => $dates->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn ($dates) => $dates->whereNull('available_until')->orWhere('available_until', '>=', now()))
            ->get();

        foreach ($assignments as $assignment) {
            if ($assignment->batch_ref === null || $this->catalog->isBatchMember($request->user(), (string) $assignment->batch_ref)) {
                return $assignment->word()->where('is_active', true)->firstOrFail();
            }
        }

        throw new AccessDeniedHttpException('This vocabulary word is not assigned to your course or batch.');
    }

    private function privateDisk()
    {
        return Storage::build([
            'driver' => 'local',
            'root' => storage_path('app/private/phonetics-recordings'),
            'throw' => true,
        ]);
    }
}
