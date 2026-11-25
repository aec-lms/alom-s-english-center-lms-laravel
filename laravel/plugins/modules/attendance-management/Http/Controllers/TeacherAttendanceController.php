<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\AttendanceManagement\Models\TeacherAttendanceRecord;
use Plugins\Modules\TeacherManagement\Models\Teacher;

class TeacherAttendanceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureBranch($actor);
        abort_unless(Schema::hasTable('teacher_attendance_records') && Schema::hasTable('teachers'), 409, 'Teacher attendance is not installed.');
        $from = $request->validate(['from' => ['nullable','date_format:Y-m-d'], 'to' => ['nullable','date_format:Y-m-d','after_or_equal:from'], 'status' => ['nullable','in:present,absent,late,leave']]);
        $records = TeacherAttendanceRecord::query()->with('teacher.user','marker')
            ->when($actor->hasRole('branch_manager'), fn($q) => $q->where('branch_id',$actor->branch_id))
            ->when($from['from'] ?? null, fn($q,$v) => $q->whereDate('attendance_date','>=',$v))
            ->when($from['to'] ?? null, fn($q,$v) => $q->whereDate('attendance_date','<=',$v))
            ->when($from['status'] ?? null, fn($q,$v) => $q->where('status',$v))
            ->latest('attendance_date')->paginate(30)->withQueryString();
        $teachers = Teacher::query()->with('user')->where('is_active',true)
            ->when($actor->hasRole('branch_manager'), fn($q) => $q->where('branch_id',$actor->branch_id))
            ->orderBy('employee_code')->get();
        $summary = TeacherAttendanceRecord::query()
            ->when($actor->hasRole('branch_manager'), fn($q) => $q->where('branch_id',$actor->branch_id))
            ->whereDate('attendance_date', now()->toDateString())
            ->selectRaw("status, count(*) as total")->groupBy('status')->pluck('total','status');
        return view('attendance-management::teacher', compact('records','teachers','summary','from'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor=$request->user(); $this->ensureBranch($actor);
        $data=$request->validate([
            'teacher_id'=>['required','integer','exists:teachers,id'],
            'attendance_date'=>['required','date_format:Y-m-d','before_or_equal:today'],
            'status'=>['required','in:present,absent,late,leave'],
            'check_in_at'=>['nullable','date'], 'check_out_at'=>['nullable','date','after_or_equal:check_in_at'],
            'remarks'=>['nullable','string','max:255'],
        ]);
        $teacher=Teacher::query()->findOrFail($data['teacher_id']);
        abort_if($actor->hasRole('branch_manager') && (int)$teacher->branch_id !== (int)$actor->branch_id,403,'Branch isolation violation.');
        $record=DB::transaction(function() use($data,$teacher,$actor){
            $record=TeacherAttendanceRecord::query()->where('teacher_id',$teacher->id)->whereDate('attendance_date',$data['attendance_date'])->lockForUpdate()->first() ?? new TeacherAttendanceRecord();
            $record->fill($data+['branch_id'=>$teacher->branch_id,'marked_by'=>$actor->id,'source'=>'admin']);
            $record->save(); return $record;
        });
        $this->audit->log('teacher_attendance.recorded','teacher_attendance',(string)$record->id,['teacher_id'=>$teacher->id,'status'=>$record->status],$actor->id);
        return back()->with('status','Teacher attendance saved.');
    }

    public function punch(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasRole('teacher'), 403);
        abort_unless(app(PluginManager::class)->isEnabled('teacher-management') && Schema::hasTable('teachers'), 409, 'Teacher Management must be enabled.');

        [$record, $checkedOut] = DB::transaction(function () use ($actor): array {
            // Lock the teacher profile so two simultaneous first punches cannot
            // race to create duplicate attendance rows for the same date.
            $teacher = Teacher::query()->where('user_id', $actor->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $today = now()->toDateString();
            $record = TeacherAttendanceRecord::query()
                ->where('teacher_id', $teacher->id)
                ->whereDate('attendance_date', $today)
                ->lockForUpdate()
                ->first();

            if (!$record) {
                $record = new TeacherAttendanceRecord();
                $record->fill([
                    'teacher_id' => $teacher->id,
                    'branch_id' => $teacher->branch_id,
                    'attendance_date' => $today,
                    'status' => 'present',
                    'source' => 'self',
                    'marked_by' => $actor->id,
                    'check_in_at' => now(),
                ]);
                $record->save();
                return [$record, false];
            }

            abort_if($record->check_out_at, 409, 'Today attendance is already checked out.');
            abort_unless($record->check_in_at, 409, 'Check in before checking out.');
            $record->update(['check_out_at' => now()]);
            return [$record->fresh(), true];
        });

        $this->audit->log('teacher_attendance.punch', 'teacher_attendance', (string) $record->id, [
            'check_in_at' => $record->check_in_at?->toISOString(),
            'check_out_at' => $record->check_out_at?->toISOString(),
        ], $actor->id);

        return back()->with('status', $checkedOut ? 'Check-out recorded.' : 'Check-in recorded.');
    }

    private function ensureBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) return;
        abort_unless((int)($actor->branch_id ?? 0)>0 && app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches') && DB::table('branches')->where('id',$actor->branch_id)->where('is_active',true)->exists(),403,'Active branch assignment required.');
    }
}
