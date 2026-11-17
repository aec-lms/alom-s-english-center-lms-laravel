<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Appearance\SiteNavigationManager;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteNavigationController extends Controller
{
    public function index(SiteNavigationManager $navigation): View
    {
        $primary = $navigation->items('primary', true);
        $footer = $navigation->items('footer', true);

        return view('admin.site-navigation.index', compact('primary', 'footer'));
    }

    public function update(Request $request, SiteNavigationManager $navigation): RedirectResponse
    {
        $validated = $request->validate([
            'menus' => ['required', 'array'],
            'menus.primary' => ['required', 'array', 'min:1', 'max:30'],
            'menus.footer' => ['required', 'array', 'min:1', 'max:30'],
            'menus.primary.*.label' => ['required', 'string', 'max:60'],
            'menus.primary.*.url' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) use ($navigation): void { if (!$navigation->isSafeUrl((string) $value)) $fail('Use a safe same-site path or HTTP(S) URL.'); }],
            'menus.primary.*.order' => ['required', 'integer', 'between:-1000,1000'],
            'menus.primary.*.visibility' => ['required', 'in:show,hide'],
            'menus.footer.*.label' => ['required', 'string', 'max:60'],
            'menus.footer.*.url' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) use ($navigation): void { if (!$navigation->isSafeUrl((string) $value)) $fail('Use a safe same-site path or HTTP(S) URL.'); }],
            'menus.footer.*.order' => ['required', 'integer', 'between:-1000,1000'],
            'menus.footer.*.visibility' => ['required', 'in:show,hide'],
        ]);

        foreach (['primary', 'footer'] as $location) {
            $items = array_map(static fn (array $item): array => [
                'label' => trim($item['label']),
                'url' => trim($item['url']),
                'order' => (int) $item['order'],
                'hidden' => $item['visibility'] === 'hide',
            ], $validated['menus'][$location]);

            $navigation->save($location, $items, auth()->id());
        }

        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => 'site.navigation.updated',
            'entity_type' => 'site_navigation',
            'entity_id' => 'primary_and_footer',
            'ip_address' => $request->ip(),
            'metadata' => [
                'primary_count' => count($validated['menus']['primary']),
                'footer_count' => count($validated['menus']['footer']),
            ],
        ]);

        return redirect()->route('admin.site-navigation.index')->with('status', 'Website navigation saved successfully.');
    }
}
