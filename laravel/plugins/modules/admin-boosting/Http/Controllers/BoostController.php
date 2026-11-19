<?php

declare(strict_types=1);

namespace Plugins\Modules\AdminBoosting\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $totals = [
            'campaigns' => $campaigns->count(),
            'active' => $campaigns->where('status', 'active')->count(),
            'impressions' => (int) $campaigns->sum('impressions'),
            'clicks' => (int) $campaigns->sum('clicks'),
        ];
        $totals['ctr'] = $totals['impressions'] > 0
            ? round(($totals['clicks'] / $totals['impressions']) * 100, 2)
            : 0.0;

        return view('admin-boosting::index', compact('campaigns', 'totals'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->campaignRules(true));

        $campaign = BoostCampaign::query()->create([
            'campaign_name' => $validated['campaign_name'],
            'entity_type' => $validated['entity_type'],
            'entity_id' => $validated['entity_id'] ?? null,
            'placement' => $validated['placement'],
            'badge_text' => ($validated['badge_text'] ?? null) ?: 'FEATURED',
            'cta_text' => ($validated['cta_text'] ?? null) ?: 'Enroll Now',
            'destination_url' => ($validated['destination_url'] ?? null) ?: '/courses',
            'priority' => (int) ($validated['priority'] ?? 5),
            'status' => 'active',
            'starts_at' => $validated['starts_at'] ?? now(),
            'ends_at' => $validated['ends_at'] ?? now()->addDays(30),
        ]);

        $this->audit->log('boost.created', 'boost_campaign', (string) $campaign->id, [
            'name' => $campaign->campaign_name,
            'entity_type' => $campaign->entity_type,
            'entity_id' => $campaign->entity_id,
        ]);

        return back()->with('status', "Boost campaign '{$campaign->campaign_name}' launched.");
    }

    public function update(Request $request, BoostCampaign $campaign): RedirectResponse
    {
        $validated = $request->validate($this->campaignRules(false));
        $before = $campaign->only(['status', 'placement', 'entity_type', 'entity_id', 'priority']);

        $campaign->update($validated);
        $this->audit->log('boost.updated', 'boost_campaign', (string) $campaign->id, [
            'before' => $before,
            'after' => $campaign->only(['status', 'placement', 'entity_type', 'entity_id', 'priority']),
        ]);

        return back()->with('status', "Boost campaign '{$campaign->campaign_name}' updated.");
    }

    public function destroy(BoostCampaign $campaign): RedirectResponse
    {
        $name = $campaign->campaign_name;
        $id = (string) $campaign->id;
        $campaign->delete();
        $this->audit->log('boost.trashed', 'boost_campaign', $id, ['name' => $name]);

        return back()->with('status', "Boost campaign '{$name}' moved to the Admin Recycle Bin.");
    }

    public function trackImpression(Request $request, BoostCampaign $campaign)
    {
        if (!$this->isEligible($campaign) || !Schema::hasTable('boost_campaign_events')) {
            return response()->noContent();
        }

        $inserted = DB::table('boost_campaign_events')->insertOrIgnore([
            'campaign_id' => $campaign->id,
            'event_type' => 'impression',
            'visitor_hash' => $this->visitorHash($request),
            'occurred_at' => now(),
        ]);

        if ($inserted > 0) {
            $campaign->increment('impressions');
        }

        return response()->noContent();
    }

    public function trackClick(Request $request, BoostCampaign $campaign): RedirectResponse
    {
        if (!$this->isEligible($campaign)) {
            return redirect('/courses');
        }

        $destination = (string) ($campaign->destination_url ?: '/courses');
        if (!$this->isSafeDestination($destination)) {
            return redirect('/courses');
        }

        if (Schema::hasTable('boost_campaign_events')) {
            $inserted = DB::table('boost_campaign_events')->insertOrIgnore([
                'campaign_id' => $campaign->id,
                'event_type' => 'click',
                'visitor_hash' => $this->visitorHash($request),
                'occurred_at' => now(),
            ]);

            if ($inserted > 0) {
                $campaign->increment('clicks');
            }
        }

        return redirect()->to($destination);
    }

    private function campaignRules(bool $creating): array
    {
        return [
            'campaign_name' => ['required', 'string', 'max:180'],
            'entity_type' => ['required', 'in:course,institution,branch,offer'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'placement' => ['required', 'in:homepage_featured,course_listing,notice_banner'],
            'badge_text' => ['nullable', 'string', 'max:60'],
            'cta_text' => ['nullable', 'string', 'max:60'],
            'destination_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== '' && !$this->isSafeDestination((string) $value)) {
                    $fail('The destination must be a relative site path or a same-origin HTTP(S) URL.');
                }
            }],
            'priority' => [$creating ? 'nullable' : 'required', 'integer', 'min:1', 'max:10'],
            'status' => [$creating ? 'sometimes' : 'required', 'in:active,paused,completed'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    private function isSafeDestination(string $destination): bool
    {
        if ($destination === '' || preg_match('/[\x00-\x1F\x7F]/', $destination)) {
            return false;
        }

        $relative = str_starts_with($destination, '/') && !str_starts_with($destination, '//');
        if ($relative) {
            return !str_contains($destination, '\\');
        }

        $scheme = parse_url($destination, PHP_URL_SCHEME);
        $host = parse_url($destination, PHP_URL_HOST);
        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true)
            && is_string($host)
            && is_string($configuredHost)
            && strcasecmp($host, $configuredHost) === 0
            && parse_url($destination, PHP_URL_USER) === null
            && parse_url($destination, PHP_URL_PASS) === null
            && filter_var($destination, FILTER_VALIDATE_URL) !== false;
    }

    private function isEligible(BoostCampaign $campaign): bool
    {
        $now = now();

        return $campaign->status === 'active'
            && (!$campaign->starts_at || $campaign->starts_at <= $now)
            && (!$campaign->ends_at || $campaign->ends_at >= $now);
    }

    private function visitorHash(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->session()->getId(), (string) config('app.key'));
    }
}
