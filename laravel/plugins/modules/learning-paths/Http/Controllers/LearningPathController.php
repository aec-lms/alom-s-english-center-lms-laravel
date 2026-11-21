<?php

declare(strict_types=1);

namespace Plugins\Modules\LearningPaths\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LearningPathController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function index(Request $request): View
    {
        $this->ensureCourses();
        $actor = $request->user();
        $this->ensureBranchAssignment($actor);
        $isBranchManager = $actor->hasRole('branch_manager');
        $paths = DB::table('learning_paths')
            ->when($isBranchManager, fn ($query) => $query->where('branch_id', $actor->branch_id))
            ->orderByDesc('updated_at')
            ->paginate(20);
        foreach ($paths as $path) {
            $path->courses = $this->pathCourses((int) $path->id);
            $path->learner_count = DB::table('learning_path_enrollments')->where('learning_path_id', $path->id)->count();
        }
        $courses = DB::table('courses')
            ->where('status', 'published')
            ->when(Schema::hasColumn('courses', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->when($isBranchManager, fn ($query) => $query->where('branch_id', $actor->branch_id))
            ->orderBy('title')
            ->get(['id', 'title', 'branch_id']);
        $branches = Schema::hasTable('branches')
            ? DB::table('branches')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('learning-paths::admin-index', compact('paths', 'courses', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAssignment($actor);
        $validated = $this->validatePath($request, $actor);

        DB::transaction(function () use ($actor, $validated): void {
            $pathId = DB::table('learning_paths')->insertGetId([
                'branch_id' => $validated['branch_id'],
                'created_by' => $actor->id,
                'title' => $validated['title'],
                'slug' => $this->uniqueSlug($validated['title']),
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->replaceCourses($pathId, $validated['course_ids']);
        });

        return back()->with('status', 'Learning path created.');
    }

    public function update(Request $request, int $path): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAssignment($actor);
        $record = DB::table('learning_paths')
            ->when($actor->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id))
            ->where('id', $path)->first();
        abort_unless($record, 404);
        $validated = $this->validatePath($request, $actor);

        DB::transaction(function () use ($record, $validated, $actor): void {
            $locked = DB::table('learning_paths')
                ->when($actor->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id))
                ->where('id', $record->id)->lockForUpdate()->first();
            abort_unless($locked, 404);
            $currentIds = DB::table('learning_path_courses')->where('learning_path_id', $locked->id)
                ->orderBy('position')->pluck('course_id')->map(fn ($id): int => (int) $id)->all();
            $nextIds = array_values(array_unique(array_map('intval', $validated['course_ids'])));
            $hasLearners = DB::table('learning_path_enrollments')->where('learning_path_id', $locked->id)->exists();
            abort_if($hasLearners && $currentIds !== $nextIds, 409,
                'Course order is locked after learners enroll. Create a new path version to change its curriculum.');
            abort_if($hasLearners && (int) ($locked->branch_id ?? 0) !== (int) ($validated['branch_id'] ?? 0), 409,
                'Branch assignment is locked after learners enroll.');
            abort_if($hasLearners && $validated['status'] !== 'published', 409,
                'A path with enrolled learners cannot be unpublished or archived until those learners finish.');

            DB::table('learning_paths')->where('id', $locked->id)->update([
                'branch_id' => $validated['branch_id'],
                'title' => $validated['title'],
                'slug' => $this->uniqueSlug($validated['title'], (int) $locked->id),
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);
            if (!$hasLearners) {
                $this->replaceCourses((int) $locked->id, $nextIds);
            }
        });

        return back()->with('status', 'Learning path updated. Enrolled learner curriculum snapshots remain unchanged.');
    }

    public function status(Request $request, int $path): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureBranchAssignment($actor);
        $validated = $request->validate(['status' => ['required', 'in:draft,published,archived']]);
        DB::transaction(function () use ($actor, $path, $validated): void {
            // Enrollment takes this same parent-row lock before creating a learner
            // snapshot. Recheck the active roster only after acquiring the lock.
            $record = DB::table('learning_paths')
                ->when($actor->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id))
                ->where('id', $path)
                ->lockForUpdate()
                ->first();
            abort_unless($record, 404);

            $hasActiveLearners = DB::table('learning_path_enrollments')
                ->where('learning_path_id', $record->id)
                ->where('status', 'active')
                ->exists();
            abort_if($hasActiveLearners && $validated['status'] !== 'published', 409,
                'A path with active learners cannot be unpublished or archived until they finish.');

            if ($validated['status'] === 'published') {
                $items = $this->pathCourses((int) $record->id);
                abort_unless($items->count() >= 2, 422, 'A published learning path needs at least two courses.');
                abort_unless($items->every(fn ($item) => $item->course_status === 'published' && $item->course_deleted_at === null), 409,
                    'Every course in the path must remain published.');
                $this->assertPathCourseBranches($record, $items);
            }

            DB::table('learning_paths')->where('id', $record->id)->update([
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Learning path status updated.');
    }

    public function catalog(Request $request): View
    {
        $this->ensureCourses();
        $actor = $request->user();
        $paths = DB::table('learning_paths')
            ->where('status', 'published')
            ->when($actor && $actor->hasRole('student'), fn ($query) => $query->where(function ($branch) use ($actor): void {
                $branch->whereNull('branch_id');
                if ($actor->branch_id !== null) $branch->orWhere('branch_id', $actor->branch_id);
            }))
            ->when(!$actor || !$actor->hasRole('student'), fn ($query) => $query->whereNull('branch_id'))
            ->orderBy('title')->paginate(12);
        $visiblePaths = $paths->getCollection()->filter(function ($path) use ($actor): bool {
            $path->courses = $this->pathCourses((int) $path->id);
            $path->enrolled = $actor && $actor->hasRole('student')
                ? DB::table('learning_path_enrollments')->where('learning_path_id', $path->id)->where('user_id', $actor->id)->exists()
                : false;

            return $path->courses->count() >= 2
                && $path->courses->every(fn ($course) => $course->course_status === 'published' && $course->course_deleted_at === null);
        })->values();
        $paths->setCollection($visiblePaths);

        return view('learning-paths::catalog', compact('paths'));
    }

    public function show(Request $request, string $slug): View
    {
        $this->ensureCourses();
        $actor = $request->user();
        $path = DB::table('learning_paths')->where('slug', $slug)
            ->when($actor && $actor->hasRole('student'), fn ($query) => $query->where(function ($visibility) use ($actor): void {
                $visibility->where('status', 'published')
                    ->orWhereExists(function ($enrollment) use ($actor): void {
                        $enrollment->selectRaw('1')->from('learning_path_enrollments')
                            ->whereColumn('learning_path_enrollments.learning_path_id', 'learning_paths.id')
                            ->where('learning_path_enrollments.user_id', $actor->id)
                            ->whereIn('learning_path_enrollments.status', ['active', 'completed']);
                    });
            }))
            ->when(!$actor || !$actor->hasRole('student'), fn ($query) => $query->where('status', 'published'))
            ->when($actor && $actor->hasRole('student'), fn ($query) => $query->where(function ($branch) use ($actor): void {
                $branch->whereNull('branch_id');
                if ($actor->branch_id !== null) $branch->orWhere('branch_id', $actor->branch_id);
            }))
            ->when(!$actor || !$actor->hasRole('student'), fn ($query) => $query->whereNull('branch_id'))
            ->first();
        abort_unless($path, 404);
        $pathEnrollment = $actor && $actor->hasRole('student')
            ? DB::table('learning_path_enrollments')->where('learning_path_id', $path->id)->where('user_id', $actor->id)->first()
            : null;
        $courses = $this->pathCourses((int) $path->id);
        abort_unless($courses->count() >= 2, 404);
        if (!$pathEnrollment) {
            abort_unless($path->status === 'published' && $courses->every(fn ($course) => $course->course_status === 'published' && $course->course_deleted_at === null), 404);
            $this->assertPathCourseBranches($path, $courses);
        }
        $firstCourse = $courses->first();
        $firstEnrollment = $actor && $actor->hasRole('student')
            ? DB::table('enrollments')->where('user_id', $actor->id)->where('course_id', $firstCourse->course_id)
                ->whereIn('status', ['active', 'completed'])->exists()
            : false;
        $progress = $pathEnrollment ? $this->learnerProgress($path, $actor->id, $courses) : collect();

        return view('learning-paths::show', compact('path', 'courses', 'pathEnrollment', 'firstEnrollment', 'progress'));
    }

    public function enroll(Request $request, string $slug): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403, 'Student accounts only.');
        abort_unless((bool) $student->is_active, 403, 'An active student account is required.');
        $this->ensureCourses();

        DB::transaction(function () use ($student, $slug): void {
            DB::table('users')->where('id', $student->id)->lockForUpdate()->first();
            $path = DB::table('learning_paths')->where('slug', $slug)->where('status', 'published')->lockForUpdate()->first();
            abort_unless($path, 404);
            if ($path->branch_id !== null) {
                abort_unless($student->branch_id !== null && (int) $student->branch_id === (int) $path->branch_id,
                    403, 'This learning path is not available to your branch.');
            }
            $courses = $this->pathCourses((int) $path->id);
            abort_unless($courses->count() >= 2 && $courses->every(fn ($course) => $course->course_status === 'published' && $course->course_deleted_at === null), 409);
            $this->assertPathCourseBranches($path, $courses);
            $first = $courses->first();
            $firstEnrollment = DB::table('enrollments')->where('user_id', $student->id)
                ->where('course_id', $first->course_id)->whereIn('status', ['active', 'completed'])
                ->lockForUpdate()->first();
            abort_unless($firstEnrollment, 409,
                'Enroll in the first course before starting this learning path. Course purchases and fees remain separate.');
            $expectedEnrollmentBranch = $path->branch_id ?? $student->branch_id;
            if ($firstEnrollment->branch_id !== null) {
                abort_unless($expectedEnrollmentBranch !== null
                    && (int) $firstEnrollment->branch_id === (int) $expectedEnrollmentBranch,
                    403, 'Your first-course enrollment is outside this learning path branch.');
            }

            $existingPathEnrollment = DB::table('learning_path_enrollments')
                ->where('learning_path_id', $path->id)
                ->where('user_id', $student->id)
                ->lockForUpdate()
                ->first();
            if (!$existingPathEnrollment) {
                DB::table('learning_path_enrollments')->insert([
                    'learning_path_id' => $path->id,
                    'user_id' => $student->id,
                    'branch_id' => $path->branch_id ?? $student->branch_id,
                    'status' => 'active',
                    'started_at' => now(),
                    'completed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return redirect()->route('learning-paths.show', $slug)->with('status', 'You joined this learning path. Complete each course in order to unlock the next step.');
    }

    public function mine(Request $request): View
    {
        $student = $request->user();
        abort_unless($student->hasRole('student'), 403);
        $enrollments = DB::table('learning_path_enrollments as e')
            ->join('learning_paths as p', 'p.id', '=', 'e.learning_path_id')
            ->where('e.user_id', $student->id)
            ->whereIn('e.status', ['active', 'completed'])
            ->orderByDesc('e.started_at')
            ->select('e.*', 'p.title', 'p.slug', 'p.description')
            ->get();
        foreach ($enrollments as $enrollment) {
            $courses = $this->pathCourses((int) $enrollment->learning_path_id);
            $enrollment->progress = $this->learnerProgress((object) ['id' => $enrollment->learning_path_id], $student->id, $courses);
            if ($enrollment->progress->isNotEmpty() && $enrollment->progress->every(fn ($step) => $step->completed)) {
                DB::table('learning_path_enrollments')->where('id', $enrollment->id)->update([
                    'status' => 'completed', 'completed_at' => $enrollment->completed_at ?? now(), 'updated_at' => now(),
                ]);
                $enrollment->status = 'completed';
            }
        }

        return view('learning-paths::mine', compact('enrollments'));
    }

    private function validatePath(Request $request, $actor): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:draft,published,archived'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'course_ids' => ['required', 'array', 'min:2', 'max:30'],
            'course_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $validated['course_ids'])));
        $query = DB::table('courses')->whereIn('id', $ids)->where('status', 'published')
            ->when(Schema::hasColumn('courses', 'deleted_at'), fn ($builder) => $builder->whereNull('deleted_at'));
        if ($actor->hasRole('branch_manager')) {
            $query->where('branch_id', $actor->branch_id ?: -1);
        }
        $selected = $query->get(['id', 'title', 'branch_id']);
        abort_unless($selected->count() === count($ids), 422, 'Choose only published courses in your permitted branch.');
        $branchIds = $selected->pluck('branch_id')->filter()->unique();
        abort_if($branchIds->count() > 1, 422, 'A learning path cannot mix courses from different branches.');
        $branchId = $actor->hasRole('branch_manager')
            ? (int) $actor->branch_id
            : (isset($validated['branch_id']) ? (int) $validated['branch_id'] : $branchIds->first());
        if ($branchId !== null) {
            abort_unless($this->plugins->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $branchId)->where('is_active', true)->exists(),
                422, 'Select an active branch from Branch Management.');
        }
        if ($actor->hasRole('branch_manager')) {
            abort_unless($branchId > 0, 403, 'Branch managers must have an assigned branch.');
        }
        if ($branchId !== null && $branchIds->isNotEmpty()) {
            abort_unless($branchIds->count() === 1 && (int) $branchIds->first() === $branchId, 422,
                'The path branch must match every included course.');
        }

        return [
            'title' => trim($validated['title']),
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'branch_id' => $branchId,
            'course_ids' => $ids,
        ];
    }

    private function replaceCourses(int $pathId, array $courseIds): void
    {
        $courses = DB::table('courses')->whereIn('id', $courseIds)->where('status', 'published')
            ->when(Schema::hasColumn('courses', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->get()->keyBy('id');
        abort_unless($courses->count() === count($courseIds), 409, 'A selected course is no longer published.');
        DB::table('learning_path_courses')->where('learning_path_id', $pathId)->delete();
        foreach (array_values($courseIds) as $index => $courseId) {
            $course = $courses->get($courseId);
            DB::table('learning_path_courses')->insert([
                'learning_path_id' => $pathId,
                'course_id' => $courseId,
                'course_title' => $course->title,
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function pathCourses(int $pathId)
    {
        $columns = [
            'item.course_id', 'item.course_title', 'item.position',
            'courses.slug as course_slug', 'courses.status as course_status', 'courses.branch_id as course_branch_id',
        ];
        $columns[] = Schema::hasColumn('courses', 'deleted_at')
            ? 'courses.deleted_at as course_deleted_at'
            : DB::raw('NULL as course_deleted_at');

        return DB::table('learning_path_courses as item')
            ->leftJoin('courses', 'courses.id', '=', 'item.course_id')
            ->where('item.learning_path_id', $pathId)
            ->orderBy('item.position')
            ->get($columns);
    }

    private function assertPathCourseBranches($path, $courses): void
    {
        $courseBranchIds = $courses->pluck('course_branch_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        abort_if($courseBranchIds->count() > 1, 409, 'The path contains courses from multiple branches.');
        if ($path->branch_id !== null && $courseBranchIds->isNotEmpty()) {
            abort_unless($courseBranchIds->count() === 1 && (int) $courseBranchIds->first() === (int) $path->branch_id,
                409, 'A course branch changed after this learning path was configured.');
        }
        if ($path->branch_id === null && $courseBranchIds->isNotEmpty()) {
            abort(409, 'A branch-specific course cannot be delivered through a global learning path.');
        }
    }

    private function learnerProgress($path, int $userId, $courses)
    {
        $previousCompleted = true;
        return $courses->map(function ($course) use ($userId, &$previousCompleted) {
            $enrollment = DB::table('enrollments')->where('user_id', $userId)->where('course_id', $course->course_id)
                ->whereIn('status', ['active', 'completed'])->first();
            $completed = $enrollment?->status === 'completed';
            if ($enrollment && !$completed && $this->plugins->isEnabled('course-content-studio')
                && Schema::hasTable('course_curriculum_items')
                && Schema::hasTable('course_content_progress')
                && Schema::hasTable('course_content_posts')
            ) {
                $required = DB::table('course_curriculum_items as item')
                    ->join('course_content_posts as post', 'post.id', '=', 'item.course_content_post_id')
                    ->where('item.course_id', $course->course_id)
                    ->where('item.is_active', true)->where('item.is_preview', false)
                    ->where('post.status', 'published')->whereNotNull('post.published_at')
                    ->where('post.published_at', '<=', now())->count();
                $done = DB::table('course_content_progress as progress')
                    ->join('course_curriculum_items as item', 'item.id', '=', 'progress.course_curriculum_item_id')
                    ->join('course_content_posts as post', 'post.id', '=', 'item.course_content_post_id')
                    ->where('progress.user_id', $userId)->where('item.course_id', $course->course_id)
                    ->where('item.is_active', true)->where('item.is_preview', false)
                    ->where('post.status', 'published')->whereNotNull('post.published_at')
                    ->where('post.published_at', '<=', now())
                    ->whereNotNull('progress.completed_at')->count();
                $completed = $required > 0 && $done >= $required;
            }
            $step = (object) [
                'course_id' => $course->course_id,
                'course_title' => $course->course_title,
                'course_slug' => $course->course_slug,
                'position' => $course->position,
                'completed' => $completed,
                'enrolled' => $enrollment !== null,
                'unlocked' => $previousCompleted,
            ];
            $previousCompleted = $completed;
            return $step;
        });
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'learning-path';
        $slug = $base;
        $suffix = 2;
        while (DB::table('learning_paths')->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '<>', $ignoreId))->exists()) {
            $slug = $base . '-' . $suffix++;
        }
        return $slug;
    }

    private function ensureCourses(): void
    {
        abort_unless($this->plugins->isEnabled('course-management') && Schema::hasTable('courses') && Schema::hasTable('enrollments'),
            503, 'Enable Course Management to use Learning Paths.');
    }

    private function ensureBranchAssignment($actor): void
    {
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless($actor->branch_id !== null, 403, 'Your account has no assigned branch.');
        }
    }
}
