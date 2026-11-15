<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\PluginRecord;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $metrics = [
            'users' => User::query()->count(),
            'plugins_total' => PluginRecord::query()->count(),
            'plugins_enabled' => PluginRecord::query()->where('is_enabled', true)->count(),
            'backups' => BackupRecord::query()->count(),
            'core_version' => (string) config('lms.core_version', '2.0.0'),
            'php_version' => PHP_VERSION,
        ];
        $recentAudits = AuditLog::query()->with('user')->latest()->limit(10)->get();

        return view('admin.dashboard', compact('metrics', 'recentAudits'));
    }
}
