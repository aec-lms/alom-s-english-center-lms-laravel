<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Appearance\ThemeManager;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PluginRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppearanceController extends Controller
{
    public function index(Request $request, ThemeManager $themes): View
    {
        $scope = (string) $request->query('scope', 'global');
        abort_unless($scope === 'global' || preg_match('/^plugin\.[a-z0-9_-]+$/', $scope), 404);

        $colors = $themes->colors($scope);
        $plugins = PluginRecord::query()->where('is_installed', true)->orderBy('name')->get(['slug', 'name']);

        return view('admin.appearance.index', compact('scope', 'colors', 'plugins'));
    }

    public function update(Request $request, ThemeManager $themes): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'regex:/^(global|plugin\.[a-z0-9_-]+)$/'],
            'colors' => ['required', 'array'],
            'colors.*' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $scope = $validated['scope'];
        if (str_starts_with($scope, 'plugin.')) {
            $slug = substr($scope, 7);
            abort_unless(PluginRecord::query()->where('slug', $slug)->where('is_installed', true)->exists(), 404);
        }

        $themes->save($scope, $validated['colors'], auth()->id());
        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => 'appearance.theme.updated',
            'entity_type' => 'theme',
            'entity_id' => $scope,
            'ip_address' => $request->ip(),
            'metadata' => ['scope' => $scope, 'color_tokens' => array_keys($validated['colors'])],
        ]);

        return redirect()->route('admin.appearance.index', ['scope' => $scope])->with('status', 'Theme colors saved successfully.');
    }

    public function reset(Request $request, ThemeManager $themes): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'regex:/^(global|plugin\.[a-z0-9_-]+)$/'],
        ]);

        $scope = $validated['scope'];
        if (str_starts_with($scope, 'plugin.')) {
            abort_unless(PluginRecord::query()->where('slug', substr($scope, 7))->where('is_installed', true)->exists(), 404);
        }

        $themes->reset($scope);
        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => 'appearance.theme.reset',
            'entity_type' => 'theme',
            'entity_id' => $scope,
            'ip_address' => $request->ip(),
            'metadata' => ['scope' => $scope],
        ]);

        return redirect()->route('admin.appearance.index', ['scope' => $scope])->with('status', 'Theme reset to inherited/default colors.');
    }
}
