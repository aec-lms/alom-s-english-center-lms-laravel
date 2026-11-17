<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Plugins\AdminMenuRegistry;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminMenuController extends Controller
{
    public function index(AdminMenuRegistry $registry): View
    {
        $items = [];
        foreach ($registry->groups(true) as $group => $groupItems) {
            foreach ($groupItems as $item) {
                $items[] = $item + ['group' => $group];
            }
        }

        return view('admin.menu-editor.index', compact('items'));
    }

    public function update(Request $request, AdminMenuRegistry $registry): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.key' => ['required', 'string', 'max:220', 'distinct'],
            'items.*.group' => ['required', 'string', 'max:60', 'regex:/^[\pL\pN _&().-]+$/u'],
            'items.*.order' => ['required', 'integer', 'between:-1000,1000'],
            'items.*.visibility' => ['required', 'in:show,hide'],
        ]);

        $allowed = [];
        foreach ($registry->groups(true) as $groupItems) {
            foreach ($groupItems as $item) {
                $allowed[$item['plugin'] . '|' . $item['route']] = true;
            }
        }

        $preferences = [];
        foreach ($validated['items'] as $item) {
            $key = $item['key'];
            if (!isset($allowed[$key])) {
                return back()->withErrors(['items' => 'A menu entry is no longer available. Refresh the page and try again.']);
            }

            $preferences[$key] = [
                'group' => trim($item['group']),
                'order' => (int) $item['order'],
                'hidden' => $item['visibility'] === 'hide',
            ];
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => 'admin.navigation.menu'],
            [
                'value' => json_encode($preferences, JSON_THROW_ON_ERROR),
                'updated_by' => auth()->id(),
                'is_encrypted' => false,
            ],
        );

        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => 'admin.navigation.menu.updated',
            'entity_type' => 'admin_navigation',
            'entity_id' => 'plugin_menu',
            'ip_address' => $request->ip(),
            'metadata' => ['entry_count' => count($preferences)],
        ]);

        return redirect()->route('admin.menu-editor.index')->with('status', 'Admin menu settings saved.');
    }
}
