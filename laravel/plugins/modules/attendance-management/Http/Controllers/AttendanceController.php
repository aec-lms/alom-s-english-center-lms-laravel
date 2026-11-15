<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\AttendanceManagement\Models\AttendanceRecord;

class AttendanceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $records = AttendanceRecord::query()
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest('attendance_date')
            ->paginate(30);

        return view('attendance-management::index', compact('records'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,late,excused'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $student = Student::query()->findOrFail((int) $validated['student_id']);
        $batch = \\Illuminate\\Support\\Facades\\DB::table('batches')->where('id', (int) $validated['batch_id'])->first();
        if (!$batch || (int) $student->branch_id !== (int) $validated['branch_id'] || (int) $batch->branch_id !== (int) $student->branch_id) {
            abort(422, 'Student, batch and attendance branch must match.');
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

        $this->audit->log('attendance.marked', 'attendance', (string) $record->id, ['status' => $record->status]);

        return back()->with('status', 'Attendance saved.');
    }
}
