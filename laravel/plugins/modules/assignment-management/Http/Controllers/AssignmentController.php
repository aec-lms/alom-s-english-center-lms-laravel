<?php

declare(strict_types=1);

namespace Plugins\Modules\AssignmentManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\AssignmentManagement\Models\Assignment;
use Plugins\Modules\AssignmentManagement\Models\AssignmentSubmission;
use Plugins\Modules\LearningRewards\Services\LearningRewardsService;
use Throwable;

class AssignmentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasRole('admin')
            || $actor->hasRole('teacher')
            || ($actor->hasRole('branch_manager') && $actor->hasPermission('assignments.manage'))
        ), 403);
        $this->ensureAssignedBranch($actor);

        $assignments = Assignment::query()
            ->with(['submissions.student', 'creator'])
            ->withCount('submissions')
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', (int) $actor->branch_id))
            ->when($actor->hasRole('teacher'), fn ($q) => $q->where('created_by', $actor->id))
            ->latest()
            ->paginate(20);

        return view('assignment-management::index', compact('assignments'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasRole('admin')
            || $actor->hasRole('teacher')
            || ($actor->hasRole('branch_manager') && $actor->hasPermission('assignments.manage'))
        ), 403);
        $this->ensureAssignedBranch($actor);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'instructions' => ['required', 'string', 'max:20000'],
            'course_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'due_at' => ['nullable', 'date', 'after:now'],
            'max_score' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'allow_late' => ['nullable', 'boolean'],
            'status' => ['required', 'in:draft,published'],
        ]);

        $course = null;
        if (!empty($validated['course_id'])) {
            abort_unless(app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Enable Course Management to attach an assignment to a course.');
            $courseQuery = DB::table('courses')->where('id', (int) $validated['course_id']);
            if (Schema::hasColumn('courses', 'deleted_at')) {
                $courseQuery->whereNull('deleted_at');
            }
            $course = $courseQuery->first();
            abort_unless($course, 422, 'Selected course does not exist.');
        }

        $requestedBranchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $courseBranchId = $course && $course->branch_id !== null ? (int) $course->branch_id : null;
        $branchId = $this->resolveAssignmentBranch($actor, $requestedBranchId, $courseBranchId);

        $assignment = Assignment::query()->create([
            'branch_id' => $branchId,
            'course_id' => $course?->id,
            'created_by' => $actor->id,
            'title' => trim($validated['title']),
            'instructions' => $validated['instructions'],
            'due_at' => $validated['due_at'] ?? null,
            'max_score' => $validated['max_score'],
            'allow_late' => $request->boolean('allow_late'),
            'status' => $validated['status'],
        ]);

        $this->audit->log('assignment.created', 'assignment', (string) $assignment->id, [
            'branch_id' => $branchId,
            'course_id' => $course?->id,
            'status' => $assignment->status,
        ], $actor->id);

        return back()->with('status', 'Assignment created successfully.');
    }

    public function studentIndex(Request $request): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);

        $assignments = $this->studentAssignmentQuery($student)
            ->with(['submissions' => fn ($q) => $q->where('student_id', $student->id)])
            ->latest()
            ->paginate(20);

        return view('assignment-management::student-index', compact('assignments'));
    }

    public function show(Request $request, int $assignment): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $item = $this->studentAssignmentQuery($student)
            ->with(['submissions' => fn ($q) => $q->where('student_id', $student->id)])
            ->findOrFail($assignment);

        return view('assignment-management::student-show', [
            'assignment' => $item,
            'submission' => $item->submissions->first(),
        ]);
    }

    public function submit(Request $request, int $assignment): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $item = $this->studentAssignmentQuery($student)->findOrFail($assignment);

        $validated = $request->validate([
            'answer_text' => ['nullable', 'string', 'max:30000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,doc,docx,txt,jpg,jpeg,png,zip', 'max:20480'],
        ]);
        if (trim((string) ($validated['answer_text'] ?? '')) === '' && !$request->hasFile('attachment')) {
            return back()->withErrors(['submission' => 'Write an answer or attach a file.']);
        }

        $storedPath = null;
        if ($request->hasFile('attachment')) {
            $storedPath = $request->file('attachment')->store('assignment-submissions/' . $item->id, 'local');
            if (!$storedPath) {
                return back()->withErrors(['attachment' => 'The attachment could not be stored. Please try again.']);
            }
        }

        try {
            $result = DB::transaction(function () use ($item, $student, $validated, $storedPath): array {
                $lockedAssignment = Assignment::query()->lockForUpdate()->findOrFail($item->id);
                abort_unless($this->studentCanAccessAssignment($student, $lockedAssignment), 404);

                if ($lockedAssignment->due_at && now()->greaterThan($lockedAssignment->due_at) && !$lockedAssignment->allow_late) {
                    return ['state' => 'closed'];
                }

                $submission = AssignmentSubmission::query()
                    ->where('assignment_id', $lockedAssignment->id)
                    ->where('student_id', $student->id)
                    ->lockForUpdate()
                    ->first();

                if ($submission && $submission->status === 'graded') {
                    return ['state' => 'graded'];
                }

                if (
                    trim((string) ($validated['answer_text'] ?? '')) === ''
                    && !$storedPath
                    && !$submission?->attachment_path
                    && trim((string) ($submission?->answer_text ?? '')) === ''
                ) {
                    throw ValidationException::withMessages([
                        'submission' => 'Write an answer or attach a file.',
                    ]);
                }

                $previousAttachmentPath = $submission?->attachment_path;
                $submission ??= new AssignmentSubmission([
                    'assignment_id' => $lockedAssignment->id,
                    'student_id' => $student->id,
                ]);

                $submission->fill([
                    'answer_text' => $validated['answer_text'] ?? $submission->answer_text,
                    'attachment_path' => $storedPath ?? $submission->attachment_path,
                    'submitted_at' => now(),
                    'status' => 'submitted',
                    'score' => null,
                    'feedback' => null,
                    'graded_by' => null,
                    'graded_at' => null,
                ]);
                $submission->assignment_id = $lockedAssignment->id;
                $submission->student_id = $student->id;
                $submission->save();

                $this->audit->log('assignment.submitted', 'assignment_submission', (string) $submission->id, [
                    'assignment_id' => $lockedAssignment->id,
                    'has_attachment' => (bool) $submission->attachment_path,
                    'revision' => !$submission->wasRecentlyCreated,
                ], $student->id);

                return [
                    'state' => 'saved',
                    'previous_attachment_path' => $previousAttachmentPath,
                ];
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }

        if ($result['state'] !== 'saved') {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            $message = match ($result['state']) {
                'closed' => 'The submission deadline has passed.',
                'graded' => 'This submission has already been graded and cannot be replaced.',
                default => 'The assignment is no longer available.',
            };

            return back()->withErrors(['submission' => $message]);
        }

        $previousPath = $result['previous_attachment_path'] ?? null;
        if ($previousPath && $previousPath !== $storedPath) {
            Storage::disk('local')->delete($previousPath);
        }

        return redirect()->route('plugins.assignments.student.show', $item->id)
            ->with('status', 'Assignment submitted successfully.');
    }

    public function grade(Request $request, int $submission): RedirectResponse
    {
        $grader = $request->user();
        abort_unless($grader && (
            $grader->hasRole('admin')
            || $grader->hasRole('teacher')
            || ($grader->hasRole('branch_manager') && $grader->hasPermission('assignments.grade'))
        ), 403);
        $this->ensureAssignedBranch($grader);

        $record = AssignmentSubmission::query()->with('assignment')->findOrFail($submission);
        $this->authorizeGrading($grader, $record->assignment);

        $validated = $request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:' . $record->assignment->max_score],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($grader, $record, $validated): void {
            $locked = AssignmentSubmission::query()->with('assignment')->lockForUpdate()->findOrFail($record->id);
            $this->authorizeGrading($grader, $locked->assignment);
            $previouslyGraded = $locked->status === 'graded';
            $previousScore = (float) ($locked->score ?? 0);
            $newScore = (float) $validated['score'];
            $locked->update([
                'score' => $validated['score'],
                'feedback' => $validated['feedback'] ?? null,
                'status' => 'graded',
                'graded_by' => $grader->id,
                'graded_at' => now(),
            ]);

            if (app(PluginManager::class)->isEnabled('learning-rewards')) {
                $rewards = app(LearningRewardsService::class);
                if ($newScore > 0 && (!$previouslyGraded || $previousScore <= 0)) {
                    $rewards->awardVerifiedActivity(
                        (int) $locked->student_id,
                        'assignment_accepted',
                        'assignment_submission',
                        (int) $locked->id,
                        $previouslyGraded ? 'regraded-' . $locked->updated_at?->format('Uu') : null,
                    );
                } elseif ($previouslyGraded && $previousScore > 0 && $newScore <= 0) {
                    $rewards->reverseActivityAward(
                        (int) $locked->student_id,
                        'assignment_accepted',
                        'assignment_submission',
                        (int) $locked->id,
                        (int) $grader->id,
                        'Assignment grade was changed to zero.',
                    );
                }
            }

            $this->audit->log('assignment.graded', 'assignment_submission', (string) $locked->id, [
                'assignment_id' => $locked->assignment_id,
                'score' => $validated['score'],
            ], $grader->id);
        });

        return back()->with('status', 'Submission graded successfully.');
    }

    public function downloadSubmission(Request $request, int $submission)
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasRole('admin')
            || ($actor->hasRole('teacher') && AssignmentSubmission::query()->whereKey($submission)->whereHas('assignment', fn ($q) => $q->where('created_by', $actor->id))->exists())
            || ($actor->hasRole('branch_manager') && $actor->hasPermission('assignments.grade'))
        ), 403);
        $this->ensureAssignedBranch($actor);

        $record = AssignmentSubmission::query()->with('assignment')->findOrFail($submission);
        $this->authorizeGrading($actor, $record->assignment);
        abort_unless($record->attachment_path, 404);

        return $this->downloadPrivateAttachment($record->attachment_path);
    }

    public function downloadOwnSubmission(Request $request, int $submission)
    {
        $actor = $request->user();
        $record = AssignmentSubmission::query()->findOrFail($submission);
        abort_unless($actor && $actor->hasRole('student') && (int) $record->student_id === (int) $actor->id, 403);
        abort_unless($record->attachment_path, 404);

        return $this->downloadPrivateAttachment($record->attachment_path);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $assignments = $this->studentAssignmentQuery($student)
            ->with(['submissions' => fn ($query) => $query->where('student_id', $student->id)])
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $assignments->getCollection()->map(fn (Assignment $assignment): array => $this->studentAssignmentData($assignment)),
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
            ],
        ]);
    }

    public function apiShow(Request $request, int $assignment): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $item = $this->studentAssignmentQuery($student)
            ->with(['submissions' => fn ($query) => $query->where('student_id', $student->id)])
            ->findOrFail($assignment);

        return response()->json(['data' => $this->studentAssignmentData($item)]);
    }

    public function apiSubmit(Request $request, int $assignment): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $item = $this->studentAssignmentQuery($student)->findOrFail($assignment);

        $validated = $request->validate([
            'answer_text' => ['nullable', 'string', 'max:30000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,doc,docx,txt,jpg,jpeg,png,zip', 'max:20480'],
        ]);
        if (trim((string) ($validated['answer_text'] ?? '')) === '' && !$request->hasFile('attachment')) {
            return response()->json(['message' => 'Write an answer or attach a file.'], 422);
        }

        $storedPath = null;
        if ($request->hasFile('attachment')) {
            $storedPath = $request->file('attachment')->store('assignment-submissions/' . $item->id, 'local');
            if (!$storedPath) {
                return response()->json(['message' => 'The attachment could not be stored.'], 500);
            }
        }

        try {
            $result = DB::transaction(function () use ($item, $student, $validated, $storedPath): array {
                $lockedAssignment = Assignment::query()->lockForUpdate()->findOrFail($item->id);
                abort_unless($this->studentCanAccessAssignment($student, $lockedAssignment), 404);
                if ($lockedAssignment->due_at && now()->greaterThan($lockedAssignment->due_at) && !$lockedAssignment->allow_late) {
                    return ['state' => 'closed'];
                }

                $submission = AssignmentSubmission::query()
                    ->where('assignment_id', $lockedAssignment->id)
                    ->where('student_id', $student->id)
                    ->lockForUpdate()
                    ->first();
                if ($submission && $submission->status === 'graded') {
                    return ['state' => 'graded'];
                }

                if (
                    trim((string) ($validated['answer_text'] ?? '')) === ''
                    && !$storedPath
                    && !$submission?->attachment_path
                    && trim((string) ($submission?->answer_text ?? '')) === ''
                ) {
                    throw ValidationException::withMessages([
                        'answer_text' => 'Write an answer or attach a file.',
                    ]);
                }

                $previousPath = $submission?->attachment_path;
                $submission ??= new AssignmentSubmission([
                    'assignment_id' => $lockedAssignment->id,
                    'student_id' => $student->id,
                ]);
                $submission->fill([
                    'answer_text' => $validated['answer_text'] ?? $submission->answer_text,
                    'attachment_path' => $storedPath ?? $submission->attachment_path,
                    'submitted_at' => now(),
                    'status' => 'submitted',
                    'score' => null,
                    'feedback' => null,
                    'graded_by' => null,
                    'graded_at' => null,
                ]);
                $submission->assignment_id = $lockedAssignment->id;
                $submission->student_id = $student->id;
                $submission->save();

                $this->audit->log('assignment.submitted', 'assignment_submission', (string) $submission->id, [
                    'assignment_id' => $lockedAssignment->id,
                    'source' => 'mobile_api',
                    'has_attachment' => (bool) $submission->attachment_path,
                ], $student->id);

                return [
                    'state' => 'saved',
                    'created' => $submission->wasRecentlyCreated,
                    'previous_path' => $previousPath,
                    'submission' => $submission,
                ];
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }

        if ($result['state'] !== 'saved') {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            $status = $result['state'] === 'graded' ? 409 : 422;
            $message = $result['state'] === 'graded'
                ? 'This submission has already been graded and cannot be replaced.'
                : 'The submission deadline has passed.';
            return response()->json(['message' => $message], $status);
        }

        if (($result['previous_path'] ?? null) && $result['previous_path'] !== $storedPath) {
            Storage::disk('local')->delete($result['previous_path']);
        }

        /** @var AssignmentSubmission $submission */
        $submission = $result['submission'];

        return response()->json([
            'message' => 'Assignment submitted successfully.',
            'data' => [
                'id' => $submission->id,
                'status' => $submission->status,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'score' => $submission->score,
                'feedback' => $submission->feedback,
                'has_attachment' => filled($submission->attachment_path),
            ],
        ], $result['created'] ? 201 : 200);
    }

    private function studentAssignmentData(Assignment $assignment): array
    {
        $submission = $assignment->submissions->first();

        return [
            'id' => $assignment->id,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'course_id' => $assignment->course_id,
            'due_at' => $assignment->due_at?->toIso8601String(),
            'max_score' => $assignment->max_score,
            'allow_late' => $assignment->allow_late,
            'submission' => $submission ? [
                'id' => $submission->id,
                'answer_text' => $submission->answer_text,
                'status' => $submission->status,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'score' => $submission->score,
                'feedback' => $submission->feedback,
                'has_attachment' => filled($submission->attachment_path),
            ] : null,
        ];
    }

    private function studentAssignmentQuery($student)
    {
        $query = Assignment::query()
            ->where('status', 'published')
            ->where(function ($q) use ($student): void {
                $q->whereNull('branch_id');
                if ($student->branch_id !== null) {
                    $q->orWhere('branch_id', (int) $student->branch_id);
                }
            })
            ->where(function ($q) use ($student): void {
                $q->whereNull('course_id');
                if (app(PluginManager::class)->isEnabled('course-management') && Schema::hasTable('enrollments')) {
                    $q->orWhereIn('course_id', DB::table('enrollments')
                        ->select('course_id')
                        ->where('user_id', $student->id)
                        ->where('status', 'active'));
                }
            });

        return $query;
    }

    private function studentCanAccessAssignment($student, Assignment $assignment): bool
    {
        if ($assignment->status !== 'published') {
            return false;
        }
        if ($assignment->branch_id !== null && (int) $assignment->branch_id !== (int) ($student->branch_id ?? 0)) {
            return false;
        }
        if ($assignment->course_id === null) {
            return true;
        }
        if (!app(PluginManager::class)->isEnabled('course-management') || !Schema::hasTable('enrollments')) {
            return false;
        }

        return DB::table('enrollments')
            ->where('user_id', $student->id)
            ->where('course_id', $assignment->course_id)
            ->where('status', 'active')
            ->exists();
    }

    private function resolveAssignmentBranch($actor, ?int $requestedBranchId, ?int $courseBranchId): ?int
    {
        if ($actor->hasRole('branch_manager') || ($actor->hasRole('teacher') && $actor->branch_id !== null)) {
            $assignedBranchId = (int) $actor->branch_id;
            abort_unless($assignedBranchId > 0, 403, 'Your account is not assigned to a branch.');
            if ($requestedBranchId !== null) {
                abort_unless($requestedBranchId === $assignedBranchId, 403, 'You cannot create an assignment for another branch.');
            }
            if ($courseBranchId !== null) {
                abort_unless($courseBranchId === $assignedBranchId, 422, 'Assignment and course must belong to the same branch.');
            }
            return $assignedBranchId;
        }

        if ($actor->hasRole('teacher')) {
            abort_unless($requestedBranchId === null && $courseBranchId === null, 403, 'An unassigned teacher can create only global assignments.');
            return null;
        }

        $branchId = $requestedBranchId ?? $courseBranchId;
        if ($courseBranchId !== null) {
            abort_unless($branchId === $courseBranchId, 422, 'Assignment and course must belong to the same branch.');
        }
        if ($branchId !== null) {
            abort_unless(
                app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $branchId)->where('is_active', true)->exists(),
                422,
                'Select an active branch.'
            );
        }

        return $branchId;
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor) {
            return;
        }

        $requiresBranch = $actor->hasRole('branch_manager')
            || ($actor->hasRole('teacher') && $actor->branch_id !== null);
        if (!$requiresBranch) {
            return;
        }

        abort_unless(
            (int) ($actor->branch_id ?? 0) > 0
            && app(PluginManager::class)->isEnabled('branch-management')
            && Schema::hasTable('branches')
            && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
            403,
            'Your account must be assigned to an active branch.'
        );
    }

    private function authorizeGrading($grader, Assignment $assignment): void
    {
        if ($grader->hasRole('teacher')) {
            abort_unless((int) $assignment->created_by === (int) $grader->id, 403, 'You can grade only your own assignments.');
            abort_unless(
                (int) ($assignment->branch_id ?? 0) === (int) ($grader->branch_id ?? 0),
                403,
                'You cannot grade assignments outside your assigned branch.'
            );
        }
        if ($grader->hasRole('branch_manager')) {
            abort_unless((int) ($grader->branch_id ?? 0) > 0 && (int) $grader->branch_id === (int) $assignment->branch_id, 403);
        }
    }

    private function downloadPrivateAttachment(string $path)
    {
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        $target = realpath($disk->path($path));
        abort_unless(
            $root !== false
            && $target !== false
            && is_file($target)
            && str_starts_with($target, $root . DIRECTORY_SEPARATOR),
            404
        );

        return response()->download($target);
    }
}
