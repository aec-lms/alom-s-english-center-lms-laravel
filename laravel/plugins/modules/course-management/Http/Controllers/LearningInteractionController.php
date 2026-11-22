<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LearningInteractionController extends Controller
{
    public function lessonComments(Request $request, int $lesson): JsonResponse
    {
        $lessonRow = $this->authorizedLesson($request, $lesson);
        $comments = DB::table('course_lesson_comments as comments')
            ->join('users', 'users.id', '=', 'comments.user_id')
            ->where('comments.lesson_id', $lesson)
            ->where('comments.status', 'visible')
            ->whereNull('comments.deleted_at')
            ->orderByDesc('comments.is_pinned')
            ->orderBy('comments.created_at')
            ->limit(150)
            ->get([
                'comments.id', 'comments.parent_id', 'comments.body', 'comments.is_pinned',
                'comments.created_at', 'comments.updated_at', 'comments.user_id',
                'users.name as author_name',
            ]);

        return response()->json([
            'comments' => $comments,
            'can_moderate' => $this->canModerateCourse($request, (int) $lessonRow->course_id),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function storeLessonComment(Request $request, int $lesson): JsonResponse
    {
        $lessonRow = $this->authorizedLesson($request, $lesson);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $body = trim(strip_tags($data['body']));
        if (mb_strlen($body) < 2) {
            throw ValidationException::withMessages(['body' => 'Please enter a meaningful comment.']);
        }

        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            abort_unless(DB::table('course_lesson_comments')->where('id', $parentId)
                ->where('lesson_id', $lesson)->where('status', 'visible')->whereNull('deleted_at')->exists(),
                422, 'The comment you are replying to is unavailable.');
        }

        $id = DB::table('course_lesson_comments')->insertGetId([
            'course_id' => $lessonRow->course_id,
            'lesson_id' => $lesson,
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'body' => $body,
            'status' => 'visible',
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Your comment has been posted.',
            'comment' => DB::table('course_lesson_comments as comments')
                ->join('users', 'users.id', '=', 'comments.user_id')
                ->where('comments.id', $id)
                ->first(['comments.id', 'comments.parent_id', 'comments.body', 'comments.is_pinned', 'comments.created_at', 'comments.updated_at', 'comments.user_id', 'users.name as author_name']),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function updateLessonComment(Request $request, int $lesson, int $comment): JsonResponse
    {
        $this->authorizedLesson($request, $lesson);
        $row = DB::table('course_lesson_comments')->where('id', $comment)->where('lesson_id', $lesson)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        abort_unless((int) $row->user_id === (int) $request->user()->id || $this->canModerateCourse($request, (int) $row->course_id), 403);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']]);
        $body = trim(strip_tags($data['body']));
        abort_if(mb_strlen($body) < 2, 422, 'Please enter a meaningful comment.');
        DB::table('course_lesson_comments')->where('id', $comment)->update(['body' => $body, 'updated_at' => now()]);
        return response()->json(['updated' => true]);
    }

    public function deleteLessonComment(Request $request, int $lesson, int $comment): JsonResponse
    {
        $this->authorizedLesson($request, $lesson);
        $row = DB::table('course_lesson_comments')->where('id', $comment)->where('lesson_id', $lesson)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        abort_unless((int) $row->user_id === (int) $request->user()->id || $this->canModerateCourse($request, (int) $row->course_id), 403);
        DB::table('course_lesson_comments')->where('id', $comment)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return response()->json(['deleted' => true]);
    }


    public function instructorLiveClass(Request $request, int $liveClass): \Illuminate\View\View
    {
        $class = $this->authorizedLiveClass($request, $liveClass);
        abort_unless($this->canTeachLiveClass($request, $class), 403, 'Only the assigned instructor or an authorized course manager can access this panel.');
        $course = DB::table('courses')->where('id', $class->course_id)->first();
        return view('course-management::live-class-instructor', compact('class', 'course'));
    }

    public function liveQuestions(Request $request, int $liveClass): JsonResponse
    {
        $class = $this->authorizedLiveClass($request, $liveClass);
        $canAnswer = $this->canTeachLiveClass($request, $class);
        abort_unless($canAnswer || $this->isActiveLiveParticipant($request, $liveClass), 403, 'Join this class or sign in as its instructor to view Q&A.');
        $items = DB::table('live_class_discussions as discussions')
            ->join('users', 'users.id', '=', 'discussions.user_id')
            ->where('discussions.live_class_id', $liveClass)
            ->where('discussions.status', 'visible')
            ->whereNull('discussions.deleted_at')
            ->orderByDesc('discussions.is_pinned')
            ->orderBy('discussions.created_at')
            ->limit(200)
            ->select([
                'discussions.id', 'discussions.parent_id', 'discussions.body', 'discussions.is_pinned',
                'discussions.created_at', 'discussions.user_id', 'users.name as author_name',
            ])
            ->selectSub(
                DB::table('live_class_discussion_votes')->selectRaw('COUNT(*)')
                    ->whereColumn('discussion_id', 'discussions.id'),
                'votes_count'
            )
            ->get();
        return response()->json([
            'questions' => $items,
            'can_answer' => $canAnswer,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function askLiveQuestion(Request $request, int $liveClass): JsonResponse
    {
        $this->authorizedLiveParticipant($request, $liveClass);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:3', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $body = trim(strip_tags($data['body']));
        abort_if(mb_strlen($body) < 3, 422, 'Please enter a clear question.');
        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            abort_unless(DB::table('live_class_discussions')->where('id', $parentId)
                ->where('live_class_id', $liveClass)->where('status', 'visible')->whereNull('deleted_at')->exists(),
                422, 'The discussion you are replying to is unavailable.');
        }
        $id = DB::table('live_class_discussions')->insertGetId([
            'live_class_id' => $liveClass,
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'body' => $body,
            'status' => 'visible',
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json([
            'message' => 'Your question has been sent to the class.',
            'question' => DB::table('live_class_discussions as discussions')
                ->join('users', 'users.id', '=', 'discussions.user_id')
                ->where('discussions.id', $id)
                ->first(['discussions.id', 'discussions.parent_id', 'discussions.body', 'discussions.is_pinned', 'discussions.created_at', 'discussions.user_id', 'users.name as author_name']),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function answerLiveQuestion(Request $request, int $liveClass, int $question): JsonResponse
    {
        $class = $this->authorizedLiveClass($request, $liveClass);
        abort_unless($this->canTeachLiveClass($request, $class), 403, 'Only the class instructor or an authorized course manager can answer questions.');
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);
        $body = trim(strip_tags($data['body']));
        abort_if(mb_strlen($body) < 2, 422);
        abort_unless(DB::table('live_class_discussions')->where('id', $question)
            ->where('live_class_id', $liveClass)->whereNull('parent_id')->where('status', 'visible')->whereNull('deleted_at')->exists(), 404);
        $id = DB::table('live_class_discussions')->insertGetId([
            'live_class_id' => $liveClass,
            'user_id' => $request->user()->id,
            'parent_id' => $question,
            'body' => $body,
            'status' => 'visible',
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['answered' => true, 'answer_id' => $id], 201);
    }

    public function moderateLiveQuestion(Request $request, int $liveClass, int $question): JsonResponse
    {
        $class = $this->authorizedLiveClass($request, $liveClass);
        abort_unless($this->canTeachLiveClass($request, $class), 403);
        $data = $request->validate([
            'status' => ['sometimes', 'in:visible,hidden'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);
        $changes = [];
        if (array_key_exists('status', $data)) $changes['status'] = $data['status'];
        if (array_key_exists('is_pinned', $data)) $changes['is_pinned'] = (bool) $data['is_pinned'];
        abort_if($changes === [], 422, 'No moderation change was provided.');
        $changes['updated_at'] = now();
        $updated = DB::table('live_class_discussions')->where('id', $question)->where('live_class_id', $liveClass)->whereNull('deleted_at')->update($changes);
        abort_unless($updated, 404);
        return response()->json(['updated' => true]);
    }

    public function voteLiveQuestion(Request $request, int $liveClass, int $question): JsonResponse
    {
        $this->authorizedLiveParticipant($request, $liveClass);
        abort_unless(DB::table('live_class_discussions')->where('id', $question)
            ->where('live_class_id', $liveClass)->whereNull('deleted_at')->where('status', 'visible')->exists(), 404);
        DB::table('live_class_discussion_votes')->insertOrIgnore([
            'discussion_id' => $question,
            'user_id' => $request->user()->id,
            'created_at' => now(),
        ]);
        $count = DB::table('live_class_discussion_votes')->where('discussion_id', $question)->count();
        return response()->json(['votes' => $count]);
    }

    private function authorizedLesson(Request $request, int $lesson): object
    {
        $row = DB::table('course_lessons')->join('courses', 'courses.id', '=', 'course_lessons.course_id')
            ->where('course_lessons.id', $lesson)->where('courses.status', 'published')
            ->select('course_lessons.id', 'course_lessons.course_id', 'courses.branch_id')->first();
        abort_unless($row, 404);
        if (!$this->canModerateCourse($request, (int) $row->course_id)) {
            $this->assertActiveEnrollment($request, (int) $row->course_id, $row->branch_id);
        }
        return $row;
    }

    private function authorizedLiveClass(Request $request, int $liveClass): object
    {
        $class = DB::table('live_classes')->where('id', $liveClass)->whereNull('deleted_at')->first();
        abort_unless($class && in_array($class->status, ['upcoming', 'live'], true), 404);
        $course = DB::table('courses')->where('id', $class->course_id)->first();
        abort_unless($course, 404);
        if (!$this->canTeachLiveClass($request, $class)) {
            $this->assertActiveEnrollment($request, (int) $course->id, $course->branch_id);
        }
        return $class;
    }

    private function isActiveLiveParticipant(Request $request, int $liveClass): bool
    {
        return DB::table('live_class_participants')->where('live_class_id', $liveClass)
            ->where('user_id', $request->user()->id)->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subMinutes(2))->exists();
    }

    private function authorizedLiveParticipant(Request $request, int $liveClass): object
    {
        $class = $this->authorizedLiveClass($request, $liveClass);
        abort_unless($this->isActiveLiveParticipant($request, $liveClass), 403, 'Join the live class before using its Q&A panel.');
        return $class;
    }

    private function assertActiveEnrollment(Request $request, int $courseId, ?int $branchId): void
    {
        $enrollment = DB::table('enrollments')
            ->where('user_id', $request->user()->id)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->first();

        abort_unless($enrollment, 403, 'An active enrollment is required.');

        $studentBranchId = $this->studentBranchId($request);
        if ($branchId !== null) {
            abort_unless($studentBranchId !== null && $studentBranchId === (int) $branchId, 403, 'Course is outside your current student branch.');
        }
        if ($enrollment->branch_id !== null) {
            abort_unless($studentBranchId !== null && $studentBranchId === (int) $enrollment->branch_id, 403, 'Enrollment is outside your current student branch.');
        }
    }

    private function studentBranchId(Request $request): ?int
    {
        $student = $request->user();
        if (app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'user_id')
            && Schema::hasColumn('students', 'branch_id')) {
            $profileBranchId = DB::table('students')->where('user_id', $student->id)->value('branch_id');
            if ($profileBranchId !== null) {
                return (int) $profileBranchId;
            }
        }

        return $student->branch_id !== null ? (int) $student->branch_id : null;
    }

    private function canTeachLiveClass(Request $request, object $class): bool
    {
        $course = DB::table('courses')->where('id', $class->course_id)->first();
        return $course !== null && $this->canManageCourse($request, $course, (string) ($class->instructor_name ?? ''));
    }

    private function canModerateCourse(Request $request, int $courseId): bool
    {
        $course = DB::table('courses')->where('id', $courseId)->first();
        return $course !== null && $this->canManageCourse($request, $course, (string) ($course->instructor_name ?? ''));
    }

    private function canManageCourse(Request $request, object $course, ?string $instructorName = null): bool
    {
        $actor = $request->user();
        if (!$actor || !$actor->is_active) {
            return false;
        }

        if ($actor->hasRole('admin')) {
            return $actor->hasPermission('courses.manage');
        }

        if ($actor->hasRole('branch_manager')) {
            return $actor->hasPermission('courses.manage')
                && $actor->branch_id !== null
                && $course->branch_id !== null
                && (int) $actor->branch_id === (int) $course->branch_id;
        }

        if ($actor->hasRole('teacher')) {
            $teacherBranchId = $actor->branch_id !== null ? (int) $actor->branch_id : null;

            if (app(PluginManager::class)->isEnabled('teacher-management') && Schema::hasTable('teachers')) {
                $profileQuery = DB::table('teachers')->where('user_id', $actor->id);
                if (Schema::hasColumn('teachers', 'deleted_at')) {
                    $profileQuery->whereNull('deleted_at');
                }
                $teacherProfile = $profileQuery->first();

                // A teacher-management account must have a current, active profile.
                if (!$teacherProfile
                    || (Schema::hasColumn('teachers', 'is_active') && !(bool) $teacherProfile->is_active)) {
                    return false;
                }

                $teacherBranchId = $teacherProfile->branch_id !== null
                    ? (int) $teacherProfile->branch_id
                    : $teacherBranchId;
            }

            if ($course->branch_id !== null
                && $teacherBranchId !== null
                && $teacherBranchId !== (int) $course->branch_id) {
                return false;
            }

            // Instructor display names are editable metadata, not authorization.
            // Teaching privileges require a current normalized assignment.
            return Schema::hasTable('course_teacher_assignments')
                && DB::table('course_teacher_assignments')
                    ->where('course_id', $course->id)
                    ->where('teacher_user_id', $actor->id)
                    ->exists();
        }

        return $actor->hasPermission('courses.manage');
    }
}
