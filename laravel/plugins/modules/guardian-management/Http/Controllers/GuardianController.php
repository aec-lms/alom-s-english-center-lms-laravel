<?php

declare(strict_types=1);

namespace Plugins\Modules\GuardianManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Plugins\Modules\GuardianManagement\Models\Guardian;
use Plugins\Modules\StudentManagement\Models\Student;

class GuardianController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $query = Guardian::query()->with(['user', 'students.user'])->latest();
        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $query->where('branch_id', $actor->branch_id);
        }
        $guardians = $query->paginate(25);
        $students = Student::query()->with('user')
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->get();

        return view('guardian-management::index', compact('guardians', 'students'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:12'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'relationship' => ['required', 'string', 'max:60'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $validated['branch_id']) {
            abort(403, 'Branch isolation violation.');
        }

        if (!empty($validated['student_id'])) {
            $student = Student::query()->findOrFail((int) $validated['student_id']);
            if ((int) $student->branch_id !== (int) $validated['branch_id']) {
                abort(422, 'Guardian and linked student must belong to the same branch.');
            }
        }

        $guardian = DB::transaction(function () use ($validated): Guardian {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'branch_id' => (int) $validated['branch_id'],
                'is_active' => true,
            ]);
            if ($role = Role::query()->where('slug', 'guardian')->first()) {
                $user->roles()->sync([$role->id]);
            }
            $guardian = Guardian::query()->create([
                'user_id' => $user->id,
                'branch_id' => (int) $validated['branch_id'],
                'relationship' => $validated['relationship'],
                'emergency_phone' => $validated['phone'] ?? null,
            ]);
            if (!empty($validated['student_id'])) {
                $guardian->students()->syncWithoutDetaching([(int) $validated['student_id'] => ['is_primary' => true]]);
            }
            return $guardian;
        });

        $this->audit->log('guardian.created', 'guardian', (string) $guardian->id, ['branch_id' => $guardian->branch_id]);

        return back()->with('status', 'Guardian profile created and linked.');
    }
}
