<?php

declare(strict_types=1);

namespace Plugins\Modules\StudentManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Plugins\Modules\StudentManagement\Models\Student;

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
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email', ''))),
            'student_code' => $request->filled('student_code')
                ? Str::upper(trim((string) $request->input('student_code')))
                : null,
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', $this->uniqueEmailRule()],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'student_code' => [
                'nullable',
                'string',
                'max:40',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }
                    if (Student::query()->whereRaw('UPPER(student_code) = ?', [Str::upper(trim((string) $value))])->exists()) {
                        $fail('The student code has already been taken.');
                    }
                },
            ],
            'status' => ['nullable', 'in:active,inactive,graduated'],
        ]);

        $code = isset($validated['student_code']) && $validated['student_code'] !== '' ? $validated['student_code'] : null;

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

            try {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'branch_id' => $branchId,
                    'is_active' => ($validated['status'] ?? 'active') === 'active',
                ]);
            } catch (QueryException $exception) {
                if ($this->isStudentIdentityUniqueViolation($exception, 'email')) {
                    throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
                }
                throw $exception;
            }
            $user->roles()->sync([$studentRole->id]);

            $studentAttributes = [
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'admission_date' => now()->toDateString(),
                'status' => $validated['status'] ?? 'active',
            ];
            $student = $code !== null
                ? Student::query()->create($studentAttributes + ['student_code' => $code])
                : $this->createStudentWithGeneratedCode($studentAttributes);

            $this->audit->log('student.created', 'student', (string) $student->id, ['branch_id' => $student->branch_id]);

            return $student;
        });

        return back()->with('status', "Student '{$validated['name']}' ({$student->student_code}) added manually.");
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $actorId = (int) $request->user()->getKey();
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email', ''))),
            'student_code' => $request->filled('student_code')
                ? Str::upper(trim((string) $request->input('student_code')))
                : null,
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', $this->uniqueEmailRule((int) $student->user_id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'student_code' => [
                'required',
                'string',
                'max:40',
                function (string $attribute, mixed $value, \Closure $fail) use ($student): void {
                    if (Student::query()
                        ->whereRaw('UPPER(student_code) = ?', [Str::upper(trim((string) $value))])
                        ->where('id', '<>', $student->id)
                        ->exists()) {
                        $fail('The student code has already been taken.');
                    }
                },
            ],
            'status' => ['required', 'in:active,inactive,graduated'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        DB::transaction(function () use ($validated, $student, $actorId): void {
            $this->assertAuditAvailable();
            [$actor, $user, $lockedStudent] = $this->lockActorAndLinkedStudent($actorId, (int) $student->getKey());
            $this->ensureAssignedBranch($actor);

            if ($actor->hasRole('branch_manager')) {
                abort_unless((int) $actor->branch_id === (int) $lockedStudent->branch_id, 403, 'Branch isolation violation.');
                abort_unless(
                    !isset($validated['branch_id']) || (int) $validated['branch_id'] === (int) $actor->branch_id,
                    403,
                    'Branch isolation violation.'
                );
            }

            $branchId = array_key_exists('branch_id', $validated)
                ? $validated['branch_id']
                : $lockedStudent->branch_id;
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
            try {
                $user->update($userPayload);
                $lockedStudent->update([
                    'student_code' => $validated['student_code'],
                    'branch_id' => $branchId,
                    'status' => $validated['status'],
                ]);
            } catch (QueryException $exception) {
                if ($this->isStudentIdentityUniqueViolation($exception, 'email')) {
                    throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
                }
                if ($this->isStudentIdentityUniqueViolation($exception, 'student_code')) {
                    throw ValidationException::withMessages(['student_code' => 'The student code has already been taken.']);
                }
                throw $exception;
            }

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
            [$actor, $user, $lockedStudent] = $this->lockActorAndLinkedStudent($actorId, (int) $student->getKey());
            $this->ensureAssignedBranch($actor);

            if ($actor->hasRole('branch_manager')) {
                abort_unless((int) $actor->branch_id === (int) $lockedStudent->branch_id, 403, 'Branch isolation violation.');
            }

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

    /**
     * Lock all participating account rows in deterministic ID order before the
     * student profile. Recycle Bin restore uses the same account-before-profile
     * order, preventing the update/delete versus restore deadlock cycle.
     *
     * @return array{0: User, 1: User, 2: Student}
     */
    private function lockActorAndLinkedStudent(int $actorId, int $studentId): array
    {
        // Read only the relationship first; never hold a profile lock while
        // acquiring an account lock.
        $snapshot = Student::query()->whereKey($studentId)->firstOrFail();
        $linkedUserId = (int) $snapshot->user_id;
        abort_unless($linkedUserId > 0, 409, 'The linked student account is missing; no changes were applied.');

        $userIds = array_values(array_unique([$actorId, $linkedUserId]));
        sort($userIds, SORT_NUMERIC);

        $lockedUsers = [];
        foreach ($userIds as $userId) {
            $lockedUsers[$userId] = User::query()->whereKey($userId)->lockForUpdate()->first();
            abort_unless($lockedUsers[$userId], $userId === $linkedUserId ? 409 : 404,
                $userId === $linkedUserId
                    ? 'The linked student account is missing; no changes were applied.'
                    : 'The acting account is unavailable.');
        }

        $lockedStudent = Student::query()->whereKey($studentId)->lockForUpdate()->firstOrFail();
        abort_unless(
            (int) $lockedStudent->user_id === $linkedUserId,
            409,
            'The linked student account changed during this operation; please retry.'
        );

        return [$lockedUsers[$actorId], $lockedUsers[$linkedUserId], $lockedStudent];
    }

    /**
     * Compare normalized addresses so case-variant legacy rows are treated as
     * duplicates consistently on SQLite and MySQL.
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

    /**
     * Retry generated student codes only when the unique index reports a
     * student_code collision. The database constraint remains the final arbiter
     * for concurrent requests; each attempt is isolated by a savepoint.
     *
     * @param array<string, mixed> $attributes
     */
    private function createStudentWithGeneratedCode(array $attributes): Student
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'STU-' . Str::upper(Str::random(10));
            try {
                return DB::transaction(
                    fn (): Student => Student::query()->create($attributes + ['student_code' => $candidate])
                );
            } catch (QueryException $exception) {
                if (!$this->isStudentCodeUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages([
            'student_code' => 'A unique student code could not be generated. Please retry the request.',
        ]);
    }

    private function isStudentIdentityUniqueViolation(QueryException $exception, string $column): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());
        $uniqueViolation = in_array($sqlState, ['23000', '23505'], true)
            && in_array($driverCode, [19, 1062], true);

        return $uniqueViolation && str_contains($message, strtolower($column));
    }

    private function isStudentCodeUniqueViolation(QueryException $exception): bool
    {
        return $this->isStudentIdentityUniqueViolation($exception, 'student_code');
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
