<?php

declare(strict_types=1);

namespace Plugins\Modules\ReportsAnalytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Core\Plugins\PluginManager;
use App\Models\AuditLog;
use App\Models\PluginRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $isBranchManager = $actor && $actor->hasRole('branch_manager');
        $branchId = $isBranchManager ? (int) $actor->branch_id : null;
        $branchUserIds = $isBranchManager ? $this->branchUserIds($branchId) : collect();

        $metrics = [
            'users_count' => $isBranchManager ? $branchUserIds->count() : User::query()->count(),
            'enabled_plugins' => $isBranchManager ? 'Restricted' : PluginRecord::query()->where('is_enabled', true)->count(),
            'audit_events' => AuditLog::query()->when($isBranchManager, fn ($q) => $q->whereIn('user_id', $branchUserIds))->count(),
            'branches_count' => $isBranchManager ? 1 : (Schema::hasTable('branches') ? DB::table('branches')->count() : 0),
            'students_count' => Schema::hasTable('students')
                ? DB::table('students')->when($isBranchManager, fn ($q) => $q->where('branch_id', $branchId))->count()
                : 0,
            'courses_count' => Schema::hasTable('courses')
                ? DB::table('courses')->when($isBranchManager, fn ($q) => $q->where('branch_id', $branchId))->count()
                : 0,
            'attendance_records' => Schema::hasTable('attendance_records')
                ? DB::table('attendance_records')->when($isBranchManager, fn ($q) => $q->where('branch_id', $branchId))->count()
                : 0,
            'fee_invoices' => Schema::hasTable('fee_invoices')
                ? DB::table('fee_invoices')->when($isBranchManager, fn ($q) => $q->where('branch_id', $branchId))->count()
                : 0,
        ];

        return view('reports-analytics::index', compact('metrics'));
    }

    public function exportAuditCsv(Request $request): StreamedResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);
        $isBranchManager = $actor && $actor->hasRole('branch_manager');
        $branchUserIds = $isBranchManager ? $this->branchUserIds((int) $actor->branch_id) : collect();
        $logs = AuditLog::query()
            ->when($isBranchManager, fn ($q) => $q->whereIn('user_id', $branchUserIds))
            ->latest('id')
            ->limit(500)
            ->get();

        return response()->streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'User ID', 'Action', 'Entity Type', 'Entity ID', 'IP', 'Created At']);
            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->user_id,
                    $this->sanitizeCsvCell((string) $log->action),
                    $this->sanitizeCsvCell((string) $log->entity_type),
                    $this->sanitizeCsvCell((string) $log->entity_id),
                    $this->sanitizeCsvCell((string) $log->ip_address),
                    $log->created_at?->toIso8601String(),
                ]);
            }
            fclose($handle);
        }, 'aec-lms-audit-export.csv', ['Content-Type' => 'text/csv']);
    }

    private function branchUserIds(int $branchId)
    {
        $users = User::query()->where('branch_id', $branchId);

        if (app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'user_id')
            && Schema::hasColumn('students', 'branch_id')) {
            $studentUserIds = DB::table('students')->whereNotNull('user_id')->whereNotNull('branch_id')->distinct()->pluck('user_id');
            $branchStudentUserIds = DB::table('students')
                ->where('branch_id', $branchId)
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id');

            return $users->whereNotIn('id', $studentUserIds)
                ->pluck('id')
                ->merge($branchStudentUserIds)
                ->unique()
                ->values();
        }

        return $users->pluck('id');
    }

    /**
     * Prevent spreadsheet applications from interpreting untrusted audit data
     * as a formula when administrators open an exported CSV.
     */
    private function sanitizeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }
        if ($actor->branch_id === null
            || !app(PluginManager::class)->isEnabled('branch-management')
            || !Schema::hasTable('branches')
        ) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
        $activeBranch = app('db')->table('branches')
            ->where('id', $actor->branch_id)
            ->where('is_active', true)
            ->exists();
        if (!$activeBranch) {
            abort(403, 'Branch manager access is disabled because the assigned branch is inactive.');
        }
    }
}
