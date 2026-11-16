<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\AttendanceManagement\Models\AttendanceRecord;

class AttendanceController extends Controller
{
    private const STATUSES = ['present', 'absent', 'late', 'excused'];

    public function __construct(private readonly AuditLogger $audit) {}

    private function authorizeRecorder(Request $request, ?int $branchId = null): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        $isBranchManager = $user->hasRole('branch_manager');
        $allowed = $user->hasRole('admin') || $user->hasRole('teacher')
            || $user->can('attendance.manage') || ($isBranchManager && $user->can('attendance.manage'));
        abort_unless($allowed, 403, 'You are not allowed to record attendance.');
        if ($isBranchManager) {
            abort_unless($user->branch_id !== null, 403, 'Branch manager has no assigned branch.');
        }
        if ($branchId !== null && !$user->hasRole('admin') && $user->branch_id !== null) {
            abort_unless((int) $user->branch_id === $branchId, 403, 'Branch isolation violation.');
        }
    }

    private function assertExcusedAllowed(Request $request, string $status): void
    {
        if ($status === 'excused') {
            abort_unless($request->user()->hasRole('admin') || $request->user()->can('attendance.excuse'), 403, 'Excused attendance requires explicit permission.');
        }
    }

    private function validateLocation(array $data): void
    {
        abort_unless(($data['location_consent'] ?? false) === true, 422, 'Location permission is required for attendance check-in.');
        abort_unless(isset($data['latitude'], $data['longitude']), 422, 'GPS coordinates are required.');
        abort_unless(abs((float) $data['latitude']) <= 90 && abs((float) $data['longitude']) <= 180, 422, 'Invalid GPS coordinates.');
    }

    private function validateBatchStudent(int $batchId, int $studentId): object
    {
        $batch = DB::table('study_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404, 'Batch not found.');
        $student = DB::table('students')->where('id', $studentId)->first();
        abort_unless($student, 404, 'Student not found.');
        abort_unless((int) $student->branch_id === (int) $batch->branch_id, 422, 'Student and batch branch do not match.');
        abort_unless(DB::table('study_batch_students')->where('study_batch_id', $batchId)->where('student_id', $studentId)->exists(), 422, 'Student is not assigned to this batch.');
        return $batch;
    }

    public function offline(Request $request): View
    {
        $this->authorizeRecorder($request);
        $actor = $request->user();
        $batches = DB::table('study_batches')->when(!$actor->hasRole('admin') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))->orderBy('name')->get();
        return view('attendance-management::offline', compact('batches'));
    }

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->authorizeRecorder($request);
        $records = AttendanceRecord::query()
            ->when(!$actor->hasRole('admin') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->filled('attendance_date'), fn ($q) => $q->whereDate('attendance_date', $request->input('attendance_date')))
            ->latest('attendance_date')->paginate(30)->withQueryString();
        $batches = DB::table('study_batches')
            ->when($actor->hasRole('branch_manager'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->orderBy('name')->get();

        return view('attendance-management::index', compact('records', 'batches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRecorder($request);
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:study_batches,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,late,excused'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'location_consent' => ['accepted'],
        ]);
        $this->assertExcusedAllowed($request, $data['status']);
        $batch = $this->validateBatchStudent((int) $data['batch_id'], (int) $data['student_id']);
        $this->authorizeRecorder($request, (int) $batch->branch_id);
        $record = DB::transaction(function () use ($data, $request, $batch): AttendanceRecord {
            $record = AttendanceRecord::query()->firstOrCreate([
                'batch_id' => (int) $data['batch_id'],
                'student_id' => (int) $data['student_id'],
                'attendance_date' => $data['attendance_date'],
            ], [
                'branch_id' => (int) $batch->branch_id,
                'status' => $data['status'],
                'remarks' => $data['remarks'] ?? null,
                'marked_by' => $request->user()->id,
                'source' => 'online',
                'sync_status' => 'synced',
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'location_accuracy' => $data['location_accuracy'] ?? null,
                'location_captured_at' => now(),
            ]);
            DB::table('attendance_record_history')->insert([
                'attendance_record_id' => $record->id, 'actor_id' => $request->user()->id,
                'action' => $record->wasRecentlyCreated ? 'created' : 'duplicate_ignored',
                'new_status' => $record->status, 'metadata' => json_encode(['source' => 'online']),
                'created_at' => now(),
            ]);
            return $record;
        });
        $this->audit->log('attendance.marked', 'attendance', (string) $record->id, ['status' => $record->status]);
        return back()->with('status', $record->wasRecentlyCreated ? 'Attendance saved.' : 'Attendance already exists; duplicate ignored.');
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $this->authorizeRecorder($request);
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:study_batches,id'],
            'attendance_date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'records.*.status' => ['required', 'in:present,absent,late,excused'],
            'records.*.remarks' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'location_consent' => ['accepted'],
        ]);
        $batch = DB::table('study_batches')->where('id', $data['batch_id'])->first();
        abort_unless($batch, 404);
        $this->authorizeRecorder($request, (int) $batch->branch_id);
        $saved = 0; $duplicates = 0;
        DB::transaction(function () use ($data, $batch, $request, &$saved, &$duplicates): void {
            foreach ($data['records'] as $item) {
                $this->assertExcusedAllowed($request, $item['status']);
                $this->validateBatchStudent((int) $data['batch_id'], (int) $item['student_id']);
                $record = AttendanceRecord::query()->firstOrCreate([
                    'batch_id' => (int) $data['batch_id'], 'student_id' => (int) $item['student_id'],
                    'attendance_date' => $data['attendance_date'],
                ], [
                    'branch_id' => (int) $batch->branch_id, 'status' => $item['status'],
                    'remarks' => $item['remarks'] ?? null, 'marked_by' => $request->user()->id,
                    'source' => 'online', 'sync_status' => 'synced',
                    'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
                    'location_accuracy' => $data['location_accuracy'] ?? null, 'location_captured_at' => now(),
                ]);
                $record->wasRecentlyCreated ? $saved++ : $duplicates++;
                DB::table('attendance_record_history')->insert([
                    'attendance_record_id' => $record->id, 'actor_id' => $request->user()->id,
                    'action' => $record->wasRecentlyCreated ? 'created' : 'duplicate_ignored',
                    'new_status' => $record->status, 'metadata' => json_encode(['source' => 'bulk']),
                    'created_at' => now(),
                ]);
            }
        });
        return response()->json(['saved' => $saved, 'duplicates_ignored' => $duplicates]);
    }

    public function history(Request $request, AttendanceRecord $record): JsonResponse
    {
        $this->authorizeRecorder($request, (int) $record->branch_id);
        return response()->json([
            'record' => $record,
            'history' => DB::table('attendance_record_history')
                ->where('attendance_record_id', $record->id)
                ->orderBy('created_at')->get(),
        ]);
    }

    public function update(Request $request, AttendanceRecord $record): JsonResponse
    {
        $this->authorizeRecorder($request, (int) $record->branch_id);
        $data = $request->validate([
            'status' => ['required', 'in:present,absent,late,excused'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $this->assertExcusedAllowed($request, $data['status']);
        DB::transaction(function () use ($record, $data, $request): void {
            $old = $record->status;
            $record->update([
                'status' => $data['status'], 'remarks' => $data['remarks'] ?? $record->remarks,
                'updated_by' => $request->user()->id, 'update_reason' => $data['reason'],
            ]);
            DB::table('attendance_record_history')->insert([
                'attendance_record_id' => $record->id, 'actor_id' => $request->user()->id,
                'action' => 'updated', 'old_status' => $old, 'new_status' => $record->status,
                'reason' => $data['reason'], 'metadata' => json_encode(['ip' => $request->ip()]),
                'created_at' => now(),
            ]);
        });
        $this->audit->log('attendance.updated', 'attendance', (string) $record->id, ['reason' => $data['reason']]);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Attendance updated with audit history.', 'record' => $record->fresh()]);
        }
        return back()->with('status', 'Attendance updated. The reason and change were added to audit history.');
    }

    public function syncOffline(Request $request): JsonResponse
    {
        $this->authorizeRecorder($request);
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:100'],
            'records.*.client_uuid' => ['required', 'uuid'],
            'records.*.batch_id' => ['required', 'integer', 'exists:study_batches,id'],
            'records.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'records.*.attendance_date' => ['required', 'date'],
            'records.*.status' => ['required', 'in:present,absent,late,excused'],
            'records.*.remarks' => ['nullable', 'string', 'max:255'],
            'records.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'records.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'records.*.location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'records.*.location_consent' => ['accepted'],
        ]);
        $results = [];
        foreach ($data['records'] as $item) {
            $this->validateLocation($item);
            $batch = $this->validateBatchStudent((int) $item['batch_id'], (int) $item['student_id']);
            $this->authorizeRecorder($request, (int) $batch->branch_id);
            $this->assertExcusedAllowed($request, $item['status']);
            $existingByUuid = AttendanceRecord::query()->where('client_uuid', $item['client_uuid'])->first();
            if ($existingByUuid) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'synced', 'record_id' => $existingByUuid->id];
                continue;
            }
            $existing = AttendanceRecord::query()->where('batch_id', $item['batch_id'])
                ->where('student_id', $item['student_id'])->whereDate('attendance_date', $item['attendance_date'])->first();
            if ($existing) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'conflict', 'server_record' => $existing->only(['id', 'status', 'updated_at'])];
                continue;
            }
            try {
                $record = AttendanceRecord::query()->create([
                    'batch_id' => $item['batch_id'], 'student_id' => $item['student_id'],
                    'branch_id' => $batch->branch_id, 'attendance_date' => $item['attendance_date'],
                    'status' => $item['status'], 'remarks' => $item['remarks'] ?? null,
                    'marked_by' => $request->user()->id, 'client_uuid' => $item['client_uuid'],
                    'source' => 'offline', 'sync_status' => 'synced',
                    'latitude' => $item['latitude'], 'longitude' => $item['longitude'],
                    'location_accuracy' => $item['location_accuracy'] ?? null, 'location_captured_at' => now(),
                ]);
                DB::table('attendance_record_history')->insert([
                    'attendance_record_id' => $record->id, 'actor_id' => $request->user()->id,
                    'action' => 'offline_sync', 'new_status' => $record->status,
                    'metadata' => json_encode(['client_uuid' => $item['client_uuid']]), 'created_at' => now(),
                ]);
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'synced', 'record_id' => $record->id];
            } catch (\Illuminate\Database\QueryException $exception) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'conflict', 'message' => 'A server record was created concurrently. Review before retrying.'];
            }
        }
        return response()->json(['results' => $results]);
    }

    public function issueQr(Request $request): JsonResponse
    {
        $this->authorizeRecorder($request);
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:study_batches,id'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
        $batch = DB::table('study_batches')->where('id', $data['batch_id'])->first();
        $this->authorizeRecorder($request, (int) $batch->branch_id);
        $token = Str::random(48);
        $id = DB::table('attendance_qr_sessions')->insertGetId([
            'batch_id' => $batch->id, 'branch_id' => $batch->branch_id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($data['expires_in_minutes'] ?? 5),
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['session_id' => $id, 'qr_payload' => $token, 'expires_at' => now()->addMinutes($data['expires_in_minutes'] ?? 5)->toIso8601String()]);
    }

    public function markQr(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'status' => ['nullable', 'in:present,late'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'location_consent' => ['accepted'],
        ]);
        $this->validateLocation($data);
        $session = DB::table('attendance_qr_sessions')->where('token_hash', hash('sha256', $data['token']))->first();
        abort_unless($session && now()->lt($session->expires_at), 422, 'QR code has expired or is invalid.');
        $ownStudent = DB::table('students')->where('user_id', $request->user()->id)->first();
        $studentId = $ownStudent ? (int) $ownStudent->id : (int) ($data['student_id'] ?? 0);
        if (!$ownStudent) {
            $this->authorizeRecorder($request);
            abort_unless($studentId > 0, 422, 'Student ID is required for staff-assisted QR marking.');
        }
        $batch = $this->validateBatchStudent((int) $session->batch_id, $studentId);
        if (!$ownStudent) {
            $this->authorizeRecorder($request, (int) $batch->branch_id);
        } else {
            abort_unless((int) $ownStudent->branch_id === (int) $batch->branch_id, 403);
        }
        $record = AttendanceRecord::query()->firstOrCreate([
            'batch_id' => $session->batch_id, 'student_id' => $studentId, 'attendance_date' => today(),
        ], [
            'branch_id' => $session->branch_id, 'status' => $data['status'] ?? 'present',
            'marked_by' => $request->user()->id, 'source' => 'qr', 'sync_status' => 'synced',
            'qr_session_id' => $session->id, 'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
            'location_accuracy' => $data['location_accuracy'] ?? null, 'location_captured_at' => now(),
        ]);
        DB::table('attendance_record_history')->insert([
            'attendance_record_id' => $record->id, 'actor_id' => $request->user()->id,
            'action' => $record->wasRecentlyCreated ? 'qr_created' : 'duplicate_ignored',
            'new_status' => $record->status,
            'metadata' => json_encode(['qr_session_id' => $session->id]),
            'created_at' => now(),
        ]);
        return response()->json(['status' => $record->wasRecentlyCreated ? 'synced' : 'duplicate', 'record_id' => $record->id]);
    }

    public function portalAttendance(Request $request): View
    {
        $user = $request->user();
        $student = DB::table('students')->where('user_id', $user->id)->first();
        $studentIds = $student ? [(int) $student->id] : [];
        $isGuardian = false;
        if (!$student && Schema::hasTable('guardians') && Schema::hasTable('guardian_student')) {
            $guardian = DB::table('guardians')->where('user_id', $user->id)->first();
            if ($guardian) {
                $studentIds = DB::table('guardian_student')->where('guardian_id', $guardian->id)->pluck('student_id')->map(fn ($id) => (int) $id)->all();
                $isGuardian = true;
            }
        }
        abort_unless($studentIds !== [], 403, 'No linked student attendance is available for this account.');
        $records = AttendanceRecord::query()
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->leftJoin('study_batches', 'study_batches.id', '=', 'attendance_records.batch_id')
            ->whereIn('attendance_records.student_id', $studentIds)
            ->select('attendance_records.*', 'users.name as student_name', 'study_batches.name as batch_name')
            ->orderByDesc('attendance_records.attendance_date')->paginate(30);
        return view('attendance-management::portal', compact('records', 'isGuardian'));
    }

    public function myAttendance(Request $request): JsonResponse
    {
        $student = DB::table('students')->where('user_id', $request->user()->id)->first();
        abort_unless($student, 403, 'Student profile not found.');
        $records = AttendanceRecord::query()->where('student_id', $student->id)
            ->latest('attendance_date')->paginate(50);
        return response()->json($records);
    }

    public function guardianAttendance(Request $request, int $studentId): JsonResponse
    {
        abort_unless(Schema::hasTable('guardians') && Schema::hasTable('guardian_student'), 403, 'Guardian access is not enabled.');
        $guardian = DB::table('guardians')->where('user_id', $request->user()->id)->first();
        abort_unless($guardian && DB::table('guardian_student')->where('guardian_id', $guardian->id)->where('student_id', $studentId)->exists(), 403);
        return response()->json(AttendanceRecord::query()->where('student_id', $studentId)->latest('attendance_date')->paginate(50));
    }

    public function assignStudent(Request $request, int $batchId): JsonResponse
    {
        $this->authorizeRecorder($request);
        $data = $request->validate(['student_id' => ['required', 'integer', 'exists:students,id']]);
        $batch = DB::table('study_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404);
        $this->authorizeRecorder($request, (int) $batch->branch_id);
        $student = DB::table('students')->where('id', $data['student_id'])->first();
        abort_unless((int) $student->branch_id === (int) $batch->branch_id, 422, 'Student and batch branch do not match.');
        DB::table('study_batch_students')->updateOrInsert(
            ['study_batch_id' => $batchId, 'student_id' => $student->id],
            ['created_at' => now(), 'updated_at' => now()]
        );
        return response()->json(['message' => 'Student assigned to batch.']);
    }

    public function roster(Request $request, int $batchId): JsonResponse
    {
        $this->authorizeRecorder($request);
        $batch = DB::table('study_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404);
        $this->authorizeRecorder($request, (int) $batch->branch_id);
        $students = DB::table('study_batch_students')
            ->join('students', 'students.id', '=', 'study_batch_students.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->where('study_batch_students.study_batch_id', $batchId)
            ->orderBy('users.name')
            ->get(['students.id', 'students.student_code', 'users.name']);
        return response()->json(['batch' => $batch, 'students' => $students]);
    }

    public function report(Request $request): JsonResponse
    {
        $this->authorizeRecorder($request);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'batch_id' => ['nullable', 'integer', 'exists:study_batches,id'],
            'academic_level' => ['nullable', 'string', 'max:80'],
            'course_title' => ['nullable', 'string', 'max:180'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $actor = $request->user();
        $query = AttendanceRecord::query()
            ->join('study_batches', 'study_batches.id', '=', 'attendance_records.batch_id')
            ->select('attendance_records.*')
            ->when(!$actor->hasRole('admin') && $actor->branch_id !== null, fn ($q) => $q->where('attendance_records.branch_id', $actor->branch_id))
            ->when(isset($data['branch_id']), fn ($q) => $q->where('attendance_records.branch_id', $data['branch_id']))
            ->when(isset($data['batch_id']), fn ($q) => $q->where('attendance_records.batch_id', $data['batch_id']))
            ->when(isset($data['academic_level']), fn ($q) => $q->where('study_batches.academic_level', $data['academic_level']))
            ->when(isset($data['course_title']), fn ($q) => $q->where('study_batches.course_title', $data['course_title']))
            ->when(isset($data['from']), fn ($q) => $q->whereDate('attendance_records.attendance_date', '>=', $data['from']))
            ->when(isset($data['to']), fn ($q) => $q->whereDate('attendance_records.attendance_date', '<=', $data['to']));
        $summaryQuery = clone $query;
        $summaryQuery->getQuery()->columns = null;
        $summaryQuery->getQuery()->orders = null;
        $groupsQuery = clone $query;
        $groupsQuery->getQuery()->columns = null;
        $groupsQuery->getQuery()->orders = null;
        return response()->json([
            'summary' => $summaryQuery->selectRaw('attendance_records.status, COUNT(*) as total')->groupBy('attendance_records.status')->get(),
            'groups' => $groupsQuery->selectRaw('attendance_records.branch_id, study_batches.course_title, study_batches.academic_level, attendance_records.batch_id, attendance_records.status, COUNT(*) as total')->groupBy('attendance_records.branch_id', 'study_batches.course_title', 'study_batches.academic_level', 'attendance_records.batch_id', 'attendance_records.status')->get(),
            'records' => (clone $query)->orderByDesc('attendance_records.attendance_date')->paginate(100),
        ]);
    }
}
