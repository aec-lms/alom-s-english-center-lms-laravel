<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Appearance\SiteWidgetManager;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class SiteWidgetController extends Controller
{
    public function index(SiteWidgetManager $widgets): View
    {
        return view('admin.site-widgets.index', ['widgets' => $widgets->all()]);
    }

    public function update(Request $request, SiteWidgetManager $widgets): RedirectResponse
    {
        $validated = $request->validate([
            'widgets' => ['nullable', 'array', 'max:16'],
            'widgets.*.type' => ['nullable', 'in:text,notice,search,cta'],
            'widgets.*.area' => ['nullable', 'in:course-sidebar,site-sidebar'],
            'widgets.*.title' => ['nullable', 'string', 'max:80'],
            'widgets.*.content' => ['nullable', 'string', 'max:600'],
            'widgets.*.label' => ['nullable', 'string', 'max:50'],
            'widgets.*.url' => ['nullable', 'string', 'max:500'],
            'widgets.*.visible' => ['nullable', 'in:0,1'],
            'widgets.*.order' => ['nullable', 'integer', 'between:1,100'],
        ]);

        DB::transaction(function () use ($widgets, $validated, $request): void {
            $items = array_values(array_filter($validated['widgets'] ?? [], static fn ($item) => trim((string) ($item['title'] ?? '')) !== ''));
            $widgets->save($items, auth()->id());
            AuditLog::query()->create([
                'user_id' => auth()->id(),
                'action' => 'site.widgets.updated',
                'entity_type' => 'site_widgets',
                'entity_id' => 'sidebar_areas',
                'ip_address' => $request->ip(),
                'metadata' => ['widget_count' => count($items)],
            ]);
        });

        return redirect()->route('admin.site-widgets.index')->with('status', 'Website widgets saved successfully.');
    }
}
