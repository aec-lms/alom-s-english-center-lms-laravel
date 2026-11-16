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
        $branches = Schema::hasTable('branches') ? DB::table('branches')->orderBy('name')->get() : collect();

        return view('teacher-management::index', compact('teachers', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $branchIntegration = app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
        $branchRule = $branchIntegration
            ? ['nullable', 'integer', 'exists:branches,id,is_active,1']
            : ['nullable', 'integer'];
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:6'],
            'branch_id' => $branchRule,
            'employee_code' => ['required', 'string', 'max:60', 'unique:teachers,employee_code'],
            'specialization' => ['required', 'string', 'max:160'],
            'credentials' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'youtube_url' => ['nullable', 'string', 'max:255'],
            'facebook_url' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'photo_url' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!$branchIntegration) { $validated['branch_id'] = null; }
        if ($actor && $actor->hasRole('branch_manager')) {
            if (isset($validated['branch_id']) && (int) $validated['branch_id'] !== (int) $actor->branch_id) {
                abort(403, 'Branch isolation violation.');
            }
            $validated['branch_id'] = $actor->branch_id;
        }

        $photoPath = $request->hasFile('photo')
            ? $this->storePhoto($request->file('photo'))
            : ($validated['photo_url'] ?? null);

        $teacher = DB::transaction(function () use ($validated, $request, $photoPath): Teacher {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => Hash::make(($validated['password'] ?? null) ?: Str::random(12)),
                'branch_id' => $validated['branch_id'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);

            $teacherRole = Role::query()->where('slug', 'teacher')->first();
            if ($teacherRole) {
                $user->roles()->syncWithoutDetaching([$teacherRole->id]);
            }

            return Teacher::query()->create([
                'user_id' => $user->id,
                'branch_id' => $validated['branch_id'] ?? null,
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
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $teacher->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $branchIntegration = app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
        $branchRule = $branchIntegration
            ? ['nullable', 'integer', 'exists:branches,id,is_active,1']
            : ['nullable', 'integer'];
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190'],
            'branch_id' => $branchRule,
            'employee_code' => ['required', 'string', 'max:60', 'unique:teachers,employee_code,' . $teacher->id],
            'specialization' => ['required', 'string', 'max:160'],
            'credentials' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'youtube_url' => ['nullable', 'string', 'max:255'],
            'facebook_url' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'photo_url' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!$branchIntegration) { $validated['branch_id'] = null; }
        if ($actor && $actor->hasRole('branch_manager')) {
            if (isset($validated['branch_id']) && (int) $validated['branch_id'] !== (int) $actor->branch_id) {
                abort(403, 'Branch isolation violation.');
            }
            $validated['branch_id'] = $actor->branch_id;
        }

        $photoPath = $teacher->photo_path;
        if ($request->hasFile('photo')) {
            $photoPath = $this->storePhoto($request->file('photo'));
        } elseif (!empty($validated['photo_url'])) {
            $photoPath = $validated['photo_url'];
        }

        DB::transaction(function () use ($teacher, $validated, $request, $photoPath): void {
            $isActive = $request->boolean('is_active');
            $teacher->update([
                'branch_id' => $validated['branch_id'] ?? null,
                'employee_code' => strtoupper(trim($validated['employee_code'])),
                'specialization' => $validated['specialization'],
                'credentials' => $validated['credentials'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'youtube_url' => $validated['youtube_url'] ?? null,
                'facebook_url' => $validated['facebook_url'] ?? null,
                'photo_path' => $photoPath,
                'is_active' => $isActive,
            ]);

            if ($teacher->user) {
                $userUpdates = [
                    'name' => $validated['name'],
                    'branch_id' => $validated['branch_id'] ?? null,
                    'is_active' => $isActive,
                ];
                if (!empty($validated['email']) && $validated['email'] !== $teacher->user->email) {
                    $exists = User::query()->where('email', strtolower($validated['email']))->where('id', '!=', $teacher->user->id)->exists();
                    if (!$exists) {
                        $userUpdates['email'] = strtolower($validated['email']);
                    }
                }
                $teacher->user->update($userUpdates);
            }
        });

        $this->audit->log('teacher.updated', 'teacher', (string) $teacher->id, ['code' => $teacher->employee_code]);

        return back()->with('status', "Instructor '{$validated['name']}' updated.");
    }

    public function destroy(Request $request, Teacher $teacher): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $teacher->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $code = $teacher->employee_code;
        $id = (string) $teacher->id;
        $user = $teacher->user;

        DB::transaction(function () use ($teacher, $user): void {
            $teacher->deleted_user_was_active = (bool) $user?->is_active;
            $teacher->save();
            $teacher->update(['is_active' => false]);
            if ($user) {
                $user->update(['is_active' => false]);
            }
            $teacher->delete();
        });

        $this->audit->log('teacher.trashed', 'teacher', $id, ['code' => $code]);

        return back()->with('status', "Instructor '{$code}' moved to the Admin Recycle Bin. Account and course history have been preserved.");
    }

    private function storePhoto(UploadedFile $file): string
    {
        $dir = public_path('uploads/instructors');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return '/uploads/instructors/' . $filename;
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null
            || !app(PluginManager::class)->isEnabled('branch-management')
            || !Schema::hasTable('branches')
        ) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
        $activeBranch = \Illuminate\Support\Facades\DB::table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
