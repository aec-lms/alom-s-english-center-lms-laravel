<?php

declare(strict_types=1);

namespace Plugins\Modules\AdminBoosting\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\AdminBoosting\Models\BoostCampaign;

class BoostController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $campaigns = Schema::hasTable('boost_campaigns')
            ? BoostCampaign::query()->orderByDesc('priority')->latest()->get()
            : collect();

        return view('admin-boosting::index', compact('campaigns'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'campaign_name' => ['required', 'string', 'max:180'],
            'entity_type' => ['required', 'in:course,institution,branch,offer'],
            'placement' => ['required', 'in:homepage_featured,course_listing,notice_banner'],
            'badge_text' => ['nullable', 'string', 'max:60'],
            'cta_text' => ['nullable', 'string', 'max:60'],
            'destination_url' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $campaign = BoostCampaign::query()->create([
            'campaign_name' => $validated['campaign_name'],
            'entity_type' => $validated['entity_type'],
            'placement' => $validated['placement'],
            'badge_text' => $validated['badge_text'] ?: 'FEATURED',
            'cta_text' => $validated['cta_text'] ?: 'Enroll Now',
            'destination_url' => $validated['destination_url'] ?: '/courses',
            'priority' => (int) ($validated['priority'] ?? 5),
            'status' => 'active',
            'starts_at' => $validated['starts_at'] ?? now(),
            'ends_at' => $validated['ends_at'] ?? now()->addDays(30),
        ]);

        $this->audit->log('boost.created', 'boost_campaign', (string) $campaign->id, ['name' => $campaign->campaign_name]);

        return back()->with('status', "Boost campaign '{$campaign->campaign_name}' launched.");
    }

    public function update(Request $request, BoostCampaign $campaign): RedirectResponse
    {
        $validated = $request->validate([
            'campaign_name' => ['required', 'string', 'max:180'],
            'placement' => ['required', 'in:homepage_featured,course_listing,notice_banner'],
            'badge_text' => ['nullable', 'string', 'max:60'],
            'cta_text' => ['nullable', 'string', 'max:60'],
            'destination_url' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'min:1', 'max:10'],
            'status' => ['required', 'in:active,paused,completed'],
        ]);

        $campaign->update($validated);
        $this->audit->log('boost.updated', 'boost_campaign', (string) $campaign->id, ['status' => $campaign->status]);

        return back()->with('status', "Boost campaign '{$campaign->campaign_name}' updated.");
    }

    public function destroy(BoostCampaign $campaign): RedirectResponse
    {
        $name = $campaign->campaign_name;
        $id = (string) $campaign->id;
        $campaign->delete();
        $this->audit->log('boost.deleted', 'boost_campaign', $id, ['name' => $name]);

        return back()->with('status', "Boost campaign '{$name}' deleted.");
    }

    public function trackClick(BoostCampaign $campaign): RedirectResponse
    {
        $campaign->increment('clicks');
        return redirect()->to($campaign->destination_url ?: '/courses');
    }
}
