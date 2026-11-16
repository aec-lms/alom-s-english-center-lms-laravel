<?php

declare(strict_types=1);

namespace Plugins\Modules\BranchManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\BranchManagement\Models\Branch;

class BranchController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $branches = Schema::hasTable('branches')
            ? Branch::query()
                ->when($actor && $actor->hasRole('branch_manager'), fn ($query) => $query->whereKey($actor->branch_id))
                ->latest()
                ->get()
            : collect();

        return view('branch-management::index', compact('branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor && $actor->hasRole('branch_manager'), 403, 'Branch managers cannot create branches.');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:branches,code'],
            'name' => ['required', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $branch = Branch::query()->create($validated + ['is_active' => true]);
        $this->audit->log('branch.created', 'branch', (string) $branch->id);

        return back()->with('status', "Branch '{$branch->name}' created.");
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $branch->id) {
            abort(403, 'Branch isolation violation.');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:branches,code,' . $branch->id],
            'name' => ['required', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $branch->update($validated);
        $this->audit->log('branch.updated', 'branch', (string) $branch->id);

        return back()->with('status', "Branch '{$branch->name}' updated.");
    }

    public function toggle(Request $request, Branch $branch): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor && $actor->hasRole('branch_manager'), 403, 'Branch managers cannot deactivate branches.');
        $branch->update(['is_active' => !$branch->is_active]);
        $state = $branch->is_active ? 'activated' : 'deactivated';
        $this->audit->log('branch.toggled', 'branch', (string) $branch->id, ['is_active' => $branch->is_active]);

        return back()->with('status', "Branch '{$branch->name}' {$state}.");
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor && $actor->hasRole('branch_manager'), 403, 'Branch managers cannot delete branches.');

        $branchTables = [
            'users',
            'students',
            'teachers',
            'teacher_profiles',
            'courses',
            'batches',
            'study_batches',
            'enrollments',
            'attendance_records',
            'exams',
            'certificates',
            'fee_invoices',
            'payment_transactions',
            'staff_profiles',
            'staff_payrolls',
            'library_books',
            'library_loans',
            'notices',
            'class_sessions',
            'live_classes',
        ];

        $referencingTables = [];
        foreach ($branchTables as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'branch_id')
                && DB::table($table)->where('branch_id', $branch->id)->exists()) {
                $referencingTables[] = $table;
            }
        }

        if ($referencingTables !== []) {
            return back()->withErrors([
                'branch' => 'This branch still owns records in: ' . implode(', ', $referencingTables)
                    . '. Reassign or archive those records first, then delete the branch.',
            ]);
        }

        $name = $branch->name;
        $id = (string) $branch->id;

        DB::transaction(fn () => $branch->delete());

        $this->audit->log('branch.deleted', 'branch', $id, ['name' => $name]);

        return back()->with('status', "Branch '{$name}' deleted.");
    }
    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
        $activeBranch = app('db')->table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }

}
