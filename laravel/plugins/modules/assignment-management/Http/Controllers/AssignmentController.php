<?php

declare(strict_types=1);

namespace Plugins\Modules\AssignmentManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Plugins\Modules\AssignmentManagement\Models\Assignment;
use Plugins\Modules\AssignmentManagement\Models\AssignmentSubmission;

class AssignmentController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasRole('admin') || $actor->hasRole('teacher') || ($actor->hasRole('branch_manager') && $actor->hasPermission('assignments.manage'))), 403);
        $this->ensureAssignedBranch($actor);
        $assignments = Assignment::query()->with('submissions.student')
            ->with('creator')
            ->withCount('submissions')
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id ?: -1))
            ->when($actor->hasRole('teacher'), fn ($q) => $q->where('created_by', $actor->id))
            ->latest()
            ->paginate(20);

        return view('assignment-management::index', compact('assignments'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasRole('admin') || $actor->hasRole('teacher') || ($actor->hasRole('branch_manager') && $actor->hasPermission('assignments.manage'))), 403);
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
            $course = DB::table('courses')->where('id', (int) $validated['course_id'])->first();
            abort_unless($course, 422, 'Selected course does not exist.');
        }

        $branchId = $actor->hasRole('branch_manager')
            ? (int) ($actor->branch_id ?? 0)
            : (isset($validated['branch_id']) ? (int) $validated['branch_id'] : ($course->branch_id ?? $actor->branch_id));
        if ($actor->hasRole('branch_manager')) {
            abort_unless($branchId > 0, 403, 'Your account is not assigned to an active branch.');
        }
        if ($course && (int) ($course->branch_id ?? 0) !== (int) ($branchId ?? 0)) {
            abort(422, 'Assignment and course must belong to the same branch.');
        }

        Assignment::query()->create([
            'branch_id' => $branchId,
            'course_id' => $course?->id,
            'created_by' => $actor->id,
            'title' => $validated['title'],
            'instructions' => $validated['instructions'],
            'due_at' => $validated['due_at'] ?? null,
            'max_score' => $validated['max_score'],
            'allow_late' => $request->boolean('allow_late'),
            'status' => $validated['status'],
        ]);

        return back()->with('status', 'Assignment created successfully.');
    }

    public function studentIndex(Request $request): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $assignments = Assignment::query()
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))
            ->with(['submissions' => fn ($q) => $q->where('student_id', $student->id)])
            ->latest()
            ->paginate(20);

        return view('assignment-management::student-index', compact('assignments'));
    }

    public function show(Request $request, int $assignment): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $item = Assignment::query()->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))
            ->with(['submissions' => fn ($q) => $q->where('student_id', $student->id)])
            ->findOrFail($assignment);

        return view('assignment-management::student-show', ['assignment' => $item, 'submission' => $item->submissions->first()]);
    }

    public function submit(Request $request, int $assignment): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $item = Assignment::query()->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))
            ->findOrFail($assignment);

        if ($item->due_at && now()->greaterThan($item->due_at) && !$item->allow_late) {
            return back()->withErrors(['submission' => 'The submission deadline has passed.']);
        }

        $validated = $request->validate([
            'answer_text' => ['nullable', 'string', 'max:30000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,doc,docx,txt,jpg,jpeg,png,zip', 'max:20480'],
        ]);
        if (trim((string) ($validated['answer_text'] ?? '')) === '' && !$request->hasFile('attachment')) {
            return back()->withErrors(['submission' => 'Write an answer or attach a file.']);
        }

        $existing = AssignmentSubmission::query()->where('assignment_id', $item->id)->where('student_id', $student->id)->first();
        if ($existing && $existing->status === 'graded') {
            return back()->withErrors(['submission' => 'This submission has already been graded and cannot be replaced.']);
        }

        $path = $request->hasFile('attachment')
            ? $request->file('attachment')->store('assignment-submissions/'.$item->id, 'local')
            : ($existing?->attachment_path);

        AssignmentSubmission::query()->updateOrCreate(
            ['assignment_id' => $item->id, 'student_id' => $student->id],
            [
                'answer_text' => $validated['answer_text'] ?? null,
                'attachment_path' => $path,
                'submitted_at' => now(),
                'status' => 'submitted',
                'score' => null,
                'feedback' => null,
                'graded_by' => null,
                'graded_at' => null,
            ]
        );

        return redirect()->route('plugins.assignments.student.show', $item->id)->with('status', 'Assignment submitted successfully.');
    }

    public function grade(Request $request, int $submission): RedirectResponse
    {
        $grader = $request->user();
        abort_unless($grader && ($grader->hasRole('admin') || $grader->hasRole('teacher') || ($grader->hasRole('branch_manager') && $grader->hasPermission('assignments.grade'))), 403);
        $record = AssignmentSubmission::query()->with('assignment')->findOrFail($submission);
        if ($grader->hasRole('teacher')) {
            abort_unless((int) $record->assignment->created_by === (int) $grader->id, 403, 'You can grade only your own assignments.');
        }
        if ($grader->hasRole('branch_manager')) {
            abort_unless((int) $grader->branch_id === (int) $record->assignment->branch_id, 403);
        }

        $validated = $request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:'.$record->assignment->max_score],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $record->update([
            'score' => $validated['score'],
            'feedback' => $validated['feedback'] ?? null,
            'status' => 'graded',
            'graded_by' => $grader->id,
            'graded_at' => now(),
        ]);

        return back()->with('status', 'Submission graded successfully.');
    }

    public function downloadSubmission(Request $request, int $submission)
    {
        $actor = $request->user();
        $record = AssignmentSubmission::query()->with('assignment')->findOrFail($submission);
        abort_unless($actor && ($actor->hasRole('admin') || ($actor->hasRole('teacher') && (int) $record->assignment->created_by === (int) $actor->id) || ($actor->hasRole('branch_manager') && $actor->hasPermission('assignments.grade') && (int) $actor->branch_id === (int) $record->assignment->branch_id)), 403);
        abort_unless($record->attachment_path && Storage::disk('local')->exists($record->attachment_path), 404);

        return Storage::disk('local')->download($record->attachment_path);
    }

    public function downloadOwnSubmission(Request $request, int $submission)
    {
        $actor = $request->user();
        $record = AssignmentSubmission::query()->findOrFail($submission);
        abort_unless($actor && $actor->hasRole('student') && (int) $record->student_id === (int) $actor->id, 403);
        abort_unless($record->attachment_path && Storage::disk('local')->exists($record->attachment_path), 404);

        return Storage::disk('local')->download($record->attachment_path);
    }


    public function apiIndex(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $assignments = Assignment::query()
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))
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
        $item = Assignment::query()
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))
            ->with(['submissions' => fn ($query) => $query->where('student_id', $student->id)])
            ->findOrFail($assignment);

        return response()->json(['data' => $this->studentAssignmentData($item)]);
    }

    public function apiSubmit(Request $request, int $assignment): JsonResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $item = Assignment::query()
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))
            ->findOrFail($assignment);

        if ($item->due_at && now()->greaterThan($item->due_at) && !$item->allow_late) {
            return response()->json(['message' => 'The submission deadline has passed.'], 422);
        }

        $validated = $request->validate([
            'answer_text' => ['nullable', 'string', 'max:30000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,doc,docx,txt,jpg,jpeg,png,zip', 'max:20480'],
        ]);
        if (trim((string) ($validated['answer_text'] ?? '')) === '' && !$request->hasFile('attachment')) {
            return response()->json(['message' => 'Write an answer or attach a file.'], 422);
        }

        $existing = AssignmentSubmission::query()
            ->where('assignment_id', $item->id)
            ->where('student_id', $student->id)
            ->first();
        if ($existing && $existing->status === 'graded') {
            return response()->json(['message' => 'This submission has already been graded and cannot be replaced.'], 409);
        }

        $path = $request->hasFile('attachment')
            ? $request->file('attachment')->store('assignment-submissions/'.$item->id, 'local')
            : ($existing?->attachment_path);

        $submission = AssignmentSubmission::query()->updateOrCreate(
            ['assignment_id' => $item->id, 'student_id' => $student->id],
            [
                'answer_text' => $validated['answer_text'] ?? null,
                'attachment_path' => $path,
                'submitted_at' => now(),
                'status' => 'submitted',
                'score' => null,
                'feedback' => null,
                'graded_by' => null,
                'graded_at' => null,
            ]
        );

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
        ], $existing ? 200 : 201);
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

    private function ensureAssignedBranch($actor): void
    {
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless((int) ($actor->branch_id ?? 0) > 0, 403, 'Your account is not assigned to an active branch.');
        }
    }
}
