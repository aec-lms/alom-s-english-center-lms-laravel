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
            'recording' => ['required', 'file', 'mimes:webm,ogg,wav,mp3,m4a,mp4', 'max:10240'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:120'],
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
                    'duration_seconds' => (int) $data['duration_seconds'],
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
            ->get(['id', 'entry_id', 'status', 'score', 'feedback', 'duration_seconds', 'created_at', 'reviewed_at']);

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

        DB::transaction(function () use ($recording): void {
            $this->privateDisk()->delete($recording->storage_path);
            $recording->delete();
        });

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

        DB::transaction(function () use ($request, $recording, $data): void {
            $recording->update([
                'status' => 'reviewed',
                'reviewer_user_id' => (string) $request->user()->getAuthIdentifier(),
                'score' => $data['score'],
                'feedback' => $data['feedback'],
                'reviewed_at' => now(),
            ]);
            PhoneticAuditEvent::query()->create([
                'user_id' => $request->user()->getAuthIdentifier(),
                'action' => 'phonetics.recording.reviewed',
                'entity_type' => 'speaking_recording',
                'entity_id' => (string) $recording->id,
                'ip_address' => $request->ip(),
                'metadata' => ['student_id' => $recording->user_id, 'score' => $data['score']],
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
        object $course
    ): void {
        $reviewer = $request->user();
        abort_unless($reviewer, 401);

        if ($this->isGlobalPhoneticsAdmin($reviewer)) {
            return;
        }

        $reviewerBranchId = data_get($reviewer, 'branch_id');
        abort_unless($reviewerBranchId !== null, 403, 'A branch assignment is required to review student recordings.');

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

        $student = DB::table($userTable)
            ->where($userIdColumn, $recording->user_id)
            ->first(['branch_id']);
        abort_unless(
            $student && $student->branch_id !== null
                && (string) $student->branch_id === (string) $reviewerBranchId,
            404,
            'Recording not found in your branch.'
        );

        $courseMap = config('phonetics.catalog.courses', []);
        $courseBranchColumn = $courseMap['branch_id'] ?? 'branch_id';
        if (is_string($courseBranchColumn) && Schema::hasTable($courseMap['table'] ?? '')) {
            if (Schema::hasColumn($courseMap['table'], $courseBranchColumn)) {
                $courseBranchId = data_get($course, $courseBranchColumn);
                abort_unless(
                    $courseBranchId === null || (string) $courseBranchId === (string) $reviewerBranchId,
                    404,
                    'Course is not available to your branch.'
                );
            }
        }
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
