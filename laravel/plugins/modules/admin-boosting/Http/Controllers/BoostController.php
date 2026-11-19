<?php

declare(strict_types=1);

namespace Plugins\Modules\AdminBoosting\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
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

        $targetCourses = Schema::hasTable('courses')
            ? DB::table('courses')->where('status', 'published')->when(Schema::hasColumn('courses', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))->orderBy('title')->get(['id', 'title'])
            : collect();
        $targetBranches = Schema::hasTable('branches') && app(PluginManager::class)->isEnabled('branch-management')
            ? DB::table('branches')->where('is_active', true)->when(Schema::hasColumn('branches', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))->orderBy('name')->get(['id', 'name', 'city'])
            : collect();

        $totals = [
            'campaigns' => $campaigns->count(),
            'active' => $this->countEligibleCampaigns($campaigns),
            'impressions' => (int) $campaigns->sum('impressions'),
            'clicks' => (int) $campaigns->sum('clicks'),
        ];
        $totals['ctr'] = $totals['impressions'] > 0
            ? round(($totals['clicks'] / $totals['impressions']) * 100, 2)
            : 0.0;

        return view('admin-boosting::index', compact('campaigns', 'totals', 'targetCourses', 'targetBranches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->campaignRules($request, true));
        $startsAt = $validated['starts_at'] ?? now();
        $endsAt = $validated['ends_at'] ?? \Illuminate\Support\Carbon::parse($startsAt)->addDays(30);

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
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
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
        $validated = $request->validate($this->campaignRules($request, false));

        // Validate the effective date range, including values retained from the
        // existing campaign when an update submits only one boundary.
        $effectiveStart = array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $campaign->starts_at;
        $effectiveEnd = array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $campaign->ends_at;
        if ($effectiveStart && $effectiveEnd
            && \Illuminate\Support\Carbon::parse($effectiveEnd)->lt(\Illuminate\Support\Carbon::parse($effectiveStart))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ends_at' => 'The campaign end date must be on or after its start date.',
            ]);
        }

        $before = $campaign->only(['status', 'placement', 'entity_type', 'entity_id', 'priority']);
        if (in_array($validated['entity_type'], ['institution', 'offer'], true)) {
            $validated['entity_id'] = null;
        }
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

    private function campaignRules(Request $request, bool $creating): array
    {
        return [
            'campaign_name' => ['required', 'string', 'max:180'],
            'entity_type' => ['required', 'in:course,institution,branch,offer'],
            'entity_id' => ['required_if:entity_type,course,branch', 'nullable', 'integer', 'min:1', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                if ($value === null || $value === '') {
                    return;
                }

                $type = (string) $request->input('entity_type');
                if ($type === 'branch' && $request->input('placement') !== 'course_listing') {
                    $fail('Branch-targeted campaigns must use the course listing placement.');
                    return;
                }
                $table = match ($type) { 'course' => 'courses', 'branch' => (app(PluginManager::class)->isEnabled('branch-management') ? 'branches' : null), default => null };
                if ($table === null || !Schema::hasTable($table)) {
                    $fail('This target type does not support a record ID.');
                    return;
                }

                $query = DB::table($table)->where('id', (int) $value);
                if ($type === 'course') {
                    $query->where('status', 'published');
                } else {
                    $query->where('is_active', true);
                }
                if (Schema::hasColumn($table, 'deleted_at')) {
                    $query->whereNull('deleted_at');
                }
                if (!$query->exists()) {
                    $fail('The selected target record does not exist or is not publicly available.');
                }
            }],
            'placement' => ['required', 'in:homepage_featured,course_listing,notice_banner'],
            'badge_text' => ['nullable', 'string', 'max:60'],
            'cta_text' => ['nullable', 'string', 'max:60'],
            'destination_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== '' && !$this->isSafeDestination((string) $value)) {
                    $fail('The destination must be a relative path on this site, such as /courses/ielts.');
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

        return str_starts_with($destination, '/')
            && !str_starts_with($destination, '//')
            && !str_contains($destination, '\\');
    }

    /**
     * Calculate the dashboard's eligible-campaign metric with bounded queries.
     * Public click/impression tracking still performs a live per-campaign check.
     */
    private function countEligibleCampaigns($campaigns): int
    {
        $now = now();
        $eligible = $campaigns->filter(fn (BoostCampaign $campaign): bool =>
            $campaign->status === 'active'
            && (!$campaign->starts_at || $campaign->starts_at <= $now)
            && (!$campaign->ends_at || $campaign->ends_at >= $now)
        );

        $count = $eligible->filter(fn (BoostCampaign $campaign): bool =>
            in_array($campaign->entity_type, ['institution', 'offer'], true)
            && $campaign->entity_id === null
        )->count();

        if (Schema::hasTable('courses')) {
            $courseIds = $eligible->where('entity_type', 'course')->pluck('entity_id')->filter()->unique()->values();
            if ($courseIds->isNotEmpty()) {
                $validCourseIds = DB::table('courses')->whereIn('id', $courseIds)
                    ->where('status', 'published')
                    ->when(Schema::hasColumn('courses', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $count += $eligible->where('entity_type', 'course')
                    ->filter(fn (BoostCampaign $campaign): bool => in_array((int) $campaign->entity_id, $validCourseIds, true))
                    ->count();
            }
        }

        if (app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches')) {
            $branchIds = $eligible->where('entity_type', 'branch')
                ->filter(fn (BoostCampaign $campaign): bool => $campaign->placement === 'course_listing')
                ->pluck('entity_id')->filter()->unique()->values();
            if ($branchIds->isNotEmpty()) {
                $validBranchIds = DB::table('branches')->whereIn('id', $branchIds)
                    ->where('is_active', true)
                    ->when(Schema::hasColumn('branches', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $count += $eligible->where('entity_type', 'branch')
                    ->filter(fn (BoostCampaign $campaign): bool =>
                        $campaign->placement === 'course_listing'
                        && in_array((int) $campaign->entity_id, $validBranchIds, true)
                    )->count();
            }
        }

        return $count;
    }

    private function isEligible(BoostCampaign $campaign): bool
    {
        $now = now();
        if ($campaign->status !== 'active'
            || ($campaign->starts_at && $campaign->starts_at > $now)
            || ($campaign->ends_at && $campaign->ends_at < $now)) {
            return false;
        }

        if (in_array($campaign->entity_type, ['institution', 'offer'], true)) {
            return $campaign->entity_id === null;
        }

        if ($campaign->entity_type === 'course') {
            if (!$campaign->entity_id || !Schema::hasTable('courses')) {
                return false;
            }

            return DB::table('courses')
                ->where('id', $campaign->entity_id)
                ->where('status', 'published')
                ->when(Schema::hasColumn('courses', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->exists();
        }

        if ($campaign->entity_type === 'branch') {
            if ($campaign->placement !== 'course_listing'
                || !app(PluginManager::class)->isEnabled('branch-management')
                || !Schema::hasTable('branches')
                || !$campaign->entity_id) {
                return false;
            }

            return DB::table('branches')
                ->where('id', $campaign->entity_id)
                ->where('is_active', true)
                ->when(Schema::hasColumn('branches', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->exists();
        }

        return false;
    }

    private function visitorHash(Request $request): string
    {
        $visitorId = $request->session()->get('boost_visitor_id');
        if (!is_string($visitorId) || preg_match('/^[a-f0-9]{64}$/D', $visitorId) !== 1) {
            $visitorId = bin2hex(random_bytes(32));
            $request->session()->put('boost_visitor_id', $visitorId);
        }

        return hash_hmac('sha256', $visitorId, (string) config('app.key'));
    }
}
