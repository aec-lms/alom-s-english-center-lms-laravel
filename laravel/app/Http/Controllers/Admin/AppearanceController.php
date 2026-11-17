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
        $design = $themes->design($scope);
        $plugins = PluginRecord::query()->where('is_installed', true)->orderBy('name')->get(['slug', 'name']);
        return view('admin.appearance.index', compact('scope', 'colors', 'design', 'plugins'));
    }

    public function update(Request $request, ThemeManager $themes): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'regex:/^(global|plugin\.[a-z0-9_-]+)$/'],
            'colors' => ['required', 'array'],
            'colors.*' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $scope = $validated['scope'];
        $this->assertScopeExists($scope);
        $themes->save($scope, $validated['colors'], auth()->id());
        $this->audit($request, 'appearance.theme.updated', $scope, ['color_tokens' => array_keys($validated['colors'])]);
        return redirect()->route('admin.appearance.index', ['scope' => $scope])->with('status', 'Theme colors saved successfully.');
    }

    public function updateDesign(Request $request, ThemeManager $themes): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'regex:/^(global|plugin\.[a-z0-9_-]+)$/'],
            'design.font_family' => ['required', 'in:plus-jakarta,inter,noto-sans,system'],
            'design.font_size' => ['required', 'in:14,15,16,17,18'],
            'design.heading_weight' => ['required', 'in:600,700,800,900'],
            'design.content_width' => ['required', 'in:960,1120,1280,1440,1600'],
            'design.border_radius' => ['required', 'in:8,12,16,20,24'],
            'design.density' => ['required', 'in:compact,comfortable'],
        ]);
        $scope = $validated['scope'];
        $this->assertScopeExists($scope);
        $themes->saveDesign($scope, $validated['design'], auth()->id());
        $this->audit($request, 'appearance.design.updated', $scope, ['design_tokens' => array_keys($validated['design'])]);
        return redirect()->route('admin.appearance.index', ['scope' => $scope])->with('status', 'Typography and layout settings saved.');
    }

    public function reset(Request $request, ThemeManager $themes): RedirectResponse
    {
        $validated = $request->validate(['scope' => ['required', 'string', 'regex:/^(global|plugin\.[a-z0-9_-]+)$/']]);
        $scope = $validated['scope'];
        $this->assertScopeExists($scope);
        $themes->reset($scope);
        $this->audit($request, 'appearance.theme.reset', $scope, []);
        return redirect()->route('admin.appearance.index', ['scope' => $scope])->with('status', 'Appearance reset to inherited/default settings.');
    }

    private function assertScopeExists(string $scope): void
    {
        if (str_starts_with($scope, 'plugin.')) {
            abort_unless(PluginRecord::query()->where('slug', substr($scope, 7))->where('is_installed', true)->exists(), 404);
        }
    }

    private function audit(Request $request, string $action, string $scope, array $metadata): void
    {
        AuditLog::query()->create([
            'user_id' => auth()->id(), 'action' => $action, 'entity_type' => 'appearance',
            'entity_id' => $scope, 'ip_address' => $request->ip(), 'metadata' => array_merge(['scope' => $scope], $metadata),
        ]);
    }
}
