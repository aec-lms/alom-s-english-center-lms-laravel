<?php

declare(strict_types=1);

namespace Plugins\Modules\BatchManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\BatchManagement\Models\StudyBatch;

class BatchController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $batches = Schema::hasTable('study_batches')
            ? StudyBatch::query()
                ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
                ->latest()
                ->get()
            : collect();

        return view('batch-management::index', compact('batches'));
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
            'batch_code' => ['required', 'string', 'max:40', 'unique:study_batches,batch_code'],
            'name' => ['required', 'string', 'max:160'],
            'course_title' => ['nullable', 'string', 'max:180'],
            'schedule' => ['required', 'string', 'max:160'],
            'room_number' => ['nullable', 'string', 'max:60'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'start_date' => ['nullable', 'date'],
            'status' => ['required', 'in:upcoming,ongoing,completed'],
            'branch_id' => $branchRule,
        ]);

        if (!$branchIntegration) {
            $validated['branch_id'] = null;
        }

        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $batch = StudyBatch::query()->create($validated);
        $this->audit->log('batch.created', 'study_batch', (string) $batch->id);

        return back()->with('status', "Batch '{$batch->name}' created.");
    }

    public function update(Request $request, StudyBatch $batch): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $batch->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $branchIntegration = app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches');
        $branchRule = $branchIntegration
            ? ['nullable', 'integer', 'exists:branches,id,is_active,1']
            : ['nullable', 'integer'];
        $validated = $request->validate([
            'batch_code' => ['required', 'string', 'max:40', 'unique:study_batches,batch_code,' . $batch->id],
            'name' => ['required', 'string', 'max:160'],
            'course_title' => ['nullable', 'string', 'max:180'],
            'schedule' => ['required', 'string', 'max:160'],
            'room_number' => ['nullable', 'string', 'max:60'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'start_date' => ['nullable', 'date'],
            'status' => ['required', 'in:upcoming,ongoing,completed'],
            'branch_id' => $branchRule,
        ]);

        if (!$branchIntegration) {
            $validated['branch_id'] = null;
        }
        if ($actor && $actor->hasRole('branch_manager')) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $batch->update($validated);
        $this->audit->log('batch.updated', 'study_batch', (string) $batch->id);

        return back()->with('status', "Batch '{$batch->name}' updated.");
    }

    public function destroy(Request $request, StudyBatch $batch): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $batch->branch_id) {
            abort(403, 'Branch isolation violation.');
        }
        $name = $batch->name;
        $id = (string) $batch->id;
        $batch->delete();
        $this->audit->log('batch.deleted', 'study_batch', $id);

        return back()->with('status', "Batch '{$name}' removed.");
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
