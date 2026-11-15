<?php

declare(strict_types=1);

namespace Plugins\Modules\GoogleConsole\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GoogleConsoleController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function index(): View
    {
        $config = [
            'gsc_verification_token' => $this->plugins->getSetting('google-console', 'gsc_verification_token', ''),
            'ga4_measurement_id' => $this->plugins->getSetting('google-console', 'ga4_measurement_id', ''),
            'gtm_container_id' => $this->plugins->getSetting('google-console', 'gtm_container_id', ''),
            'auto_inject' => $this->plugins->getSetting('google-console', 'auto_inject', '1') === '1',
        ];

        return view('google-console::index', compact('config'));
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gsc_verification_token' => ['nullable', 'string', 'max:255'],
            'ga4_measurement_id' => ['nullable', 'string', 'max:64'],
            'gtm_container_id' => ['nullable', 'string', 'max:64'],
        ]);

        $this->plugins->saveSettings('google-console', [
            'gsc_verification_token' => trim((string) ($validated['gsc_verification_token'] ?? '')),
            'ga4_measurement_id' => trim((string) ($validated['ga4_measurement_id'] ?? '')),
            'gtm_container_id' => trim((string) ($validated['gtm_container_id'] ?? '')),
            'auto_inject' => $request->boolean('auto_inject', true) ? '1' : '0',
        ], [], auth()->id());

        return back()->with('status', 'Google Search Console, GA4 & Tag Manager settings saved.');
    }
}
