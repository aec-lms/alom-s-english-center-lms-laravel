<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Admin;

use App\\Http\\Controllers\\Controller;
use App\\Models\\AuditLog;
use App\\Models\\BackupRecord;
use App\\Models\\PluginRecord;
use App\\Models\\SystemSetting;
use App\\Models\\User;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\View\\View;

class DashboardController extends Controller
{
    private const WIDGETS = ['users', 'plugins', 'backups', 'audit_trail', 'system_info'];

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
        $widgets = $this->widgetsFor(auth()->id());

        return view('admin.dashboard', compact('metrics', 'recentAudits', 'widgets'));
    }

    public function saveWidgets(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'widgets' => ['present', 'array', 'max:5'],
            'widgets.*' => ['required', 'string', 'distinct', 'in:' . implode(',', self::WIDGETS)],
        ]);

        $widgets = array_values(array_intersect(self::WIDGETS, $validated['widgets']));
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
        } catch (\\JsonException) {
            return $defaults;
        }

        if (!is_array($stored)) {
            return $defaults;
        }

        return array_values(array_intersect(self::WIDGETS, array_filter($stored, 'is_string')));
    }

    private function preferenceKey(int|string $userId): string
    {
        return 'admin.dashboard.widgets.user.' . $userId;
    }
}
