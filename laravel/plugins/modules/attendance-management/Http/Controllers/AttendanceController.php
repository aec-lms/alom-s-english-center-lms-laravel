<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\AttendanceManagement\Models\AttendanceRecord;
use Plugins\Modules\StudentManagement\Models\Student;
use Plugins\Modules\StudentTracking\Services\StudentActivityTracker;

class AttendanceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $branchId = $actor->hasRole('branch_manager') ? (int) $actor->branch_id : null;
        $records = AttendanceRecord::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('attendance_date')
            ->paginate(30);
        $batches = DB::table('batches')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();
        $students = Student::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('id')
            ->get();

        return view('attendance-management::index', compact('records', 'batches', 'students'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $plugins = app(PluginManager::class);
        abort_unless($plugins->isEnabled('student-management') && Schema::hasTable('students'), 409, 'Enable Student Management before recording attendance.');
        abort_unless($plugins->isEnabled('course-management') && Schema::hasTable('batches'), 409, 'Enable Course Management before recording attendance.');

        $validated = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'attendance_date' => ['required', 'date'],
            'source' => ['nullable', 'in:online,offline'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'status' => ['nullable', 'in:present,absent,late,excused'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'sync_uuid' => ['nullable', 'uuid'],
            'edit_reason' => ['nullable', 'string', 'max:255'],
            'records' => ['nullable', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required_with:records', 'integer', 'exists:students,id'],
            'records.*.status' => ['required_with:records', 'in:present,absent,late,excused'],
            'records.*.remarks' => ['nullable', 'string', 'max:255'],
            'records.*.sync_uuid' => ['nullable', 'uuid'],
            'records.*.edit_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $batch = DB::table('batches')->where('id', (int) $validated['batch_id'])->first();
        abort_unless($batch, 404, 'Batch not found.');
        if ($actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $batch->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $items = $validated['records'] ?? [[
            'student_id' => $validated['student_id'] ?? null,
            'status' => $validated['status'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'sync_uuid' => $validated['sync_uuid'] ?? null,
            'edit_reason' => $validated['edit_reason'] ?? null,
        ]];
        abort_if(empty($items[0]['student_id']) || empty($items[0]['status']), 422, 'At least one student and attendance status are required.');

        DB::transaction(function () use ($items, $batch, $validated, $actor, $plugins): void {
            foreach ($items as $item) {
                $student = Student::query()->findOrFail((int) $item['student_id']);
                abort_if((int) ($batch->branch_id ?? 0) !== (int) ($student->branch_id ?? 0), 422, 'Student and batch must belong to the same branch.');
                if ($actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $student->branch_id) {
                    abort(403, 'Branch isolation violation.');
                }

                if (!empty($item['sync_uuid'])) {
                    $duplicate = AttendanceRecord::query()->where('sync_uuid', $item['sync_uuid'])->first();
                    if ($duplicate) {
                        abort_if((int) $duplicate->student_id !== (int) $student->id || (int) $duplicate->batch_id !== (int) $batch->id, 409, 'Sync identifier was already used for another attendance record.');
                        continue;
                    }
                }

                $record = AttendanceRecord::query()
                    ->where('batch_id', $batch->id)
                    ->where('student_id', $student->id)
                    ->whereDate('attendance_date', $validated['attendance_date'])
                    ->lockForUpdate()
                    ->first();

                $oldStatus = $record?->status;
                $oldRemarks = $record?->remarks;
                $isEdit = $record !== null;
                $changed = $isEdit && ($oldStatus !== $item['status'] || $oldRemarks !== ($item['remarks'] ?? null));
                $reason = $item['edit_reason'] ?? null;
                abort_if($changed && blank($reason), 422, 'An edit reason is required when changing saved attendance.');

                $record ??= new AttendanceRecord();
                $record->fill([
                    'batch_id' => (int) $batch->id,
                    'student_id' => (int) $student->id,
                    'branch_id' => (int) $student->branch_id,
                    'attendance_date' => $validated['attendance_date'],
                    'status' => $item['status'],
                    'remarks' => $item['remarks'] ?? null,
                    'marked_by' => $actor->id,
                    'sync_uuid' => $item['sync_uuid'] ?? null,
                    'source' => $validated['source'] ?? 'online',
                    'edited_reason' => $changed ? $reason : null,
                ]);
                $record->save();

                DB::table('attendance_audit_logs')->insert([
                    'attendance_record_id' => $record->id,
                    'actor_id' => $actor->id,
                    'event' => $isEdit ? ($changed ? 'updated' : 'reconfirmed') : 'created',
                    'old_status' => $oldStatus,
                    'new_status' => $record->status,
                    'reason' => $changed ? $reason : null,
                    'source' => $validated['source'] ?? 'online',
                    'occurred_at' => now(),
                ]);

                if ($plugins->isEnabled('student-tracking')) {
                    app(StudentActivityTracker::class)->record((int) $student->user_id, 'attendance_marked', 'attendance', (int) $record->id, null, ['attendance_status' => $record->status, 'source' => $validated['source'] ?? 'online'], (int) $actor->id);
                }

                $this->audit->log($isEdit ? 'attendance.updated' : 'attendance.marked', 'attendance', (string) $record->id, ['status' => $record->status, 'source' => $validated['source'] ?? 'online', 'reason' => $changed ? $reason : null]);
            }
        });

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
        $activeBranch = DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
