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
    private const MOTION_DEFAULTS = [
        'enabled' => true,
        'preset' => 'premium',
        'speed' => 'normal',
        'intensity' => 'medium',
        'loading' => 'shimmer',
        'counters' => true,
        'hover' => true,
    ];

    public function index(Request $request): View
    {
        $actor = $request->user();
        $isBranchManager = $actor && $actor->hasRole('branch_manager');
        if ($isBranchManager) {
            $this->ensureAssignedBranch($actor);
        }

        $metrics = [
            'users' => $isBranchManager
                ? $this->branchUserCount((int) $actor->branch_id)
                : User::query()->count(),
            'plugins_total' => $isBranchManager ? 0 : PluginRecord::query()->count(),
            'plugins_enabled' => $isBranchManager ? 0 : PluginRecord::query()->where('is_enabled', true)->count(),
            'backups' => $isBranchManager ? 0 : BackupRecord::query()->count(),
            'core_version' => (string) config('lms.core_version', '2.0.0'),
            'php_version' => PHP_VERSION,
        ];
        $recentAudits = $isBranchManager ? collect() : AuditLog::query()->with('user')->latest()->limit(10)->get();
        $widgetDefinitions = $this->widgetDefinitions($actor);
        $widgets = $this->widgetsFor($actor?->id, $widgetDefinitions);
        $widgetSizes = $this->widgetSizes($actor?->id, $widgetDefinitions);
        $setupIncomplete = !$isBranchManager && SystemSetting::query()->where('key', 'setup.wizard.completed')->value('value') !== '1';
        $motionSettings = $this->motionSettings();

        return view('admin.dashboard', compact('metrics', 'recentAudits', 'widgets', 'widgetDefinitions', 'widgetSizes', 'setupIncomplete', 'isBranchManager', 'motionSettings'));
    }

    public function saveWidgets(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $isBranchManager = $actor && $actor->hasRole('branch_manager');
        if ($isBranchManager) {
            $this->ensureAssignedBranch($actor);
        }
        $widgetDefinitions = $this->widgetDefinitions($actor);
        $allowedWidgets = array_keys($widgetDefinitions);
        $validated = $request->validate([
            'widgets' => ['present', 'array', 'max:20'],
            'widgets.*' => ['required', 'string', 'distinct', 'in:' . implode(',', $allowedWidgets)],
            'sizes' => ['sometimes', 'array', 'max:20'],
            'sizes.*' => ['sometimes', 'in:small,medium,large'],
        ]);

        $widgets = array_values(array_unique(array_filter(
            $validated['widgets'],
            fn ($widget) => in_array($widget, $allowedWidgets, true)
        )));
        $sizes = [];
        foreach (($validated['sizes'] ?? []) as $widget => $size) {
            if (in_array($widget, $allowedWidgets, true)) $sizes[$widget] = $size;
        }
        SystemSetting::query()->updateOrCreate(
            ['key' => $this->sizePreferenceKey(auth()->id())],
            ['value' => json_encode($sizes, JSON_THROW_ON_ERROR), 'updated_by' => auth()->id(), 'is_encrypted' => false],
        );

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

    public function refresh(Request $request): \\Illuminate\\Http\\JsonResponse
    {
        $actor = $request->user();
        $isBranchManager = $actor->hasRole('branch_manager');
        if ($isBranchManager) {
            $this->ensureAssignedBranch($actor);
        }

        return response()->json([
            'metrics' => [
                'users' => $isBranchManager ? $this->branchUserCount((int) $actor->branch_id) : User::query()->count(),
                'plugins_total' => $isBranchManager ? 0 : PluginRecord::query()->count(),
                'plugins_enabled' => $isBranchManager ? 0 : PluginRecord::query()->where('is_enabled', true)->count(),
                'backups' => $isBranchManager ? 0 : BackupRecord::query()->count(),
            ],
            'refreshed_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function trackWidget(Request $request): \\Illuminate\\Http\\JsonResponse
    {
        $actor = $request->user();
        $definitions = $this->widgetDefinitions($actor);
        $validated = $request->validate([
            'widget' => ['required', 'string', 'in:' . implode(',', array_keys($definitions))],
        ]);
        $key = 'admin.dashboard.analytics.user.' . $actor->id;
        DB::transaction(function () use ($key, $validated, $actor): void {
            $setting = SystemSetting::query()->where('key', $key)->lockForUpdate()->first();
            $analytics = [];
            if ($setting && is_string($setting->value)) {
                try { $analytics = json_decode($setting->value, true, 512, JSON_THROW_ON_ERROR); } catch (\\JsonException) { $analytics = []; }
            }
            if (!is_array($analytics)) $analytics = [];
            $widget = $validated['widget'];
            $analytics[$widget] = min(1000000000, (int) ($analytics[$widget] ?? 0) + 1);
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => json_encode($analytics, JSON_THROW_ON_ERROR), 'updated_by' => $actor->id, 'is_encrypted' => false],
            );
        });

        return response()->json(['tracked' => true], 202)->header('Cache-Control', 'no-store, private');
    }

    public function saveMotionSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'preset' => ['required', 'in:subtle,premium,dynamic'],
            'speed' => ['required', 'in:slow,normal,fast'],
            'intensity' => ['required', 'in:low,medium,high'],
            'loading' => ['required', 'in:shimmer,minimal,none'],
            'counters' => ['sometimes', 'boolean'],
            'hover' => ['sometimes', 'boolean'],
        ]);

        $settings = [
            'enabled' => $request->boolean('enabled'),
            'preset' => $validated['preset'],
            'speed' => $validated['speed'],
            'intensity' => $validated['intensity'],
            'loading' => $validated['loading'],
            'counters' => $request->boolean('counters'),
            'hover' => $request->boolean('hover'),
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => 'dashboard.motion.global'],
            ['value' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_by' => $request->user()->id, 'is_encrypted' => false],
        );

        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'admin.dashboard.motion.updated',
            'entity_type' => 'admin_dashboard',
            'entity_id' => 'global',
            'ip_address' => $request->ip(),
            'metadata' => $settings,
        ]);

        return redirect()->route('admin.dashboard')->with('status', 'Dashboard motion and loading preferences saved.');
    }

    private function motionSettings(): array
    {
        $value = SystemSetting::query()->where('key', 'dashboard.motion.global')->value('value');
        if (!is_string($value) || $value === '') {
            return self::MOTION_DEFAULTS;
        }

        try {
            $stored = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::MOTION_DEFAULTS;
        }

        if (!is_array($stored)) {
            return self::MOTION_DEFAULTS;
        }

        return array_replace(self::MOTION_DEFAULTS, array_intersect_key($stored, self::MOTION_DEFAULTS));
    }

    private function branchUserCount(int $branchId): int
    {
        $users = User::query()->where('branch_id', $branchId);

        if (app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'user_id')
            && Schema::hasColumn('students', 'branch_id')) {
            $studentUserIds = DB::table('students')->whereNotNull('user_id')->whereNotNull('branch_id')->distinct()->pluck('user_id');
            $branchStudentCount = DB::table('students')
                ->where('branch_id', $branchId)
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');

            return $users->whereNotIn('id', $studentUserIds)->count() + $branchStudentCount;
        }

        return $users->count();
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

    /**
     * @return array<string, array{label:string,view:?string,default:bool}>
     */
    private function widgetDefinitions($actor): array
    {
        $definitions = [
            'users' => ['label' => 'Registered Accounts', 'view' => null, 'default' => true],
            'plugins' => ['label' => 'Plugin Overview', 'view' => null, 'default' => true],
            'backups' => ['label' => 'Encrypted Backups', 'view' => null, 'default' => true],
            'audit_trail' => ['label' => 'Security Audit Trail', 'view' => null, 'default' => true],
            'system_info' => ['label' => 'System Information', 'view' => null, 'default' => true],
        ];

        if ($actor && $actor->hasRole('branch_manager')) {
            return ['users' => $definitions['users']];
        }

        $manager = app(PluginManager::class);
        $enabledSlugs = PluginRecord::query()
            ->where('is_installed', true)
            ->where('is_enabled', true)
            ->pluck('slug')
            ->flip();

        foreach ($manager->discoverManifests() as $manifest) {
            if (!$enabledSlugs->has($manifest->slug)) {
                continue;
            }

            foreach ($manifest->dashboardWidgets as $widget) {
                $permission = $widget['permission'];
                if ($permission !== null && (!$actor || !$actor->hasPermission($permission))) {
                    continue;
                }

                $definitions[$widget['key']] = [
                    'label' => $widget['label'],
                    'view' => $manifest->slug . '::' . $widget['view'],
                    'default' => $widget['default'],
                ];
            }
        }

        return $definitions;
    }

    private function widgetsFor(int|string|null $userId, array $definitions): array
    {
        $defaults = array_keys(array_filter($definitions, fn (array $definition): bool => $definition['default']));
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

        $allowedWidgets = array_keys($definitions);
        return array_values(array_unique(array_filter($stored, fn ($widget) => is_string($widget) && in_array($widget, $allowedWidgets, true))));
    }

    private function widgetSizes(int|string|null $userId, array $definitions): array
    {
        $sizes = [];
        foreach ($definitions as $key => $definition) $sizes[$key] = 'medium';
        if (!$userId) return $sizes;
        $value = SystemSetting::query()->where('key', $this->sizePreferenceKey($userId))->value('value');
        if (!is_string($value) || $value === '') return $sizes;
        try { $stored = json_decode($value, true, 512, JSON_THROW_ON_ERROR); } catch (\\JsonException) { return $sizes; }
        if (!is_array($stored)) return $sizes;
        foreach ($stored as $key => $size) {
            if (array_key_exists($key, $sizes) && in_array($size, ['small', 'medium', 'large'], true)) $sizes[$key] = $size;
        }
        return $sizes;
    }

    private function sizePreferenceKey(int|string $userId): string
    {
        return 'admin.dashboard.widget_sizes.user.' . $userId;
    }

    private function preferenceKey(int|string $userId): string
    {
        return 'admin.dashboard.widgets.user.' . $userId;
    }
}
