<?php

declare(strict_types=1);

namespace Plugins\Modules\ReportsAnalytics\Http\Controllers;

use App\Http\Controllers\Controller;
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
        $branchUserIds = $isBranchManager
            ? User::query()->where('branch_id', $branchId)->pluck('id')
            : collect();

        $metrics = [
            'users_count' => User::query()->when($isBranchManager, fn ($q) => $q->where('branch_id', $branchId))->count(),
            'enabled_plugins' => PluginRecord::query()->where('is_enabled', true)->count(),
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
        $branchUserIds = $isBranchManager
            ? User::query()->where('branch_id', (int) $actor->branch_id)->pluck('id')
            : collect();
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
                    $log->action,
                    $log->entity_type,
                    $log->entity_id,
                    $log->ip_address,
                    $log->created_at?->toIso8601String(),
                ]);
            }
            fclose($handle);
        }, 'aec-lms-audit-export.csv', ['Content-Type' => 'text/csv']);
    }

    private function ensureAssignedBranch($actor): void
    {
        if ($actor && $actor->hasRole('branch_manager') && $actor->branch_id === null) {
            abort(403, 'Branch manager must be assigned to a branch.');
        }
    }
}
