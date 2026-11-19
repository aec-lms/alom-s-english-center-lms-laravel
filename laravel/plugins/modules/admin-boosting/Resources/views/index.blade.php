@extends('layouts.admin')
@section('title', 'Admin Boosting — AEC LMS')
@section('content')
<div class="space-y-6 max-w-7xl">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3">
        <div>
            <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold">Premium Campaign Studio</div>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Admin Boosting & Campaign Analytics</h1>
            <p class="text-xs text-slate-500">Schedule, target and measure featured promotions across the AEC learning experience.</p>
        </div>
        <div class="text-xs text-slate-500">Unique visitors are deduplicated per session. External redirects are blocked.</div>
    </div>

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <strong>Please correct the following:</strong>
            <ul class="list-disc pl-5 mt-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid grid-cols-2 xl:grid-cols-5 gap-3">
        <div class="bg-white border border-slate-200 rounded-2xl p-4"><div class="text-xs text-slate-500">All campaigns</div><div class="text-2xl font-black text-slate-900">{{ $totals['campaigns'] }}</div></div>
        <div class="bg-white border border-slate-200 rounded-2xl p-4"><div class="text-xs text-slate-500">Active now</div><div class="text-2xl font-black text-emerald-700">{{ $totals['active'] }}</div></div>
        <div class="bg-white border border-slate-200 rounded-2xl p-4"><div class="text-xs text-slate-500">Unique impressions</div><div class="text-2xl font-black text-slate-900">{{ number_format($totals['impressions']) }}</div></div>
        <div class="bg-white border border-slate-200 rounded-2xl p-4"><div class="text-xs text-slate-500">Unique clicks</div><div class="text-2xl font-black text-slate-900">{{ number_format($totals['clicks']) }}</div></div>
        <div class="bg-white border border-slate-200 rounded-2xl p-4 col-span-2 xl:col-span-1"><div class="text-xs text-slate-500">Overall CTR</div><div class="text-2xl font-black text-indigo-700">{{ number_format($totals['ctr'], 2) }}%</div></div>
    </div>

    <form method="POST" action="{{ route('plugins.boost.store') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
        @csrf
        <div><h2 class="text-sm font-bold text-slate-900">Create campaign</h2><p class="text-slate-500 mt-1">Choose a target, placement and campaign window. A target ID is optional for broad promotions.</p></div>
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
            <label class="font-semibold text-slate-700">Campaign title *
                <input name="campaign_name" required maxlength="180" value="{{ old('campaign_name') }}" placeholder="IELTS Spring Intake" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
            <label class="font-semibold text-slate-700">Target type *
                <select id="boost-create-target-type" data-boost-type data-target-id="boost-create-target-id" name="entity_type" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    @foreach (['course' => 'Specific course', 'branch' => 'Specific branch', 'institution' => 'Whole institution', 'offer' => 'General offer'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('entity_type', 'course') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="font-semibold text-slate-700">Target record
                <input type="search" id="boost-create-target-search" data-boost-search data-target-id="boost-create-target-id" placeholder="Search published courses or branches..." class="mt-1 mb-2 w-full rounded-xl border border-slate-300 px-3 py-2 font-normal"><select id="boost-create-target-id" data-boost-id name="entity_id" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    <option value="">No specific record / broad campaign</option>
                    <optgroup label="Published courses">
                        @foreach ($targetCourses as $targetCourse)
                            <option data-entity-type="course" value="{{ $targetCourse->id }}" @selected((string) old('entity_id') === (string) $targetCourse->id)>{{ $targetCourse->title }} (#{{ $targetCourse->id }})</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Active branches">
                        @foreach ($targetBranches as $targetBranch)
                            <option data-entity-type="branch" value="{{ $targetBranch->id }}" @selected((string) old('entity_id') === (string) $targetBranch->id)>{{ $targetBranch->name }}{{ $targetBranch->city ? ' — '.$targetBranch->city : '' }} (#{{ $targetBranch->id }})</option>
                        @endforeach
                    </optgroup>
                </select>
                <span class="mt-1 block font-normal text-slate-400">Course and branch targets are validated against active public records.</span>
            </label>
            <label class="font-semibold text-slate-700">Placement *
                <select name="placement" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    <option value="homepage_featured">Homepage featured strip</option>
                    <option value="course_listing">Course catalog top</option>
                    <option value="notice_banner">Top notice promo</option>
                </select>
            </label>
            <label class="font-semibold text-slate-700">Badge text
                <input name="badge_text" maxlength="60" value="{{ old('badge_text', 'BOOSTED OFFER') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
            <label class="font-semibold text-slate-700">CTA label
                <input name="cta_text" maxlength="60" value="{{ old('cta_text', 'Explore Now') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
            <label class="font-semibold text-slate-700">Destination (same-site path only)
                <input name="destination_url" maxlength="255" value="{{ old('destination_url', '/courses') }}" placeholder="/courses/ielts" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
            <label class="font-semibold text-slate-700">Priority (1–10)
                <input type="number" min="1" max="10" name="priority" value="{{ old('priority', 8) }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
            <label class="font-semibold text-slate-700">Starts at
                <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
            <label class="font-semibold text-slate-700">Ends at
                <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
            </label>
        </div>
        <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Create campaign</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
        <div class="flex items-center justify-between gap-3"><div><h2 class="text-sm font-bold text-slate-900">Campaign manager</h2><p class="text-xs text-slate-500">Edit creative, targeting, schedule and lifecycle status in place.</p></div><span class="text-xs text-slate-500">{{ $campaigns->count() }} total</span></div>
        @forelse ($campaigns as $item)
            @php
                $ctr = $item->impressions > 0 ? ($item->clicks / $item->impressions) * 100 : 0;
                $stateClass = $item->status === 'active' ? 'bg-emerald-100 text-emerald-800' : ($item->status === 'paused' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700');
            @endphp
            <details class="border border-slate-200 rounded-xl overflow-hidden" @if ($errors->any() && old('campaign_id') == $item->id) open @endif>
                <summary class="cursor-pointer list-none p-4 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $stateClass }}">{{ $item->status }}</span><strong class="text-sm text-slate-900">{{ $item->campaign_name }}</strong><span class="text-slate-400 text-xs">Priority {{ $item->priority }}</span></div>
                        <div class="text-xs text-slate-500 mt-1">{{ ucfirst($item->entity_type) }}{{ $item->entity_id ? ' #'.$item->entity_id : ' · All matching records' }} · {{ str_replace('_', ' ', $item->placement) }} · {{ $item->starts_at?->format('M d, Y H:i') ?? 'Immediate' }} → {{ $item->ends_at?->format('M d, Y H:i') ?? 'No end' }}</div>
                    </div>
                    <div class="flex flex-wrap gap-4 text-xs"><span><b class="text-slate-900">{{ number_format($item->impressions) }}</b><span class="text-slate-500"> impressions</span></span><span><b class="text-slate-900">{{ number_format($item->clicks) }}</b><span class="text-slate-500"> clicks</span></span><span><b class="text-indigo-700">{{ number_format($ctr, 2) }}%</b><span class="text-slate-500"> CTR</span></span></div>
                </summary>
                <div class="border-t border-slate-100 bg-slate-50 p-4">
                    <form method="POST" action="{{ route('plugins.boost.update', $item) }}" class="grid md:grid-cols-2 xl:grid-cols-3 gap-3 text-xs">
                        @csrf @method('PUT')
                        <label class="font-semibold text-slate-700">Campaign title<input name="campaign_name" required maxlength="180" value="{{ $item->campaign_name }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                        <label class="font-semibold text-slate-700">Target type<select id="boost-target-type-{{ $item->id }}" data-boost-type data-target-id="boost-target-id-{{ $item->id }}" name="entity_type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">@foreach (['course' => 'Specific course', 'branch' => 'Specific branch', 'institution' => 'Whole institution', 'offer' => 'General offer'] as $value => $label)<option value="{{ $value }}" @selected($item->entity_type === $value)>{{ $label }}</option>@endforeach</select></label>
                        <label class="font-semibold text-slate-700">Target record<input type="search" id="boost-target-search-{{ $item->id }}" data-boost-search data-target-id="boost-target-id-{{ $item->id }}" placeholder="Search courses or branches..." class="mt-1 mb-2 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"><select id="boost-target-id-{{ $item->id }}" data-boost-id name="entity_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 bg-white"><option value="">No specific record / broad campaign</option><optgroup label="Published courses">@foreach ($targetCourses as $targetCourse)<option data-entity-type="course" value="{{ $targetCourse->id }}" @selected($item->entity_type === 'course' && (int) $item->entity_id === (int) $targetCourse->id)>{{ $targetCourse->title }} (#{{ $targetCourse->id }})</option>@endforeach</optgroup><optgroup label="Active branches">@foreach ($targetBranches as $targetBranch)<option data-entity-type="branch" value="{{ $targetBranch->id }}" @selected($item->entity_type === 'branch' && (int) $item->entity_id === (int) $targetBranch->id)>{{ $targetBranch->name }}{{ $targetBranch->city ? ' — '.$targetBranch->city : '' }} (#{{ $targetBranch->id }})</option>@endforeach</optgroup></select></label>
                        <label class="font-semibold text-slate-700">Placement<select name="placement" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 bg-white"><option value="homepage_featured" @selected($item->placement === 'homepage_featured')>Homepage featured</option><option value="course_listing" @selected($item->placement === 'course_listing')>Course listing</option><option value="notice_banner" @selected($item->placement === 'notice_banner')>Notice banner</option></select></label>
                        <label class="font-semibold text-slate-700">Badge<input name="badge_text" maxlength="60" value="{{ $item->badge_text }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                        <label class="font-semibold text-slate-700">CTA label<input name="cta_text" maxlength="60" value="{{ $item->cta_text }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                        <label class="font-semibold text-slate-700">Destination (same-site only)<input name="destination_url" maxlength="255" value="{{ $item->destination_url }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                        <label class="font-semibold text-slate-700">Priority<input type="number" min="1" max="10" name="priority" value="{{ $item->priority }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                        <label class="font-semibold text-slate-700">Status<select name="status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 bg-white"><option value="active" @selected($item->status === 'active')>Active</option><option value="paused" @selected($item->status === 'paused')>Paused</option><option value="completed" @selected($item->status === 'completed')>Completed</option></select></label>
                        <label class="font-semibold text-slate-700">Starts at<input type="datetime-local" name="starts_at" value="{{ $item->starts_at?->format('Y-m-d\\TH:i') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                        <label class="font-semibold text-slate-700">Ends at<input type="datetime-local" name="ends_at" value="{{ $item->ends_at?->format('Y-m-d\\TH:i') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label>
                        <div class="flex items-end gap-2"><button class="px-4 py-2 rounded-lg bg-slate-900 text-white font-bold">Save changes</button></div>
                    </form>
                    <div class="mt-3 flex justify-end"><form method="POST" action="{{ route('plugins.boost.destroy', $item) }}" onsubmit="return confirm('Move this campaign to the recycle bin?')">@csrf @method('DELETE')<button class="px-3 py-2 rounded-lg border border-rose-200 text-rose-700 font-semibold">Move to recycle bin</button></form></div>
                </div>
            </details>
        @empty
            <p class="text-xs text-slate-400">No boost campaigns yet.</p>
        @endforelse
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-boost-type]').forEach(function (typeSelect) {
        const targetSelect = document.getElementById(typeSelect.dataset.targetId);
        const searchInput = document.querySelector('[data-boost-search][data-target-id="' + typeSelect.dataset.targetId + '"]');
        if (!targetSelect) return;

        const syncTargetOptions = function (resetInvalid) {
            const selectedType = typeSelect.value;
            const searchTerm = (searchInput ? searchInput.value : '').trim().toLowerCase();
            targetSelect.querySelectorAll('option[data-entity-type]').forEach(function (option) {
                const typeMatches = option.dataset.entityType === selectedType;
                const searchMatches = searchTerm === '' || option.textContent.toLowerCase().includes(searchTerm);
                const available = typeMatches && searchMatches;
                option.hidden = !available;
                option.disabled = !available;
            });
            targetSelect.disabled = !['course', 'branch'].includes(selectedType);
            const selectedOption = targetSelect.selectedOptions[0];
            if (resetInvalid && selectedOption && selectedOption.dataset.entityType && selectedOption.dataset.entityType !== selectedType) {
                targetSelect.value = '';
            }
        };

        typeSelect.addEventListener('change', function () { syncTargetOptions(true); });
        if (searchInput) searchInput.addEventListener('input', function () { syncTargetOptions(false); });
        syncTargetOptions(false);
    });
});
</script>

@endsection
