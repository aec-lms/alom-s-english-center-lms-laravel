<?php

declare(strict_types=1);

namespace Plugins\Modules\GuardianManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\GuardianManagement\Models\Guardian;
use Plugins\Modules\StudentManagement\Models\Student;

class GuardianController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $studentIntegrationEnabled = app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students');
        $query = Guardian::query()
            ->with('user')
            ->when($studentIntegrationEnabled, fn ($q) => $q->with('students.user'))
            ->latest();
        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $query->where('branch_id', $actor->branch_id);
        }
        $guardians = $query->paginate(25);
        if (!$studentIntegrationEnabled) {
            $guardians->getCollection()->each(fn (Guardian $guardian) => $guardian->setRelation('students', collect()));
        }
        $students = $studentIntegrationEnabled
            ? Student::query()->with('user')
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->get()
            : collect();

        return view('guardian-management::index', compact('guardians', 'students', 'studentIntegrationEnabled'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $branchIntegrationEnabled = app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
        $studentIntegrationEnabled = app(PluginManager::class)->isEnabled('student-management') && Schema::hasTable('students');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:12'],
            'branch_id' => $branchIntegrationEnabled ? ['nullable', 'integer', 'exists:branches,id'] : ['nullable', 'integer'],
            'relationship' => ['required', 'string', 'max:60'],
            'student_id' => $studentIntegrationEnabled ? ['nullable', 'integer', 'exists:students,id'] : ['nullable', 'integer'],
        ]);

        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $validated['branch_id']) {
            abort(403, 'Branch isolation violation.');
        }

        if (!empty($validated['student_id'])) {
            abort_unless($studentIntegrationEnabled, 503, 'Enable Student Management before linking a guardian to a student.');
            $student = Student::query()->findOrFail((int) $validated['student_id']);
            if ($student->branch_id !== null && $validated['branch_id'] !== null && (int) $student->branch_id !== (int) $validated['branch_id']) {
                abort(422, 'Guardian and linked student must belong to the same branch.');
            }
        }

        $guardian = DB::transaction(function () use ($validated): Guardian {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'branch_id' => $validated['branch_id'] ?? null,
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

    private function ensureAssignedBranch($actor): void
    {
        if ($actor && $actor->hasRole('branch_manager') && ($actor->branch_id === null || !app(PluginManager::class)->isEnabled('branch-management') || !Schema::hasTable('branches') || !DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists())) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
    }
}
