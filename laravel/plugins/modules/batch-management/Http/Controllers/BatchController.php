<?php

declare(strict_types=1);

namespace Plugins\Modules\BatchManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
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
        $validated = $request->validate([
            'batch_code' => ['required', 'string', 'max:40', 'unique:study_batches,batch_code'],
            'name' => ['required', 'string', 'max:160'],
            'course_title' => ['nullable', 'string', 'max:180'],
            'schedule' => ['required', 'string', 'max:160'],
            'room_number' => ['nullable', 'string', 'max:60'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'start_date' => ['nullable', 'date'],
            'status' => ['required', 'in:upcoming,ongoing,completed'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $batch = StudyBatch::query()->create($validated);
        $this->audit->log('batch.created', 'study_batch', (string) $batch->id);

        return back()->with('status', "Batch '{$batch->name}' created.");
    }

    public function destroy(StudyBatch $batch): RedirectResponse
    {
        $name = $batch->name;
        $id = (string) $batch->id;
        $batch->delete();
        $this->audit->log('batch.deleted', 'study_batch', $id);

        return back()->with('status', "Batch '{$name}' removed.");
    }
}
