<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\PluginRecord;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const WIDGETS = ['users', 'plugins', 'backups', 'audit_trail', 'system_info'];

    public function index(Request $request): View
    {
        $actor = $request->user();
        $isBranchManager = $actor && $actor->hasRole('branch_manager');
        if ($isBranchManager) {
            $this->ensureAssignedBranch($actor);
        }

        $metrics = [
            'users' => User::query()->when($isBranchManager, fn ($query) => $query->where('branch_id', $actor->branch_id))->count(),
            'plugins_total' => $isBranchManager ? 0 : PluginRecord::query()->count(),
            'plugins_enabled' => $isBranchManager ? 0 : PluginRecord::query()->where('is_enabled', true)->count(),
            'backups' => $isBranchManager ? 0 : BackupRecord::query()->count(),
            'core_version' => (string) config('lms.core_version', '2.0.0'),
            'php_version' => PHP_VERSION,
        ];
        $recentAudits = $isBranchManager ? collect() : AuditLog::query()->with('user')->latest()->limit(10)->get();
        $widgets = $this->widgetsFor($actor?->id);
        if ($isBranchManager) {
            $widgets = array_values(array_intersect($widgets, ['users']));
        }
        $setupIncomplete = !$isBranchManager && SystemSetting::query()->where('key', 'setup.wizard.completed')->value('value') !== '1';

        return view('admin.dashboard', compact('metrics', 'recentAudits', 'widgets', 'setupIncomplete', 'isBranchManager'));
    }

    public function saveWidgets(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $isBranchManager = $actor && $actor->hasRole('branch_manager');
        if ($isBranchManager) {
            $this->ensureAssignedBranch($actor);
        }
        $allowedWidgets = $isBranchManager ? ['users'] : self::WIDGETS;
        $validated = $request->validate([
            'widgets' => ['present', 'array', 'max:5'],
            'widgets.*' => ['required', 'string', 'distinct', 'in:' . implode(',', $allowedWidgets)],
        ]);

        $widgets = array_values(array_unique(array_filter($validated['widgets'], fn ($widget) => in_array($widget, self::WIDGETS, true))));
        SystemSetting::query()->updateOrCreate(
            ['key' => $this->preferenceKey(auth()->id())],
            ['value' => json_encode($widgets, JSON_THROW_ON_ERROR), 'updated_by' => auth()->id(), 'is_encrypted' => false],
        );

        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => 'admin.dashboard.widgets.updated',
            'entity_type' => 'admin_dashboard',
            'entity_id' => (string) auth()->id(),
            'ip_address' => $request->ip(),
            'metadata' => ['widgets' => $widgets],
        ]);

        return redirect()->route('admin.dashboard')->with('status', 'Dashboard layout saved for your account.');
    }

    private function ensureAssignedBranch($actor): void
    {
        abort_unless(
            $actor->branch_id !== null
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );
    }

    private function widgetsFor(int|string|null $userId): array
    {
        $defaults = self::WIDGETS;
        if (!$userId) {
            return $defaults;
        }

        $value = SystemSetting::query()->where('key', $this->preferenceKey($userId))->value('value');
        if (!is_string($value) || $value === '') {
            return $defaults;
        }

        try {
            $stored = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $defaults;
        }

        if (!is_array($stored)) {
            return $defaults;
        }

        return array_values(array_unique(array_filter($stored, fn ($widget) => is_string($widget) && in_array($widget, self::WIDGETS, true))));
    }

    private function preferenceKey(int|string $userId): string
    {
        return 'admin.dashboard.widgets.user.' . $userId;
    }
}
