<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceAuditController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAuditAccess($actor);
        $filters = $this->filters($request);

        return view('attendance-management::audit', [
            'entries' => $this->query($actor, $filters)->orderByDesc('logs.occurred_at')->paginate(50)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $actor = $request->user();
        $this->ensureAuditAccess($actor);
        $filters = $this->filters($request);
        $query = $this->query($actor, $filters)->orderBy('logs.id');

        $this->audit->log('attendance.audit.exported', 'attendance_audit', 'csv', [
            'filters' => $filters,
            'branch_id' => $actor->hasRole('branch_manager') ? $actor->branch_id : null,
        ], $actor->id);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Timestamp', 'Student', 'Student code', 'Student email', 'Batch', 'Attendance date', 'Actor', 'Event', 'Previous status', 'New status', 'Source', 'Reason']);

            $query->chunk(500, function ($rows) use ($output): void {
                foreach ($rows as $row) {
                    $values = [
                        $row->occurred_at,
                        $row->student_name,
                        $row->student_code,
                        $row->student_email,
                        $row->batch_name,
                        $row->attendance_date,
                        $row->actor_name,
                        $row->event,
                        $row->old_status,
                        $row->new_status,
                        $row->source,
                        $row->reason,
                    ];
                    fputcsv($output, array_map(fn ($value) => $this->csvSafe((string) ($value ?? '')), $values));
                }
            });

            fclose($output);
        }, 'attendance-audit-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'student_id' => ['nullable', 'integer', 'min:1'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'batch_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:20'],
            'event' => ['nullable', 'in:created,updated,reconfirmed'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function query($actor, array $filters): Builder
    {
        $query = DB::table('attendance_entry_audit_logs as logs')
            ->join('attendance_entries as attendance', 'attendance.id', '=', 'logs.attendance_entry_id')
            ->join('students as profile', 'profile.id', '=', 'attendance.student_id')
            ->join('users as student', 'student.id', '=', 'profile.user_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'logs.actor_id')
            ->leftJoin('attendance_batch_mappings as batch', 'batch.id', '=', 'attendance.attendance_batch_mapping_id')
            ->select([
                'logs.id',
                'logs.occurred_at',
                'logs.event',
                'logs.old_status',
                'logs.new_status',
                'logs.source',
                'logs.reason',
                'attendance.attendance_date',
                'attendance.student_id',
                'attendance.branch_id',
                'attendance.attendance_batch_mapping_id',
                'profile.student_code',
                'student.name as student_name',
                'student.email as student_email',
                'actor.name as actor_name',
                'batch.display_name as batch_name',
            ]);

        if ($actor->hasRole('branch_manager')) {
            $query->where('attendance.branch_id', (int) $actor->branch_id);
        }
        if (!empty($filters['from'])) $query->whereDate('logs.occurred_at', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate('logs.occurred_at', '<=', $filters['to']);
        if (!empty($filters['student_id'])) $query->where('attendance.student_id', (int) $filters['student_id']);
        if (!empty($filters['actor_id'])) $query->where('logs.actor_id', (int) $filters['actor_id']);
        if (!empty($filters['batch_id'])) $query->where('attendance.attendance_batch_mapping_id', (int) $filters['batch_id']);
        if (!empty($filters['source'])) $query->where('logs.source', $filters['source']);
        if (!empty($filters['event'])) $query->where('logs.event', $filters['event']);
        if (!empty($filters['q'])) {
            $term = '%' . $filters['q'] . '%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->where('student.name', 'like', $term)
                    ->orWhere('student.email', 'like', $term)
                    ->orWhere('profile.student_code', 'like', $term)
                    ->orWhere('actor.name', 'like', $term)
                    ->orWhere('logs.reason', 'like', $term);
            });
        }

        return $query;
    }

    private function ensureAuditAccess($actor): void
    {
        abort_unless($actor && ($actor->hasRole('admin') || $actor->hasRole('branch_manager')), 403);
        if ($actor->hasRole('branch_manager')) {
            abort_unless(
                (int) ($actor->branch_id ?? 0) > 0
                    && app(\App\Core\Plugins\PluginManager::class)->isEnabled('branch-management')
                    && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
                403,
                'Branch manager must be assigned to an active branch.'
            );
        }
    }

    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+@\\-\\t\\r]/', $value) ? "'" . $value : $value;
    }
}
