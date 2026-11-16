<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\AttendanceManagement\Models\AttendanceRecord;
use Plugins\Modules\BatchManagement\Models\StudyBatch;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\StudentTracking\Services\StudentActivityTracker;

class AttendanceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $records = AttendanceRecord::query()
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest('attendance_date')
            ->paginate(30);

        return view('attendance-management::index', compact('records'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $plugins = app(PluginManager::class);
        abort_unless(
            $plugins->isEnabled('student-management') && Schema::hasTable('students'),
            409,
            'Enable Student Management before recording attendance.'
        );
        abort_unless(
            $plugins->isEnabled('batch-management') && Schema::hasTable('study_batches'),
            409,
            'Enable Batch Management before recording attendance.'
        );
        $validated = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:study_batches,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,late,excused'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $student = Student::query()->findOrFail((int) $validated['student_id']);
        $batch = StudyBatch::query()->findOrFail((int) $validated['batch_id']);
        if ((int) ($batch->branch_id ?? 0) !== (int) ($student->branch_id ?? 0)) {
            abort(422, 'Student and batch must belong to the same branch.');
        }
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $student->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $record = AttendanceRecord::query()->updateOrCreate(
            [
                'batch_id' => (int) $validated['batch_id'],
                'student_id' => (int) $validated['student_id'],
                'attendance_date' => $validated['attendance_date'],
            ],
            [
                'branch_id' => (int) $student->branch_id,
                'status' => $validated['status'],
                'remarks' => $validated['remarks'] ?? null,
                'marked_by' => $actor?->id,
            ]
        );

        if ($plugins->isEnabled('student-tracking')) {
            app(StudentActivityTracker::class)->record((int) $student->user_id, 'attendance_marked', 'attendance', (int) $record->id, null, ['attendance_status' => $record->status, 'source' => 'attendance-register'], (int) ($actor?->id ?? $student->user_id));
        }

        $this->audit->log('attendance.marked', 'attendance', (string) $record->id, ['status' => $record->status]);

        return back()->with('status', 'Attendance saved.');
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null || !app(PluginManager::class)->isEnabled('branch-management') || !Schema::hasTable('branches')) {
            abort(403, 'Branch manager must be assigned to an active branch.');
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
