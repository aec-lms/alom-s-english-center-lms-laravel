<?php

declare(strict_types=1);

namespace Plugins\Modules\ReportsAnalytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PluginRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): View
    {
        $metrics = [
            'users_count' => User::query()->count(),
            'enabled_plugins' => PluginRecord::query()->where('is_enabled', true)->count(),
            'audit_events' => AuditLog::query()->count(),
            'branches_count' => Schema::hasTable('branches') ? \DB::table('branches')->count() : 0,
            'students_count' => Schema::hasTable('students') ? \DB::table('students')->count() : 0,
        ];

        return view('reports-analytics::index', compact('metrics'));
    }

    public function exportAuditCsv(Request $request): StreamedResponse
    {
        $logs = AuditLog::query()->latest('id')->limit(500)->get();

        return response()->streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Actor ID', 'Action', 'Entity Type', 'Entity ID', 'IP', 'Created At']);
            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->actor_id,
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
}
