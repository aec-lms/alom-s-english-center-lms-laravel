<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\AttendanceManagement\Models\AttendanceBatchMapping;
use Plugins\Modules\AttendanceManagement\Models\AttendanceEntry;
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
        if ($actor->hasRole('branch_manager')) {
            abort_if((int) $mapping->branch_id !== (int) $actor->branch_id, 403, 'Branch isolation violation.');
        }
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
            AttendanceBatchMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();
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
        abort_unless(in_array($mapping->source_type, ['course_batch', 'study_batch'], true), 422, 'Unsupported attendance batch source.');
        $sourceTable = $mapping->source_type === 'course_batch' ? 'batches' : 'study_batches';
        $batch = DB::table($sourceTable)->where('id', $mapping->source_id)
            ->when(Schema::hasColumn($sourceTable, 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->first();
        abort_unless($batch && $batch->branch_id !== null, 422, 'Assign this batch to an active branch before issuing QR attendance.');
        if ($actor->hasRole('branch_manager')) {
            abort_if((int) $actor->branch_id !== (int) $batch->branch_id, 403, 'Branch isolation violation.');
        }

        $validated = $request->validate([
            'expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:30'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:20', 'max:2000'],
        ]);
        $geoFields = array_filter(['latitude', 'longitude', 'radius_meters'], fn ($key) => array_key_exists($key, $validated) && $validated[$key] !== null);
        abort_if(count($geoFields) !== 0 && count($geoFields) !== 3, 422, 'Provide latitude, longitude and radius together to enable GPS verification.');
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes((int) ($validated['expires_in_minutes'] ?? 5));
        $sessionId = DB::table('attendance_qr_sessions')->insertGetId([
            'attendance_batch_mapping_id' => $mapping->id,
            'branch_id' => (int) $batch->branch_id,
            'token_hash' => hash('sha256', $token),
            'issued_by' => $actor->id,
            'expires_at' => $expiresAt,
            'geo_latitude' => $validated['latitude'] ?? null,
            'geo_longitude' => $validated['longitude'] ?? null,
            'geo_radius_meters' => $validated['radius_meters'] ?? null,
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
            'checkin_url' => url('/attendance/qr/check-in'),
        ]);
    }


    public function revoke(Request $request, int $sessionId): JsonResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $session = DB::table('attendance_qr_sessions')->where('id', $sessionId)->first();
        abort_unless($session, 404);
        if ($actor->hasRole('branch_manager')) {
            abort_if((int) $session->branch_id !== (int) $actor->branch_id, 403, 'Branch isolation violation.');
        }

        DB::table('attendance_qr_sessions')->where('id', $sessionId)->whereNull('revoked_at')->update([
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->log('attendance.qr.revoked', 'attendance_qr_session', (string) $sessionId, [], $actor->id);

        return response()->json(['status' => 'ok', 'revoked' => true]);
    }


    public function showCheckin(Request $request)
    {
        abort_unless($request->user() && $request->user()->hasRole('student'), 403);
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);

        return view('attendance-management::qr-checkin', ['token' => $validated['token']]);
    }

    public function checkin(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Only authenticated students can check in.');
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'gps_consent' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_meters' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $student = Student::query()->where('user_id', $actor->id)->firstOrFail();
        $tokenHash = hash('sha256', $validated['token']);

        $result = DB::transaction(function () use ($actor, $student, $tokenHash, $validated): array {
            $session = DB::table('attendance_qr_sessions')->where('token_hash', $tokenHash)->lockForUpdate()->first();
            abort_unless($session, 404, 'QR session not found.');
            abort_if($session->revoked_at !== null, 410, 'QR session has been revoked.');
            abort_if(now()->greaterThanOrEqualTo($session->expires_at), 410, 'QR session has expired.');
            abort_if((int) $session->branch_id !== (int) $student->branch_id, 403, 'Student is outside this batch branch.');

            $mapping = AttendanceBatchMapping::query()->whereKey((int) $session->attendance_batch_mapping_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($mapping->source_type, ['course_batch', 'study_batch'], true), 422, 'Unsupported attendance batch source.');
            $roster = DB::table('attendance_batch_rosters')
                ->where('attendance_batch_mapping_id', $mapping->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)->exists();
            abort_unless($roster, 403, 'Student is not assigned to this batch roster.');

            abort_if(DB::table('attendance_qr_checkins')->where('attendance_qr_session_id', $session->id)->where('student_id', $student->id)->exists(), 409, 'This QR session has already been used by this student.');

            $sourceTable = $mapping->source_type === 'course_batch' ? 'batches' : 'study_batches';
            $batch = DB::table($sourceTable)->where('id', $mapping->source_id)
                ->when(Schema::hasColumn($sourceTable, 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->first();
            abort_unless($batch && (int) $batch->branch_id === (int) $student->branch_id, 403, 'Batch membership is no longer valid.');

            $gpsConsentId = null;
            $gpsVerified = false;
            $gpsAccuracy = null;
            $hasCoordinates = isset($validated['latitude'], $validated['longitude']);
            abort_if($hasCoordinates && !(bool) ($validated['gps_consent'] ?? false), 422, 'GPS coordinates require explicit consent.');
            if ($session->geo_radius_meters !== null) {
                abort_unless((bool) ($validated['gps_consent'] ?? false) && $hasCoordinates, 422, 'This attendance session requires consent-based GPS verification.');
                $gpsAccuracy = (int) ($validated['gps_accuracy_meters'] ?? 1000);
                abort_if($gpsAccuracy > 100, 422, 'GPS accuracy is insufficient for this attendance session.');
                $earthRadius = 6371000;
                $lat1 = deg2rad((float) $session->geo_latitude);
                $lat2 = deg2rad((float) $validated['latitude']);
                $deltaLat = deg2rad((float) $validated['latitude'] - (float) $session->geo_latitude);
                $deltaLon = deg2rad((float) $validated['longitude'] - (float) $session->geo_longitude);
                $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
                $a = min(1.0, max(0.0, $a));
                $distance = 2 * $earthRadius * atan2(sqrt($a), sqrt(1 - $a));
                abort_if($distance > (int) $session->geo_radius_meters, 403, 'You are outside the allowed attendance location.');
                $gpsVerified = true;
            }
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

            $entry = AttendanceEntry::query()
                ->where('attendance_batch_mapping_id', $mapping->id)
                ->where('student_id', $student->id)
                ->whereDate('attendance_date', now()->toDateString())
                ->lockForUpdate()
                ->first();
            if ($entry && $entry->status !== 'present') {
                abort(409, 'Attendance already exists with a different status. Ask the teacher to review it.');
            }
            if (!$entry) {
                $entry = AttendanceEntry::query()->create([
                    'attendance_batch_mapping_id' => (int) $mapping->id,
                    'student_id' => (int) $student->id,
                    'branch_id' => (int) $student->branch_id,
                    'attendance_date' => now()->toDateString(),
                    'status' => 'present',
                    'remarks' => 'QR check-in',
                    'marked_by' => $session->issued_by,
                    'source' => 'qr',
                ]);
                DB::table('attendance_entry_audit_logs')->insert([
                    'attendance_entry_id' => $entry->id,
                    'actor_id' => $actor->id,
                    'event' => 'qr_checkin',
                    'old_status' => null,
                    'new_status' => 'present',
                    'reason' => null,
                    'source' => 'qr',
                    'occurred_at' => now(),
                ]);
            }

            $legacyRecord = null;
            if ($mapping->source_type === 'course_batch') {
                $legacyRecord = AttendanceRecord::query()
                    ->where('batch_id', $batch->id)
                    ->where('student_id', $student->id)
                    ->whereDate('attendance_date', now()->toDateString())
                    ->lockForUpdate()
                    ->first();
                if ($legacyRecord && $legacyRecord->status !== 'present') {
                    abort(409, 'Legacy attendance already exists with a different status. Ask the teacher to review it.');
                }
                if (!$legacyRecord) {
                    $legacyRecord = AttendanceRecord::query()->create([
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
                }
                if (Schema::hasTable('attendance_audit_logs')) {
                    DB::table('attendance_audit_logs')->insert([
                        'attendance_record_id' => $legacyRecord->id,
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
                'attendance_record_id' => $legacyRecord?->id,
                'attendance_entry_id' => $entry->id,
                'gps_consent_id' => $gpsConsentId,
                'gps_verified' => $gpsVerified,
                'gps_accuracy_meters' => $gpsVerified ? $gpsAccuracy : null,
                'source' => 'qr',
                'checked_in_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['record_id' => $legacyRecord?->id, 'entry_id' => $entry->id, 'checked_in_at' => now()->toIso8601String()];
        });

        $this->audit->log('attendance.qr.checked_in', 'attendance_entry', (string) $result['entry_id'], ['student_id' => $student->id], $actor->id);
        return response()->json(['status' => 'ok'] + $result);
    }


    public function revokeGpsConsent(Request $request): JsonResponse
    {
        $actor = $request->user();
        DB::table('attendance_gps_consents')
            ->where('user_id', $actor->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        $this->audit->log('attendance.gps_consent.revoked', 'user', (string) $actor->id, [], $actor->id);

        return response()->json(['status' => 'ok', 'consent_revoked' => true]);
    }

    public function studentHistory(Request $request)
    {
        abort_unless($request->user()->hasRole('student'), 403);
        $student = Student::query()->where('user_id', $request->user()->id)->firstOrFail();
        $records = AttendanceEntry::query()->with('batchIdentity')->where('student_id', $student->id)->latest('attendance_date')->paginate(30);
        return view('attendance-management::history', ['records' => $records, 'title' => 'My attendance']);
    }

    public function guardianHistory(Request $request, int $studentId)
    {
        abort_unless($request->user()->hasRole('guardian'), 403);
        abort_unless(Schema::hasTable('guardians') && Schema::hasTable('guardian_student'), 409, 'Guardian Management is not enabled.');
        $guardian = Guardian::query()->where('user_id', $request->user()->id)->firstOrFail();
        $linked = DB::table('guardian_student')->where('guardian_id', $guardian->id)->where('student_id', $studentId)->exists();
        abort_unless($linked, 403, 'You are not authorized to view this student attendance.');
        $linkedStudent = Student::query()->findOrFail($studentId);
        abort_if($guardian->branch_id !== null && (int) $guardian->branch_id !== (int) $linkedStudent->branch_id, 403, 'Guardian and student branch do not match.');

        $records = AttendanceEntry::query()->with('batchIdentity')
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
        abort_unless(
            $actor->branch_id !== null
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }
}
