<?php

declare(strict_types=1);

namespace Plugins\\Modules\\StudentManagement\\Http\\Controllers;

use App\\Core\\Security\\AuditLogger;
use App\\Core\\Plugins\\PluginManager;
use App\\Http\\Controllers\\Controller;
use App\\Models\\Role;
use App\\Models\\User;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\Support\\Str;
use Illuminate\\View\\View;
use Plugins\\Modules\\StudentManagement\\Models\\Student;

class StudentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $query = Student::query()->with('user')->latest();
        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $query->where('branch_id', $actor->branch_id);
        }
        $students = $query->paginate(25);
        $branches = Schema::hasTable('branches')
            ? DB::table('branches')
                ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->where('id', $actor->branch_id))
                ->orderBy('name')
                ->get()
            : collect();

        return view('student-management::index', compact('students', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actorId = (int) $request->user()->getKey();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'student_code' => ['nullable', 'string', 'max:40', 'unique:students,student_code'],
            'status' => ['nullable', 'in:active,inactive,graduated'],
        ]);

        $code = !empty($validated['student_code'])
            ? trim($validated['student_code'])
            : 'STU-' . strtoupper(Str::random(6));

        $student = DB::transaction(function () use ($validated, $code, $actorId): Student {
            $this->assertAuditAvailable();
            $actor = User::query()->lockForUpdate()->findOrFail($actorId);
            $this->ensureAssignedBranch($actor);

            $branchId = $validated['branch_id'] ?? null;
            if ($actor->hasRole('branch_manager')) {
                abort_unless($branchId === null || (int) $branchId === (int) $actor->branch_id, 403, 'Branch isolation violation.');
                $branchId = $actor->branch_id;
            }

            $studentRole = Role::query()->where('slug', 'student')->lockForUpdate()->first();
            abort_unless($studentRole, 503, 'Student role is not configured. Contact an administrator.');

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'branch_id' => $branchId,
                'is_active' => ($validated['status'] ?? 'active') === 'active',
            ]);
            $user->roles()->sync([$studentRole->id]);

            $student = Student::query()->create([
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'student_code' => $code,
                'admission_date' => now()->toDateString(),
                'status' => $validated['status'] ?? 'active',
            ]);

            $this->audit->log('student.created', 'student', (string) $student->id, ['branch_id' => $student->branch_id]);

            return $student;
        });

        return back()->with('status', "Student '{$validated['name']}' ({$student->student_code}) added manually.");
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $actorId = (int) $request->user()->getKey();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email,' . $student->user_id],
            'phone' => ['nullable', 'string', 'max:32'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'student_code' => ['required', 'string', 'max:40', 'unique:students,student_code,' . $student->id],
            'status' => ['required', 'in:active,inactive,graduated'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        DB::transaction(function () use ($validated, $student, $actorId): void {
            $this->assertAuditAvailable();
            $actor = User::query()->lockForUpdate()->findOrFail($actorId);
            $this->ensureAssignedBranch($actor);

            $lockedStudent = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            if ($actor->hasRole('branch_manager')) {
                abort_unless((int) $actor->branch_id === (int) $lockedStudent->branch_id, 403, 'Branch isolation violation.');
                abort_unless(
                    !isset($validated['branch_id']) || (int) $validated['branch_id'] === (int) $actor->branch_id,
                    403,
                    'Branch isolation violation.'
                );
            }

            $user = User::query()->whereKey($lockedStudent->user_id)->lockForUpdate()->first();
            abort_unless($user, 409, 'The linked student account is missing; no changes were applied.');

            $branchId = $validated['branch_id'] ?? null;
            if ($actor->hasRole('branch_manager')) {
                $branchId = $actor->branch_id;
            }

            $before = [
                'student_code' => $lockedStudent->student_code,
                'branch_id' => $lockedStudent->branch_id === null ? null : (string) $lockedStudent->branch_id,
                'status' => $lockedStudent->status,
            ];

            $userPayload = [
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'branch_id' => $branchId,
                'is_active' => $validated['status'] === 'active',
            ];
            if (!empty($validated['password'])) {
                $userPayload['password'] = Hash::make($validated['password']);
            }
            $user->update($userPayload);

            $lockedStudent->update([
                'student_code' => $validated['student_code'],
                'branch_id' => $branchId,
                'status' => $validated['status'],
            ]);

            $after = [
                'student_code' => $lockedStudent->student_code,
                'branch_id' => $lockedStudent->branch_id === null ? null : (string) $lockedStudent->branch_id,
                'status' => $lockedStudent->status,
            ];
            $changes = [];
            foreach (['student_code', 'branch_id', 'status'] as $field) {
                if ($before[$field] !== $after[$field]) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
                }
            }

            $this->audit->log('student.updated', 'student', (string) $lockedStudent->id, ['changes' => $changes]);
        });

        return back()->with('status', "Student '{$validated['name']}' updated.");
    }

    public function destroy(Request $request, Student $student): RedirectResponse
    {
        $actorId = (int) $request->user()->getKey();
        $code = $student->student_code;
        $id = (string) $student->getKey();

        DB::transaction(function () use ($student, $actorId, $id, &$code): void {
            $this->assertAuditAvailable();
            $actor = User::query()->lockForUpdate()->findOrFail($actorId);
            $this->ensureAssignedBranch($actor);

            $lockedStudent = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            if ($actor->hasRole('branch_manager')) {
                abort_unless((int) $actor->branch_id === (int) $lockedStudent->branch_id, 403, 'Branch isolation violation.');
            }

            $user = User::query()->whereKey($lockedStudent->user_id)->lockForUpdate()->first();
            abort_unless($user, 409, 'The linked student account is missing; no changes were applied.');

            $code = $lockedStudent->student_code;
            if (Schema::hasColumn('students', 'deleted_user_was_active')) {
                $lockedStudent->deleted_user_was_active = (bool) $user->is_active;
                $lockedStudent->save();
            }
            $lockedStudent->delete();
            $user->update(['is_active' => false]);

            $this->audit->log('student.deleted', 'student', $id, ['code' => $code]);
        });

        return back()->with('status', "Student '{$code}' removed from active enrollment directory.");
    }

    private function assertAuditAvailable(): void
    {
        abort_unless(Schema::hasTable('audit_logs'), 503, 'Audit logging is unavailable; no student changes were applied.');
    }

    private function ensureAssignedBranch(User $actor): void
    {
        if ($actor->hasRole('branch_manager')) {
            $branchAvailable = $actor->branch_id !== null
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists();

            abort_unless($branchAvailable, 403, 'Branch manager must be assigned to an active branch.');
        }
    }
}
