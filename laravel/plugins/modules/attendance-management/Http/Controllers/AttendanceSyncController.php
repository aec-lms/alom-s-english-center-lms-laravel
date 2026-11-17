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

        $validated = $request->validate([
            'batch_id' => ['nullable', 'integer', 'required_without:batch_mapping_id', 'exists:batches,id'],
            'batch_mapping_id' => ['nullable', 'integer', 'required_without:batch_id', 'exists:attendance_batch_mappings,id'],
            'attendance_date' => ['required', 'date'],
            'source' => ['nullable', 'in:online,offline'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'records.*.status' => ['required', 'in:present,absent,late,excused'],
            'records.*.remarks' => ['nullable', 'string', 'max:255'],
            'records.*.edit_reason' => ['nullable', 'string', 'max:255'],
            'records.*.sync_uuid' => ['required', 'uuid'],
        ]);

        if (!empty($validated['batch_mapping_id'])) {
            $mapping = AttendanceBatchMapping::query()->findOrFail((int) $validated['batch_mapping_id']);
        } else {
            abort_unless($plugins->isEnabled('course-management') && Schema::hasTable('batches'), 409, 'Course Management is not enabled.');
            $courseBatch = DB::table('batches')->where('id', (int) $validated['batch_id'])->first();
            abort_unless($courseBatch, 404, 'Batch not found.');
            $mapping = AttendanceBatchMapping::resolve(
                'course_batch',
                (int) $courseBatch->id,
                isset($courseBatch->branch_id) ? (int) $courseBatch->branch_id : null,
                $courseBatch->name ?? null,
            );
        }

        abort_unless(in_array($mapping->source_type, ['course_batch', 'study_batch'], true), 422, 'Unsupported attendance batch source.');
        $sourceTable = $mapping->source_type === 'course_batch' ? 'batches' : 'study_batches';
        if ($mapping->source_type === 'study_batch') {
            abort_unless($plugins->isEnabled('batch-management') && Schema::hasTable('study_batches'), 409, 'Batch Management is not enabled.');
        }
        abort_unless(Schema::hasTable($sourceTable), 409, 'The selected batch source is unavailable.');
        $batch = DB::table($sourceTable)->where('id', $mapping->source_id)
            ->when(Schema::hasColumn($sourceTable, 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->first();
        abort_unless($batch && $batch->branch_id !== null, 404, 'Batch not found or not assigned to a branch.');
        $batchMappingId = (int) $mapping->id;

        $result = DB::transaction(function () use ($validated, $batch, $mapping, $batchMappingId, $actor): array {
            $synced = 0;
            $duplicates = 0;

            foreach ($validated['records'] as $item) {
                $student = Student::query()->findOrFail((int) $item['student_id']);
                abort_if((int) $batch->branch_id !== (int) $student->branch_id, 422, 'Student and batch must belong to the same branch.');
                if ($actor->hasRole('branch_manager')) {
                    abort_if((int) $actor->branch_id !== (int) $student->branch_id, 403, 'Branch isolation violation.');
                }
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

                $existingSync = AttendanceEntry::query()->where('sync_uuid', $item['sync_uuid'])->first();
                if ($existingSync) {
                    abort_if(
                        (int) $existingSync->attendance_batch_mapping_id !== $batchMappingId
                        || (int) $existingSync->student_id !== (int) $student->id
                        || $existingSync->attendance_date?->toDateString() !== $validated['attendance_date'],
                        409,
                        'Sync identifier was already used for another attendance record.'
                    );
                    $duplicates++;
                    continue;
                }

                $entry = AttendanceEntry::query()
                    ->where('attendance_batch_mapping_id', $batchMappingId)
                    ->where('student_id', $student->id)
                    ->whereDate('attendance_date', $validated['attendance_date'])
                    ->lockForUpdate()
                    ->first();

                $oldStatus = $entry?->status;
                $oldRemarks = $entry?->remarks;
                $isEdit = $entry !== null;
                $changed = $isEdit && ($oldStatus !== $item['status'] || $oldRemarks !== ($item['remarks'] ?? null));
                abort_if($changed && blank($item['edit_reason'] ?? null), 422, 'An edit reason is required when changing saved attendance.');

                $entry ??= new AttendanceEntry();
                $entry->fill([
                    'attendance_batch_mapping_id' => $batchMappingId,
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
                $entry->save();

                if ($mapping->source_type === 'course_batch') {
                    $legacyRecord = AttendanceRecord::query()
                        ->where('batch_id', $batch->id)
                        ->where('student_id', $student->id)
                        ->whereDate('attendance_date', $validated['attendance_date'])
                        ->lockForUpdate()
                        ->first();
                    $legacyRecord ??= new AttendanceRecord();
                    $legacyRecord->fill([
                        'batch_id' => (int) $batch->id,
                        'attendance_batch_mapping_id' => $batchMappingId,
                        'student_id' => (int) $student->id,
                        'branch_id' => (int) $student->branch_id,
                        'attendance_date' => $validated['attendance_date'],
                        'status' => $entry->status,
                        'remarks' => $entry->remarks,
                        'marked_by' => $actor->id,
                        'sync_uuid' => $item['sync_uuid'],
                        'source' => $validated['source'] ?? 'offline',
                        'edited_reason' => $entry->edited_reason,
                    ]);
                    $legacyRecord->save();

                    DB::table('attendance_audit_logs')->insert([
                        'attendance_record_id' => $legacyRecord->id,
                        'actor_id' => $actor->id,
                        'event' => $isEdit ? ($changed ? 'updated' : 'reconfirmed') : 'created',
                        'old_status' => $oldStatus,
                        'new_status' => $entry->status,
                        'reason' => $changed ? $item['edit_reason'] : null,
                        'source' => $validated['source'] ?? 'offline',
                        'occurred_at' => now(),
                    ]);
                }

                DB::table('attendance_entry_audit_logs')->insert([
                    'attendance_entry_id' => $entry->id,
                    'actor_id' => $actor->id,
                    'event' => $isEdit ? ($changed ? 'updated' : 'reconfirmed') : 'created',
                    'old_status' => $oldStatus,
                    'new_status' => $entry->status,
                    'reason' => $changed ? $item['edit_reason'] : null,
                    'source' => $validated['source'] ?? 'offline',
                    'occurred_at' => now(),
                ]);

                $this->audit->log($isEdit ? 'attendance.updated' : 'attendance.marked', 'attendance_entry', (string) $entry->id, [
                    'status' => $entry->status,
                    'source' => $validated['source'] ?? 'offline',
                    'reason' => $changed ? $item['edit_reason'] : null,
                    'batch_source' => $mapping->source_type,
                ], $actor->id);

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
