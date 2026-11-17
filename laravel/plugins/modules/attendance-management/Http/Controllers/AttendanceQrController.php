<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\AttendanceManagement\Models\AttendanceBatchMapping;
use Plugins\Modules\AttendanceManagement\Models\AttendanceRecord;
use Plugins\Modules\GuardianManagement\Models\Guardian;
use Plugins\Modules\StudentManagement\Models\Student;

class AttendanceQrController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function assignRoster(Request $request, int $mappingId): JsonResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $mapping = AttendanceBatchMapping::query()->findOrFail($mappingId);
        $validated = $request->validate([
            'student_ids' => ['present', 'array', 'max:500'],
            'student_ids.*' => ['required', 'integer', 'distinct', 'exists:students,id'],
        ]);

        $students = Student::query()->whereIn('id', $validated['student_ids'])->get();
        foreach ($students as $student) {
            abort_if($mapping->branch_id !== null && (int) $mapping->branch_id !== (int) $student->branch_id, 422, 'Roster students and batch must belong to the same branch.');
            if ($actor->hasRole('branch_manager')) {
                abort_if((int) $actor->branch_id !== (int) $student->branch_id, 403, 'Branch isolation violation.');
            }
        }

        DB::transaction(function () use ($mapping, $students, $actor): void {
            DB::table('attendance_batch_rosters')->where('attendance_batch_mapping_id', $mapping->id)
                ->update(['is_active' => false, 'updated_at' => now()]);
            foreach ($students as $student) {
                DB::table('attendance_batch_rosters')->updateOrInsert(
                    ['attendance_batch_mapping_id' => $mapping->id, 'student_id' => $student->id],
                    ['assigned_by' => $actor->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        });

        $this->audit->log('attendance.roster.updated', 'attendance_batch_mapping', (string) $mapping->id, [
            'active_student_count' => $students->count(),
            'source_type' => $mapping->source_type,
        ], $actor->id);

        return response()->json(['status' => 'ok', 'assigned' => $students->count()]);
    }

    public function issue(Request $request, int $mappingId): JsonResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $mapping = AttendanceBatchMapping::query()->findOrFail($mappingId);
        // The legacy attendance_records.batch_id is a required FK to batches.
        // Never write a study_batches ID into that legacy column.
        abort_unless($mapping->source_type === 'course_batch', 422, 'QR attendance is not enabled for this batch source yet.');
        $batch = DB::table('batches')->where('id', $mapping->source_id)->first();
        abort_unless($batch, 404, 'Course batch not found.');
        if ($actor->hasRole('branch_manager')) {
            abort_if((int) $actor->branch_id !== (int) $batch->branch_id, 403, 'Branch isolation violation.');
        }

        $validated = $request->validate(['expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:30']]);
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes((int) ($validated['expires_in_minutes'] ?? 5));
        $sessionId = DB::table('attendance_qr_sessions')->insertGetId([
            'attendance_batch_mapping_id' => $mapping->id,
            'branch_id' => (int) $batch->branch_id,
            'token_hash' => hash('sha256', $token),
            'issued_by' => $actor->id,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->log('attendance.qr.issued', 'attendance_qr_session', (string) $sessionId, [
            'batch_mapping_id' => $mapping->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $actor->id);

        return response()->json([
            'status' => 'ok',
            'session_id' => $sessionId,
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'checkin_url' => route('plugins.attendance.qr.checkin'),
        ]);
    }

    public function checkin(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only authenticated students can check in.');
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'gps_consent' => ['nullable', 'boolean'],
        ]);
        $student = Student::query()->where('user_id', $actor->id)->firstOrFail();
        $tokenHash = hash('sha256', $validated['token']);

        $result = DB::transaction(function () use ($actor, $student, $tokenHash, $validated): array {
            $session = DB::table('attendance_qr_sessions')->where('token_hash', $tokenHash)->lockForUpdate()->first();
            abort_unless($session, 404, 'QR session not found.');
            abort_if($session->revoked_at !== null, 410, 'QR session has been revoked.');
            abort_if(now()->greaterThanOrEqualTo($session->expires_at), 410, 'QR session has expired.');
            abort_if((int) $session->branch_id !== (int) $student->branch_id, 403, 'Student is outside this batch branch.');

            $mapping = AttendanceBatchMapping::query()->findOrFail((int) $session->attendance_batch_mapping_id);
            abort_unless($mapping->source_type === 'course_batch', 422, 'Unsupported attendance batch source.');
            $roster = DB::table('attendance_batch_rosters')
                ->where('attendance_batch_mapping_id', $mapping->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)->exists();
            abort_unless($roster, 403, 'Student is not assigned to this batch roster.');

            abort_if(DB::table('attendance_qr_checkins')->where('attendance_qr_session_id', $session->id)->where('student_id', $student->id)->exists(), 409, 'This QR session has already been used by this student.');

            $batch = DB::table('batches')->where('id', $mapping->source_id)->first();
            abort_unless($batch && (int) $batch->branch_id === (int) $student->branch_id, 403, 'Batch membership is no longer valid.');

            $gpsConsentId = null;
            if ((bool) ($validated['gps_consent'] ?? false)) {
                $gpsConsentId = DB::table('attendance_gps_consents')->insertGetId([
                    'user_id' => $actor->id,
                    'policy_version' => 'attendance-gps-v1',
                    'consented_at' => now(),
                    'revoked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $record = AttendanceRecord::query()
                ->where('batch_id', $batch->id)->where('student_id', $student->id)
                ->whereDate('attendance_date', now()->toDateString())->lockForUpdate()->first();
            if ($record && $record->status !== 'present') {
                abort(409, 'Attendance already exists with a different status. Ask the teacher to review it.');
            }

            if (!$record) {
                $record = AttendanceRecord::query()->create([
                    'batch_id' => (int) $batch->id,
                    'attendance_batch_mapping_id' => (int) $mapping->id,
                    'student_id' => (int) $student->id,
                    'branch_id' => (int) $student->branch_id,
                    'attendance_date' => now()->toDateString(),
                    'status' => 'present',
                    'remarks' => 'QR check-in',
                    'marked_by' => $session->issued_by,
                    'source' => 'qr',
                ]);
                if (Schema::hasTable('attendance_audit_logs')) {
                    DB::table('attendance_audit_logs')->insert([
                        'attendance_record_id' => $record->id,
                        'actor_id' => $actor->id,
                        'event' => 'qr_checkin',
                        'old_status' => null,
                        'new_status' => 'present',
                        'reason' => null,
                        'source' => 'qr',
                        'occurred_at' => now(),
                    ]);
                }
            }

            DB::table('attendance_qr_checkins')->insert([
                'attendance_qr_session_id' => $session->id,
                'student_id' => $student->id,
                'attendance_record_id' => $record->id,
                'gps_consent_id' => $gpsConsentId,
                'source' => 'qr',
                'checked_in_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['record_id' => $record->id, 'checked_in_at' => now()->toIso8601String()];
        });

        $this->audit->log('attendance.qr.checked_in', 'attendance_record', (string) $result['record_id'], ['student_id' => $student->id], $actor->id);
        return response()->json(['status' => 'ok'] + $result);
    }

    public function studentHistory(Request $request)
    {
        $student = Student::query()->where('user_id', $request->user()->id)->firstOrFail();
        $records = AttendanceRecord::query()->with('batchIdentity')->where('student_id', $student->id)->latest('attendance_date')->paginate(30);
        return view('attendance-management::history', ['records' => $records, 'title' => 'My attendance']);
    }

    public function guardianHistory(Request $request, int $studentId)
    {
        $guardian = Guardian::query()->where('user_id', $request->user()->id)->firstOrFail();
        $linked = DB::table('guardian_student')->where('guardian_id', $guardian->id)->where('student_id', $studentId)->exists();
        abort_unless($linked, 403, 'You are not authorized to view this student attendance.');

        $records = AttendanceRecord::query()->with('batchIdentity')
            ->where('student_id', $studentId)
            ->when($guardian->branch_id !== null, fn ($query) => $query->where('branch_id', $guardian->branch_id))
            ->latest('attendance_date')->paginate(30);

        return view('attendance-management::history', ['records' => $records, 'title' => 'Student attendance']);
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        abort_if($actor->branch_id === null || !app('db')->table('branches')
            ->where('id', $actor->branch_id)->where('is_active', true)->exists(), 403, 'Branch manager must be assigned to an active branch.');
    }
}
