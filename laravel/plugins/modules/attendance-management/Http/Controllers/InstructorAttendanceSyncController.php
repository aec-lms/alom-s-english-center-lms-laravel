<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Plugins\Modules\AttendanceManagement\Models\AttendanceBatchMapping;
use Plugins\Modules\AttendanceManagement\Models\AttendanceEntry;
use Throwable;

class InstructorAttendanceSyncController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function sync(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('teacher'), 403, 'Teacher accounts only.');
        abort_unless(app(PluginManager::class)->isEnabled('course-management')
            && app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('course_teacher_assignments')
            && Schema::hasTable('batches')
            && Schema::hasTable('students')
            && Schema::hasTable('attendance_entries'), 409, 'Required attendance integrations are unavailable.');

        abort_unless(Schema::hasTable('attendance_gps_consents')
            && DB::table('attendance_gps_consents')->where('user_id', $actor->id)
                ->where('policy_version', 'instructor-offline-gps-v1')
                ->whereNotNull('consented_at')->whereNull('revoked_at')->exists(),
            403,
            'Explicit instructor GPS consent is required before offline attendance sync.'
        );

        $validated = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:100'],
            'records.*.idempotency_key' => ['required', 'uuid'],
            'records.*.batch_id' => ['required', 'integer', 'min:1'],
            'records.*.student_id' => ['required', 'integer', 'min:1'],
            'records.*.attendance_date' => ['required', 'date_format:Y-m-d'],
            'records.*.status' => ['required', 'in:present,absent,late'],
            'records.*.location' => ['required', 'array'],
            'records.*.location.latitude' => ['required', 'numeric', 'between:-90,90'],
            'records.*.location.longitude' => ['required', 'numeric', 'between:-180,180'],
            'records.*.location.accuracy_meters' => ['required', 'numeric', 'min:0', 'max:1000'],
            'records.*.location.captured_at' => ['required', 'date'],
        ]);

        $results = [];
        foreach ($validated['records'] as $item) {
            try {
                $results[] = $this->syncOne($actor, $item);
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
                $response = $exception->getResponse();
                $payload = $response?->getData(true) ?? [];
                $results[] = [
                    'idempotency_key' => $item['idempotency_key'],
                    'status' => 'failed',
                    'message' => $payload['message'] ?? 'Attendance record was rejected.',
                ];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                $results[] = [
                    'idempotency_key' => $item['idempotency_key'],
                    'status' => 'failed',
                    'message' => $exception->getMessage() ?: 'Attendance record was rejected.',
                ];
            } catch (Throwable $exception) {
                report($exception);
                $results[] = [
                    'idempotency_key' => $item['idempotency_key'],
                    'status' => 'failed',
                    'message' => 'The server could not process this attendance record. Retry later.',
                ];
            }
        }

        return response()->json([
            'status' => 'ok',
            'results' => $results,
            'synced' => collect($results)->where('status', 'synced')->count(),
            'duplicates' => collect($results)->where('status', 'duplicate')->count(),
            'conflicts' => collect($results)->where('status', 'conflict')->count(),
            'failed' => collect($results)->where('status', 'failed')->count(),
        ]);
    }

    private function syncOne($actor, array $item): array
    {
        $capturedAt = CarbonImmutable::parse($item['location']['captured_at']);
        $ageSeconds = $capturedAt->diffInSeconds(now(), false);
        if ($ageSeconds < -60 || $ageSeconds > 600 || (float) $item['location']['accuracy_meters'] > 100) {
            return [
                'idempotency_key' => $item['idempotency_key'],
                'status' => 'failed',
                'message' => 'Location is stale or too imprecise. Capture a fresh foreground location and retry.',
            ];
        }

        return DB::transaction(function () use ($actor, $item): array {
            $batch = DB::table('batches')->where('id', (int) $item['batch_id'])
                ->when(Schema::hasColumn('batches', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->first();
            abort_unless($batch && $batch->branch_id !== null, 404, 'Batch not found or not assigned to a branch.');

            $assigned = DB::table('course_teacher_assignments')
                ->where('course_id', (int) $batch->course_id)
                ->where('teacher_user_id', (int) $actor->id)
                ->exists();
            abort_unless($assigned, 403, 'You are not assigned to this course.');

            $student = DB::table('students')->where('id', (int) $item['student_id'])->first();
            abort_unless($student, 404, 'Student not found.');
            abort_unless((int) $student->branch_id === (int) $batch->branch_id, 422, 'Student and batch must belong to the same branch.');
            if ($actor->branch_id !== null) {
                abort_unless((int) $actor->branch_id === (int) $batch->branch_id, 403, 'Branch isolation violation.');
            }

            $mapping = AttendanceBatchMapping::resolve('course_batch', (int) $batch->id, (int) $batch->branch_id, $batch->name ?? null);
            $mapping = AttendanceBatchMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();

            $rosterConfigured = DB::table('attendance_batch_rosters')
                ->where('attendance_batch_mapping_id', $mapping->id)
                ->where('is_active', true)->exists();
            if ($rosterConfigured) {
                abort_unless(DB::table('attendance_batch_rosters')
                    ->where('attendance_batch_mapping_id', $mapping->id)
                    ->where('student_id', (int) $student->id)
                    ->where('is_active', true)->exists(), 403, 'Student is not assigned to this batch roster.');
            }

            $duplicate = AttendanceEntry::query()->where('sync_uuid', $item['idempotency_key'])->first();
            if ($duplicate) {
                abort_if((int) $duplicate->attendance_batch_mapping_id !== (int) $mapping->id
                    || (int) $duplicate->student_id !== (int) $student->id
                    || $duplicate->attendance_date?->toDateString() !== $item['attendance_date'],
                    409, 'This idempotency key was already used for a different attendance record.');

                return ['idempotency_key' => $item['idempotency_key'], 'status' => 'duplicate', 'attendance_id' => $duplicate->id];
            }

            $existing = AttendanceEntry::query()
                ->where('attendance_batch_mapping_id', $mapping->id)
                ->where('student_id', (int) $student->id)
                ->whereDate('attendance_date', $item['attendance_date'])
                ->lockForUpdate()->first();
            if ($existing) {
                return [
                    'idempotency_key' => $item['idempotency_key'],
                    'status' => 'conflict',
                    'attendance_id' => $existing->id,
                    'message' => 'Attendance already exists for this student and date. Resolve the conflict with a reason.',
                ];
            }

            $entry = AttendanceEntry::query()->create([
                'attendance_batch_mapping_id' => $mapping->id,
                'student_id' => (int) $student->id,
                'branch_id' => (int) $student->branch_id,
                'attendance_date' => $item['attendance_date'],
                'status' => $item['status'],
                'remarks' => null,
                'marked_by' => $actor->id,
                'sync_uuid' => $item['idempotency_key'],
                'source' => 'offline',
                'edited_reason' => null,
            ]);

            if (Schema::hasTable('attendance_records')) {
                DB::table('attendance_records')->updateOrInsert(
                    ['batch_id' => (int) $batch->id, 'student_id' => (int) $student->id, 'attendance_date' => $item['attendance_date']],
                    [
                        'attendance_batch_mapping_id' => $mapping->id,
                        'branch_id' => (int) $student->branch_id,
                        'status' => $item['status'],
                        'remarks' => null,
                        'marked_by' => $actor->id,
                        'sync_uuid' => $item['idempotency_key'],
                        'source' => 'offline',
                        'edited_reason' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            if (Schema::hasTable('attendance_entry_audit_logs')) {
                DB::table('attendance_entry_audit_logs')->insert([
                    'attendance_entry_id' => $entry->id, 'actor_id' => $actor->id,
                    'event' => 'created', 'old_status' => null, 'new_status' => $item['status'],
                    'reason' => null, 'source' => 'offline', 'occurred_at' => now(),
                ]);
            }

            if (Schema::hasTable('attendance_audit_logs') && Schema::hasTable('attendance_records')) {
                $recordId = DB::table('attendance_records')->where('sync_uuid', $item['idempotency_key'])->value('id');
                if ($recordId) {
                    DB::table('attendance_audit_logs')->insert([
                        'attendance_record_id' => $recordId, 'actor_id' => $actor->id,
                        'event' => 'created', 'old_status' => null, 'new_status' => $item['status'],
                        'reason' => null, 'source' => 'offline', 'occurred_at' => now(),
                    ]);
                }
            }

            $this->audit->log('attendance.marked', 'attendance_entry', (string) $entry->id, [
                'status' => $entry->status, 'source' => 'offline', 'batch_id' => $batch->id,
                'location_verified' => true, 'location_accuracy_meters' => (float) $item['location']['accuracy_meters'],
            ], (int) $actor->id);

            return ['idempotency_key' => $item['idempotency_key'], 'status' => 'synced', 'attendance_id' => $entry->id];
        });
    }

    public function resolve(Request $request, int $attendance): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('teacher'), 403, 'Teacher accounts only.');
        $data = $request->validate([
            'status' => ['required', 'in:present,absent,late'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $entry = DB::transaction(function () use ($actor, $attendance, $data): AttendanceEntry {
            $record = AttendanceEntry::query()->whereKey($attendance)->lockForUpdate()->firstOrFail();
            $mapping = AttendanceBatchMapping::query()->findOrFail($record->attendance_batch_mapping_id);
            abort_unless($mapping->source_type === 'course_batch', 404);
            $batch = DB::table('batches')->where('id', $mapping->source_id)->first();
            abort_unless($batch && DB::table('course_teacher_assignments')
                ->where('course_id', (int) $batch->course_id)
                ->where('teacher_user_id', (int) $actor->id)->exists(), 403, 'You are not assigned to this course.');
            if ($actor->branch_id !== null) {
                abort_unless((int) $actor->branch_id === (int) $record->branch_id, 403, 'Branch isolation violation.');
            }

            $oldStatus = $record->status;
            $record->update(['status' => $data['status'], 'edited_reason' => trim($data['reason']), 'marked_by' => $actor->id, 'source' => 'offline']);
            if (Schema::hasTable('attendance_records')) {
                DB::table('attendance_records')->where('batch_id', (int) $batch->id)
                    ->where('student_id', (int) $record->student_id)->whereDate('attendance_date', $record->attendance_date)
                    ->update(['status' => $data['status'], 'edited_reason' => trim($data['reason']), 'marked_by' => $actor->id, 'source' => 'offline', 'updated_at' => now()]);
            }
            if (Schema::hasTable('attendance_entry_audit_logs')) {
                DB::table('attendance_entry_audit_logs')->insert([
                    'attendance_entry_id' => $record->id, 'actor_id' => $actor->id,
                    'event' => 'updated', 'old_status' => $oldStatus, 'new_status' => $data['status'],
                    'reason' => trim($data['reason']), 'source' => 'offline', 'occurred_at' => now(),
                ]);
            }
            if (Schema::hasTable('attendance_audit_logs') && Schema::hasTable('attendance_records')) {
                $legacyId = DB::table('attendance_records')->where('batch_id', (int) $batch->id)
                    ->where('student_id', (int) $record->student_id)->whereDate('attendance_date', $record->attendance_date)->value('id');
                if ($legacyId) {
                    DB::table('attendance_audit_logs')->insert([
                        'attendance_record_id' => $legacyId, 'actor_id' => $actor->id,
                        'event' => 'updated', 'old_status' => $oldStatus, 'new_status' => $data['status'],
                        'reason' => trim($data['reason']), 'source' => 'offline', 'occurred_at' => now(),
                    ]);
                }
            }
            $this->audit->log('attendance.conflict_resolved', 'attendance_entry', (string) $record->id, [
                'old_status' => $oldStatus, 'new_status' => $data['status'], 'reason' => trim($data['reason']),
            ], (int) $actor->id);

            return $record->fresh();
        });

        return response()->json(['message' => 'Attendance conflict resolved.', 'attendance_id' => $entry->id, 'status' => $entry->status]);
    }
}
