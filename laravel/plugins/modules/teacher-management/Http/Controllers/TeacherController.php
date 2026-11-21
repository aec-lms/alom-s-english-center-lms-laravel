<?php

declare(strict_types=1);

namespace Plugins\Modules\TeacherManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Plugins\Modules\TeacherManagement\Models\Teacher;

class TeacherController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $teachers = Teacher::query()
            ->with('user')
            ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(25);
        $branches = Schema::hasTable('branches')
            ? DB::table('branches')
                ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->where('id', $actor->branch_id))
                ->orderBy('name')
                ->get()
            : collect();

        return view('teacher-management::index', compact('teachers', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', $this->uniqueEmailRule()],
            'password' => ['nullable', 'string', 'min:6'],
            'branch_id' => array_merge(['nullable', 'integer'], Schema::hasTable('branches') ? [Rule::exists('branches', 'id')] : ['prohibited']),
            'employee_code' => ['required', 'string', 'max:60', 'unique:teachers,employee_code'],
            'specialization' => ['required', 'string', 'max:160'],
            'credentials' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'youtube_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'photo_url' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $actorId = (int) $request->user()->getKey();
        $photoPath = $request->hasFile('photo')
            ? $this->storePhoto($request->file('photo'))
            : ($validated['photo_url'] ?? null);

        $teacher = DB::transaction(function () use ($validated, $request, $photoPath, $actorId): Teacher {
            $actor = User::query()->whereKey($actorId)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active, 403, 'Inactive accounts cannot manage teacher records.');
            $actorRolePivots = $this->lockActorRoleAssignments($actorId);
            $this->assertActorCanManageTeachers($actor, $actorRolePivots);
            $this->ensureAssignedBranch($actor);

            $branchId = $validated['branch_id'] ?? null;
            if ($actor->hasRole('branch_manager')) {
                abort_unless($branchId === null || (int) $branchId === (int) $actor->branch_id, 403, 'Branch isolation violation.');
                $branchId = $actor->branch_id;
            }

            $teacherRole = Role::query()->where('slug', 'teacher')->first();
            if (!$teacherRole) {
                throw ValidationException::withMessages([
                    'role' => 'The required teacher role is not configured. Run the role/permission setup before creating teacher accounts.',
                ]);
            }

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => Hash::make(($validated['password'] ?? '') ?: Str::random(12)),
                'branch_id' => $branchId,
                'is_active' => $request->boolean('is_active', true),
            ]);
            $user->roles()->syncWithoutDetaching([$teacherRole->id]);

            return Teacher::query()->create([
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'employee_code' => strtoupper(trim($validated['employee_code'])),
                'specialization' => $validated['specialization'],
                'credentials' => $validated['credentials'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'youtube_url' => $validated['youtube_url'] ?? null,
                'facebook_url' => $validated['facebook_url'] ?? null,
                'photo_path' => $photoPath,
                'is_active' => $request->boolean('is_active', true),
            ]);
        });

        $this->audit->log('teacher.created', 'teacher', (string) $teacher->id, ['code' => $teacher->employee_code]);

        return back()->with('status', "Instructor '{$validated['name']}' added.");
    }

    public function update(Request $request, Teacher $teacher): RedirectResponse
    {
        $actorId = (int) $request->user()->getKey();
        if ($request->filled('email')) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190', $this->uniqueEmailRule((int) $teacher->user_id)],
            'branch_id' => array_merge(['nullable', 'integer'], Schema::hasTable('branches') ? [Rule::exists('branches', 'id')] : ['prohibited']),
            'employee_code' => ['required', 'string', 'max:60', 'unique:teachers,employee_code,' . $teacher->id],
            'specialization' => ['required', 'string', 'max:160'],
            'credentials' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'youtube_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'photo_url' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $this->storePhoto($request->file('photo'));
        } elseif (!empty($validated['photo_url'])) {
            $photoPath = $validated['photo_url'];
        }

        DB::transaction(function () use ($teacher, $validated, $request, $photoPath, $actorId): void {
            // Lock all participating account rows in deterministic ID order
            // before the Teacher profile, matching Recycle Bin restore.
            $snapshot = Teacher::query()->whereKey($teacher->getKey())->firstOrFail();
            $linkedUserId = (int) $snapshot->user_id;
            abort_unless($linkedUserId > 0, 409, 'The linked teacher account is missing; no changes were applied.');

            $userIds = array_values(array_unique([$actorId, $linkedUserId]));
            sort($userIds, SORT_NUMERIC);

            $lockedUsers = [];
            foreach ($userIds as $userId) {
                $lockedUsers[$userId] = User::query()->whereKey($userId)->lockForUpdate()->first();
                abort_unless($lockedUsers[$userId], $userId === $linkedUserId ? 409 : 404,
                    $userId === $linkedUserId
                        ? 'The linked teacher account is missing; no changes were applied.'
                        : 'The acting account is unavailable.');
            }

            $actor = $lockedUsers[$actorId];
            abort_unless($actor->is_active, 403, 'Inactive accounts cannot manage teacher records.');
            $actorRolePivots = $this->lockActorRoleAssignments($actorId);
            $this->assertActorCanManageTeachers($actor, $actorRolePivots);
            $linkedUser = $lockedUsers[$linkedUserId];
            $lockedTeacher = Teacher::query()->whereKey($teacher->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(
                (int) $lockedTeacher->user_id === $linkedUserId,
                409,
                'The linked teacher account changed during this operation; please retry.'
            );

            $this->ensureAssignedBranch($actor);
            if ($actor->hasRole('branch_manager')) {
                abort_unless((int) $actor->branch_id === (int) $lockedTeacher->branch_id, 403, 'Branch isolation violation.');
                abort_unless(
                    !array_key_exists('branch_id', $validated)
                        || $validated['branch_id'] === null
                        || (int) $validated['branch_id'] === (int) $actor->branch_id,
                    403,
                    'Branch isolation violation.'
                );
            }

            $branchId = array_key_exists('branch_id', $validated)
                ? $validated['branch_id']
                : $lockedTeacher->branch_id;
            if ($actor->hasRole('branch_manager')) {
                $branchId = $actor->branch_id;
            }

            $accountWasActive = (bool) $linkedUser->is_active;
            $profileWasActive = (bool) $lockedTeacher->is_active;
            $isActiveWasExplicit = array_key_exists('is_active', $validated);
            $isActive = $isActiveWasExplicit
                ? $request->boolean('is_active')
                : ($profileWasActive && $accountWasActive);

            // Only an administrator may explicitly reactivate a disabled login.
            if ($isActiveWasExplicit && $isActive && !$accountWasActive) {
                abort_unless($actor->hasRole('admin'), 403, 'Only an administrator may reactivate a disabled teacher account.');
            }

            $lockedTeacher->update([
                'branch_id' => $branchId,
                'employee_code' => strtoupper(trim($validated['employee_code'])),
                'specialization' => $validated['specialization'],
                'credentials' => $validated['credentials'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'youtube_url' => $validated['youtube_url'] ?? null,
                'facebook_url' => $validated['facebook_url'] ?? null,
                'photo_path' => $photoPath ?? $lockedTeacher->photo_path,
                'is_active' => $isActive,
            ]);

            $userUpdates = [
                'name' => $validated['name'],
                'branch_id' => $branchId,
                'is_active' => $isActive,
            ];
            if (array_key_exists('email', $validated) && !empty($validated['email'])) {
                $userUpdates['email'] = strtolower(trim($validated['email']));
            }
            $linkedUser->update($userUpdates);

            $updatedTeacher = $lockedTeacher->fresh();
            if ($accountWasActive !== $isActive) {
                $this->audit->log('teacher.account_status_changed', 'user', (string) $linkedUser->id, [
                    'teacher_id' => (int) $updatedTeacher->id,
                    'before' => $accountWasActive,
                    'after' => $isActive,
                ], $actorId);
            }

            $this->audit->log('teacher.updated', 'teacher', (string) $updatedTeacher->id, ['code' => $updatedTeacher->employee_code]);
        });

        return back()->with('status', "Instructor '{$validated['name']}' updated.");
    }

    public function destroy(Request $request, Teacher $teacher): RedirectResponse
    {
        $actorId = (int) $request->user()->getKey();
        $teacherId = (int) $teacher->getKey();

        $deleted = DB::transaction(function () use ($actorId, $teacherId): array {
            // Match update/restore lock order: account rows by ascending ID,
            // then the Teacher profile. This avoids user/profile lock inversion.
            $snapshot = Teacher::query()->whereKey($teacherId)->firstOrFail();
            $linkedUserId = (int) $snapshot->user_id;
            abort_unless($linkedUserId > 0, 409, 'The linked teacher account is missing; no changes were applied.');

            $userIds = array_values(array_unique([$actorId, $linkedUserId]));
            sort($userIds, SORT_NUMERIC);

            $lockedUsers = [];
            foreach ($userIds as $userId) {
                $lockedUsers[$userId] = User::query()->whereKey($userId)->lockForUpdate()->first();
                abort_unless($lockedUsers[$userId], $userId === $linkedUserId ? 409 : 404,
                    $userId === $linkedUserId
                        ? 'The linked teacher account is missing; no changes were applied.'
                        : 'The acting account is unavailable.');
            }

            $actor = $lockedUsers[$actorId];
            abort_unless($actor->is_active, 403, 'Inactive accounts cannot manage teacher records.');
            $actorRolePivots = $this->lockActorRoleAssignments($actorId);
            $this->assertActorCanManageTeachers($actor, $actorRolePivots);
            $user = $lockedUsers[$linkedUserId];
            $lockedTeacher = Teacher::query()->whereKey($teacherId)->lockForUpdate()->firstOrFail();
            abort_unless(
                (int) $lockedTeacher->user_id === $linkedUserId,
                409,
                'The linked teacher account changed during this operation; please retry.'
            );

            $this->ensureAssignedBranch($actor);
            if ($actor->hasRole('branch_manager')) {
                abort_unless((int) $actor->branch_id === (int) $lockedTeacher->branch_id, 403, 'Branch isolation violation.');
            }

            $userWasActive = (bool) $user->is_active;
            if (Schema::hasColumn('teachers', 'deleted_user_was_active')) {
                $lockedTeacher->deleted_user_was_active = $userWasActive;
                $lockedTeacher->save();
            }

            $code = (string) $lockedTeacher->employee_code;
            $id = (string) $lockedTeacher->getKey();
            $lockedTeacher->delete();
            $user->update(['is_active' => false]);

            $this->audit->log('teacher.deleted', 'teacher', $id, [
                'code' => $code,
                'user_was_active' => $userWasActive,
            ], $actorId);

            return ['code' => $code, 'id' => $id];
        });

        return back()->with('status', "Instructor '{$deleted['code']}' removed without affecting shared LMS courses.");
    }

    /**
     * Compare normalized addresses so case-variant legacy rows are duplicates
     * consistently on SQLite and MySQL.
     */
    private function uniqueEmailRule(?int $exceptUserId = null): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($exceptUserId): void {
            $query = User::query()->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $value))]);
            if ($exceptUserId !== null) {
                $query->where('id', '<>', $exceptUserId);
            }

            if ($query->exists()) {
                $fail('The email has already been taken.');
            }
        };
    }

    private function lockActorRoleAssignments(int $actorId)
    {
        return DB::table('role_user')
            ->where('user_id', $actorId)
            ->orderBy('role_id')
            ->lockForUpdate()
            ->get(['role_id']);
    }

    /**
     * Route middleware is an early gate only. Lock the current non-admin
     * teachers.manage grant so role-permission revocation cannot race teacher
     * creation, update or deletion.
     */
    private function assertActorCanManageTeachers(User $actor, $actorRolePivots): void
    {
        $adminRoleId = Role::query()->where('slug', 'admin')->value('id');
        if ($adminRoleId !== null && $actorRolePivots->contains(
            fn ($pivot): bool => (int) $pivot->role_id === (int) $adminRoleId
        )) {
            return;
        }

        $permissionId = DB::table('permissions')->where('slug', 'teachers.manage')->value('id');
        if ($permissionId === null) {
            abort(403, 'The teacher-management permission is not configured.');
        }

        $roleIds = $actorRolePivots->pluck('role_id')->map(static fn ($id): int => (int) $id)->all();
        $grantedRoles = DB::table('permission_role')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionId)
            ->orderBy('role_id')
            ->lockForUpdate()
            ->get(['role_id']);

        abort_unless($grantedRoles->isNotEmpty(), 403, 'The acting account no longer has teacher-management permission.');
    }

    private function storePhoto(UploadedFile $file): string
    {
        $dir = public_path('uploads/instructors');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $extension = strtolower((string) $file->guessExtension());
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw ValidationException::withMessages([
                'photo' => 'The uploaded instructor photo must be a valid JPG, PNG, or WebP image.',
            ]);
        }

        // Never trust the client-provided filename extension. Derive the stored
        // extension from the server-detected MIME type after image validation.
        $filename = Str::uuid()->toString() . '.' . $extension;
        $file->move($dir, $filename);

        return '/uploads/instructors/' . $filename;
    }

    private function ensureAssignedBranch($actor): void
    {
        if ($actor && $actor->hasRole('branch_manager')) {
            $branchAvailable = $actor->branch_id !== null
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists();

            abort_unless($branchAvailable, 403, 'Branch manager must be assigned to an active branch.');
        }
    }
}
