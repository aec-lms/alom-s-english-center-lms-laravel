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
        $teacherQuery = Teacher::query()
            ->with('user')
            ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->where('branch_id', $actor->branch_id));

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        $specialization = trim((string) $request->query('specialization', ''));

        $totalTeachers = (clone $teacherQuery)->count();
        $activeTeachers = (clone $teacherQuery)->where('is_active', true)->count();
        $inactiveTeachers = $totalTeachers - $activeTeachers;

        $teachers = (clone $teacherQuery)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('employee_code', 'like', '%' . $search . '%')
                        ->orWhere('specialization', 'like', '%' . $search . '%')
                        ->orWhereHas('user', function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($specialization !== '', fn ($query) => $query->where('specialization', $specialization))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $specializations = (clone $teacherQuery)->whereNotNull('specialization')
            ->where('specialization', '!=', '')
            ->distinct()->orderBy('specialization')->pluck('specialization');

        $branches = Schema::hasTable('branches')
            ? DB::table('branches')
                ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->where('id', $actor->branch_id))
                ->orderBy('name')
                ->get()
            : collect();

        $canManageCourseAssignments = $actor
            && $actor->hasPermission('courses.manage')
            && app(PluginManager::class)->isEnabled('course-management');

        return view('teacher-management::index', compact('teachers', 'branches', 'totalTeachers', 'activeTeachers', 'inactiveTeachers', 'search', 'status', 'specialization', 'specializations', 'canManageCourseAssignments'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'branch_id' => array_merge(['nullable', 'integer'], Schema::hasTable('branches') ? [Rule::exists('branches', 'id')] : ['prohibited']),
            'employee_code' => ['required', 'string', 'max:60', 'unique:teachers,employee_code'],
            'specialization' => ['required', 'string', 'max:160'],
            'credentials' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'youtube_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'photo_url' => ['nullable', 'string', 'max:255', 'regex:/^(https:\/\/[^\s]+|\/(?!\/)[A-Za-z0-9._\/-]+)$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
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
                'password' => Hash::make($validated['password']),
                'branch_id' => $validated['branch_id'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);

            $teacherRole = Role::query()->where('slug', 'teacher')->first();
            if (!$teacherRole) {
                throw ValidationException::withMessages(['role' => 'The required teacher role is not configured. Run the role/permission setup before creating teacher accounts.']);
            }
            $user->roles()->syncWithoutDetaching([$teacherRole->id]);

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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($teacher->user_id)],
            'branch_id' => array_merge(['nullable', 'integer'], Schema::hasTable('branches') ? [Rule::exists('branches', 'id')] : ['prohibited']),
            'employee_code' => ['required', 'string', 'max:60', 'unique:teachers,employee_code,' . $teacher->id],
            'specialization' => ['required', 'string', 'max:160'],
            'credentials' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'youtube_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'photo_url' => ['nullable', 'string', 'max:255', 'regex:/^(https:\/\/[^\s]+|\/(?!\/)[A-Za-z0-9._\/-]+)$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($actor && $actor->hasRole('branch_manager')) {
            if (isset($validated['branch_id']) && (int) $validated['branch_id'] !== (int) $actor->branch_id) {
                abort(403, 'Branch isolation violation.');
            }
            $validated['branch_id'] = $actor->branch_id;
        }
        // A partial/API update must not silently detach an existing teacher from their branch.
        $resolvedBranchId = array_key_exists('branch_id', $validated) ? $validated['branch_id'] : $teacher->branch_id;

        $photoPath = $teacher->photo_path;
        if ($request->hasFile('photo')) {
            $photoPath = $this->storePhoto($request->file('photo'));
        } elseif (!empty($validated['photo_url'])) {
            $photoPath = $validated['photo_url'];
        }

        DB::transaction(function () use ($teacher, $validated, $request, $photoPath, $resolvedBranchId): void {
            $isActive = $request->boolean('is_active');
            $teacher->update([
                'branch_id' => $resolvedBranchId,
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
                    'branch_id' => $resolvedBranchId,
                    'is_active' => $isActive,
                ];
                if (array_key_exists('email', $validated) && !empty($validated['email'])) {
                    $userUpdates['email'] = strtolower(trim($validated['email']));
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
            if (Schema::hasColumn('teachers', 'deleted_user_was_active')) {
                $teacher->deleted_user_was_active = (bool) ($user?->is_active ?? false);
                $teacher->save();
            }
            $teacher->delete();
            if ($user) {
                $user->update(['is_active' => false]);
            }
        });

        $this->audit->log('teacher.deleted', 'teacher', $id, ['code' => $code]);

        return back()->with('status', "Instructor '{$code}' removed without affecting shared LMS courses.");
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
