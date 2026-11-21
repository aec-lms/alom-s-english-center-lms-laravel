<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Core\Plugins\PluginManager;
use App\Core\Security\BranchAccess;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class CourseTeacherAssignmentController extends Controller
{
    public function __construct(private readonly BranchAccess $branches) {}
    public function index(Request $request): View
    {
        $branchId = $this->branches->assignedBranchId($request->user());
        $courses = DB::table('courses')->whereNull('deleted_at')->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->orderBy('title')->get(['id', 'title', 'branch_id']);
        $teachersQuery = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'teacher'))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));
        if (app(PluginManager::class)->isEnabled('teacher-management') && Schema::hasTable('teachers')) {
            $teachersQuery->whereExists(function ($query): void {
                $query->selectRaw('1')->from('teachers')->whereColumn('teachers.user_id', 'users.id');
                if (Schema::hasColumn('teachers', 'is_active')) {
                    $query->where('teachers.is_active', true);
                }
                if (Schema::hasColumn('teachers', 'deleted_at')) {
                    $query->whereNull('teachers.deleted_at');
                }
            });
        }
        $teachers = $teachersQuery->orderBy('name')->get(['id', 'name', 'email', 'branch_id']);
        $assignments = DB::table('course_teacher_assignments')
            ->join('courses', 'courses.id', '=', 'course_teacher_assignments.course_id')
            ->join('users', 'users.id', '=', 'course_teacher_assignments.teacher_user_id')
            ->join('role_user as teacher_role_user', 'teacher_role_user.user_id', '=', 'users.id')
            ->join('roles as teacher_roles', 'teacher_roles.id', '=', 'teacher_role_user.role_id')
            ->where('users.is_active', true)
            ->where('teacher_roles.slug', 'teacher')
            ->when(
                app(PluginManager::class)->isEnabled('teacher-management') && Schema::hasTable('teachers'),
                function ($query): void {
                    $query->whereExists(function ($profile): void {
                        $profile->selectRaw('1')->from('teachers')->whereColumn('teachers.user_id', 'users.id');
                        if (Schema::hasColumn('teachers', 'is_active')) {
                            $profile->where('teachers.is_active', true);
                        }
                        if (Schema::hasColumn('teachers', 'deleted_at')) {
                            $profile->whereNull('teachers.deleted_at');
                        }
                    });
                }
            )
            ->when($branchId !== null, fn ($q) => $q->where('courses.branch_id', $branchId)->where('users.branch_id', $branchId))
            ->select('course_teacher_assignments.*', 'courses.title as course_title', 'users.name as teacher_name', 'users.email as teacher_email')
            ->orderBy('courses.title')->paginate(30);

        return view('course-management::teacher-assignments', compact('courses', 'teachers', 'assignments'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'teacher_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $actorId = (int) $request->user()->getKey();
        $teacherId = (int) $data['teacher_user_id'];

        DB::transaction(function () use ($request, $data, $actorId, $teacherId): void {
            // Shared account lifecycle order: lock both linked accounts in
            // ascending ID order, then lock their canonical role pivots.
            $userIds = collect([$actorId, $teacherId])->unique()->sort()->values()->all();
            $lockedUsers = User::query()
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $actor = $lockedUsers->get($actorId);
            $teacher = $lockedUsers->get($teacherId);
            abort_unless($actor && $actor->is_active, 403, 'Inactive accounts cannot assign course teachers.');
            abort_unless($teacher, 404, 'Teacher account not found.');

            $lockedRolePivots = DB::table('role_user')
                ->whereIn('user_id', $userIds)
                ->orderBy('user_id')
                ->orderBy('role_id')
                ->lockForUpdate()
                ->get();

            // The route middleware is only an early gate. Revalidate the
            // mutable permission grant under a row lock so revocation cannot
            // race this privilege-granting assignment transaction.
            $actorRolePivots = $lockedRolePivots->where('user_id', $actorId)->values();
            $this->assertActorCanManageCourses($actor, $actorRolePivots);

            // BranchAccess::assignedBranchId re-queries the actor's role
            // pivot; those rows are already locked by this transaction.
            $branchId = $this->branches->assignedBranchId($actor);
            $teacherRoleId = Role::query()->where('slug', 'teacher')->value('id');
            $hasTeacherRole = $teacherRoleId !== null && $lockedRolePivots->contains(
                fn ($pivot): bool => (int) $pivot->user_id === $teacherId
                    && (int) $pivot->role_id === (int) $teacherRoleId
            );
            abort_unless((bool) $teacher->is_active && $hasTeacherRole, 422, 'Select an active teacher account.');

            $teacherProfile = null;
            if (app(PluginManager::class)->isEnabled('teacher-management') && Schema::hasTable('teachers')) {
                $profileQuery = DB::table('teachers')->where('user_id', $teacherId);
                if (Schema::hasColumn('teachers', 'deleted_at')) {
                    $profileQuery->whereNull('deleted_at');
                }
                $teacherProfile = $profileQuery->lockForUpdate()->first();
                abort_unless(
                    $teacherProfile
                        && (!Schema::hasColumn('teachers', 'is_active') || (bool) $teacherProfile->is_active),
                    422,
                    'Select a teacher with an active teacher profile.'
                );
            }

            $teacherBranchId = $teacherProfile && $teacherProfile->branch_id !== null
                ? (int) $teacherProfile->branch_id
                : ($teacher->branch_id !== null ? (int) $teacher->branch_id : null);

            $course = DB::table('courses')->where('id', $data['course_id'])->whereNull('deleted_at')
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->lockForUpdate()
                ->first();
            abort_unless($course, 404, 'Course not found in your assigned branch.');

            if ($branchId !== null) {
                abort_unless((int) $course->branch_id === $branchId && $teacherBranchId === $branchId, 404, 'Course and teacher must belong to your assigned branch.');
            }
            abort_if($course->branch_id !== null && $teacherBranchId !== (int) $course->branch_id, 422, 'Teacher and course must belong to the same branch.');

            DB::table('course_teacher_assignments')->updateOrInsert(
                ['course_id' => $course->id, 'teacher_user_id' => $teacher->id],
                ['assigned_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]
            );
        });

        return back()->with('status', 'Teacher assigned to course.');
    }

    /**
     * Recheck the permission used by the route middleware while holding the
     * role assignment and permission grant rows. Admin access is derived from
     * the locked role pivot; non-admin grants are serialized against role
     * permission edits via permission_role.
     *
     * @param \\Illuminate\\Support\\Collection<int, object> $actorRolePivots
     */
    private function assertActorCanManageCourses(User $actor, $actorRolePivots): void
    {
        $adminRoleId = Role::query()->where('slug', 'admin')->value('id');
        if ($adminRoleId !== null && $actorRolePivots->contains(
            fn ($pivot): bool => (int) $pivot->role_id === (int) $adminRoleId
        )) {
            return;
        }

        $permissionId = DB::table('permissions')->where('slug', 'courses.manage')->value('id');
        if ($permissionId === null) {
            abort(403, 'The course-management permission is not configured.');
        }

        $roleIds = $actorRolePivots->pluck('role_id')->map(static fn ($id): int => (int) $id)->all();
        $grantedRoles = DB::table('permission_role')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionId)
            ->orderBy('role_id')
            ->lockForUpdate()
            ->get(['role_id']);

        abort_unless($grantedRoles->isNotEmpty(), 403, 'The acting account no longer has course-management permission.');
    }

    public function destroy(Request $request, int $assignment): RedirectResponse
    {
        $actorId = (int) $request->user()->getKey();

        DB::transaction(function () use ($request, $assignment, $actorId): void {
            $actor = User::query()->whereKey($actorId)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active, 403, 'Inactive accounts cannot manage course teachers.');
            $actorRolePivots = DB::table('role_user')
                ->where('user_id', $actorId)
                ->orderBy('role_id')
                ->lockForUpdate()
                ->get(['role_id']);
            $this->assertActorCanManageCourses($actor, $actorRolePivots);

            $record = DB::table('course_teacher_assignments')
                ->join('courses', 'courses.id', '=', 'course_teacher_assignments.course_id')
                ->where('course_teacher_assignments.id', $assignment)
                ->select('course_teacher_assignments.id', 'courses.branch_id')
                ->lockForUpdate()
                ->first();
            abort_unless($record, 404);
            $this->branches->assertRecord($actor, $record);
            DB::table('course_teacher_assignments')->where('id', $assignment)->delete();
        });

        return back()->with('status', 'Course teacher assignment removed.');
    }
}
