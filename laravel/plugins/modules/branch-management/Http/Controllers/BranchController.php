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

    public function index(): View
    {
        $branches = Schema::hasTable('branches') ? Branch::query()->latest()->get() : collect();
        return view('branch-management::index', compact('branches'));
    }

    public function store(Request $request): RedirectResponse
    {
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

    public function toggle(Branch $branch): RedirectResponse
    {
        $branch->update(['is_active' => !$branch->is_active]);
        $state = $branch->is_active ? 'activated' : 'deactivated';
        $this->audit->log('branch.toggled', 'branch', (string) $branch->id, ['is_active' => $branch->is_active]);

        return back()->with('status', "Branch '{$branch->name}' {$state}.");
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $name = $branch->name;
        $id = (string) $branch->id;

        DB::transaction(function () use ($branch): void {
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'branch_id')) {
                DB::table('users')->where('branch_id', $branch->id)->update(['branch_id' => null]);
            }
            if (Schema::hasTable('students') && Schema::hasColumn('students', 'branch_id')) {
                DB::table('students')->where('branch_id', $branch->id)->update(['branch_id' => null]);
            }
            $branch->delete();
        });

        $this->audit->log('branch.deleted', 'branch', $id, ['name' => $name]);

        return back()->with('status', "Branch '{$name}' deleted safely (user & student records preserved).");
    }
}
