<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\AttendanceManagement\Models\AttendanceBatchMapping;
use Plugins\Modules\AttendanceManagement\Models\AttendanceEntry;
use Plugins\Modules\AttendanceManagement\Models\AttendanceRecord;
use Plugins\Modules\StudentManagement\Models\Student;

class AttendanceSyncController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function sync(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor, 401);
        $this->ensureAssignedBranch($actor);

        $plugins = app(PluginManager::class);
        abort_unless($plugins->isEnabled('student-management') && Schema::hasTable('students'), 409, 'Student Management is not enabled.');
        abort_unless($plugins->isEnabled('course-management') && Schema::hasTable('batches'), 409, 'Course Management is not enabled.');

        $validated = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'attendance_date' => ['required', 'date'],
            'source' => ['nullable', 'in:online,offline'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'records.*.status' => ['required', 'in:present,absent,late,excused'],
            'records.*.remarks' => ['nullable', 'string', 'max:255'],
            'records.*.edit_reason' => ['nullable', 'string', 'max:255'],
            'records.*.sync_uuid' => ['required', 'uuid'],
        ]);

        $batch = DB::table('batches')->where('id', (int) $validated['batch_id'])->first();
        abort_unless($batch, 404, 'Batch not found.');
        $batchMappingId = AttendanceBatchMapping::resolve(
            'course_batch',
            (int) $batch->id,
            isset($batch->branch_id) ? (int) $batch->branch_id : null,
            $batch->name ?? null,
        )->id;

        $result = DB::transaction(function () use ($validated, $batch, $batchMappingId, $actor): array {
            $synced = 0;
            $duplicates = 0;

            foreach ($validated['records'] as $item) {
                $student = Student::query()->findOrFail((int) $item['student_id']);
                abort_if((int) ($batch->branch_id ?? 0) !== (int) ($student->branch_id ?? 0), 422, 'Student and batch must belong to the same branch.');
                abort_if($item['status'] === 'excused' && !$actor->hasPermission('attendance.excuse'), 403, 'You do not have permission to excuse attendance.');
                $rosterConfigured = DB::table('attendance_batch_rosters')
                    ->where('attendance_batch_mapping_id', $batchMappingId)
                    ->where('is_active', true)
                    ->exists();
                if ($rosterConfigured) {
                    abort_unless(DB::table('attendance_batch_rosters')
                        ->where('attendance_batch_mapping_id', $batchMappingId)
                        ->where('student_id', $student->id)
                        ->where('is_active', true)
                        ->exists(), 403, 'Student is not assigned to this batch roster.');
                }
                if ($actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $student->branch_id) {
                    abort(403, 'Branch isolation violation.');
                }

                $existingSync = AttendanceRecord::query()->where('sync_uuid', $item['sync_uuid'])->first();
                if ($existingSync) {
                    abort_if(
                        (int) $existingSync->student_id !== (int) $student->id
                        || (int) $existingSync->batch_id !== (int) $batch->id
                        || $existingSync->attendance_date?->toDateString() !== $validated['attendance_date'],
                        409,
                        'Sync identifier was already used for another attendance record.'
                    );
                    $duplicates++;
                    continue;
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
                abort_if($changed && blank($item['edit_reason'] ?? null), 422, 'An edit reason is required when changing saved attendance.');

                $record ??= new AttendanceRecord();
                $record->fill([
                    'batch_id' => (int) $batch->id,
                    'attendance_batch_mapping_id' => (int) $batchMappingId,
                    'student_id' => (int) $student->id,
                    'branch_id' => (int) $student->branch_id,
                    'attendance_date' => $validated['attendance_date'],
                    'status' => $item['status'],
                    'remarks' => $item['remarks'] ?? null,
                    'marked_by' => $actor->id,
                    'sync_uuid' => $item['sync_uuid'],
                    'source' => $validated['source'] ?? 'offline',
                    'edited_reason' => $changed ? $item['edit_reason'] : null,
                ]);
                $record->save();

                $entry = AttendanceEntry::query()->updateOrCreate(
                    [
                        'attendance_batch_mapping_id' => (int) $batchMappingId,
                        'student_id' => (int) $student->id,
                        'attendance_date' => $validated['attendance_date'],
                    ],
                    [
                        'branch_id' => (int) $student->branch_id,
                        'status' => $record->status,
                        'remarks' => $record->remarks,
                        'marked_by' => $actor->id,
                        'sync_uuid' => $record->sync_uuid,
                        'source' => $record->source ?? 'online',
                        'edited_reason' => $record->edited_reason,
                    ]
                );

                DB::table('attendance_entry_audit_logs')->insert([
                    'attendance_entry_id' => $entry->id,
                    'actor_id' => $actor->id,
                    'event' => $isEdit ? ($changed ? 'updated' : 'reconfirmed') : 'created',
                    'old_status' => $oldStatus,
                    'new_status' => $entry->status,
                    'reason' => $changed ? ($item['edit_reason'] ?? null) : null,
                    'source' => $validated['source'] ?? 'online',
                    'occurred_at' => now(),
                ]);

                DB::table('attendance_audit_logs')->insert([
                    'attendance_record_id' => $record->id,
                    'actor_id' => $actor->id,
                    'event' => $isEdit ? ($changed ? 'updated' : 'reconfirmed') : 'created',
                    'old_status' => $oldStatus,
                    'new_status' => $record->status,
                    'reason' => $changed ? $item['edit_reason'] : null,
                    'source' => $validated['source'] ?? 'offline',
                    'occurred_at' => now(),
                ]);

                $this->audit->log($isEdit ? 'attendance.updated' : 'attendance.marked', 'attendance', (string) $record->id, [
                    'status' => $record->status,
                    'source' => $validated['source'] ?? 'offline',
                    'reason' => $changed ? $item['edit_reason'] : null,
                ]);

                $synced++;
            }

            return compact('synced', 'duplicates');
        });

        return response()->json([
            'status' => 'ok',
            'message' => 'Offline attendance synchronized.',
            'synced' => $result['synced'],
            'duplicates' => $result['duplicates'],
        ]);
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor->hasRole('branch_manager')) {
            return;
        }

        if ($actor->branch_id === null || !app(PluginManager::class)->isEnabled('branch-management') || !Schema::hasTable('branches')) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }

        $active = DB::table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();

        abort_unless($active, 403, 'Branch manager must be assigned to an active branch.');
    }
}
