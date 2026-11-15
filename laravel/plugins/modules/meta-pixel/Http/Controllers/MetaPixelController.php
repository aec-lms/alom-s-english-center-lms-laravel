<?php

declare(strict_types=1);

namespace Plugins\Modules\MetaPixel\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MetaPixelController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function index(): View
    {
        $config = [
            'pixel_id' => $this->plugins->getSetting('meta-pixel', 'pixel_id', ''),
            'test_event_code' => $this->plugins->getSetting('meta-pixel', 'test_event_code', ''),
            'track_events' => $this->plugins->getSetting('meta-pixel', 'track_events', 'PageView,Lead,InitiateCheckout,Purchase'),
            'has_capi_token' => (string) $this->plugins->getSetting('meta-pixel', 'capi_access_token', '') !== '',
        ];

        return view('meta-pixel::index', compact('config'));
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pixel_id' => ['nullable', 'string', 'max:64'],
            'capi_access_token' => ['nullable', 'string', 'max:500'],
            'test_event_code' => ['nullable', 'string', 'max:64'],
            'events' => ['nullable', 'array'],
        ]);

        $events = !empty($validated['events']) ? implode(',', $validated['events']) : 'PageView';
        $values = [
            'pixel_id' => trim((string) ($validated['pixel_id'] ?? '')),
            'test_event_code' => trim((string) ($validated['test_event_code'] ?? '')),
            'track_events' => $events,
        ];
        $secrets = [];
        if (!empty($validated['capi_access_token'])) {
            $values['capi_access_token'] = trim((string) $validated['capi_access_token']);
            $secrets[] = 'capi_access_token';
        }

        $this->plugins->saveSettings('meta-pixel', $values, $secrets, auth()->id());

        return back()->with('status', 'Facebook Meta Pixel & CAPI settings saved.');
    }
}
