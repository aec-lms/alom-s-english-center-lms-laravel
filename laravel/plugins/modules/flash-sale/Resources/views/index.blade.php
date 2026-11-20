@extends('layouts.admin')
@section('title', 'Flash Sale Studio')
@section('content')
<style>
.flash-studio{--fs-ink:#172033;--fs-muted:#64748b;--fs-line:#e6eaf0;color:var(--fs-ink)}
.flash-studio .fs-hero{background:radial-gradient(circle at 85% 15%,rgba(255,255,255,.18),transparent 28%),linear-gradient(120deg,#111827,#312e81 58%,#7c2d12);border-radius:20px;color:#fff;padding:28px}
.flash-studio .fs-card{border:1px solid var(--fs-line);border-radius:16px;box-shadow:0 8px 28px rgba(15,23,42,.045)}
.flash-studio .fs-kicker{font-size:.72rem;letter-spacing:.13em;text-transform:uppercase;font-weight:800;opacity:.72}
.flash-studio .form-label{font-size:.82rem;font-weight:650;color:#334155}
.flash-studio .fs-stat{border:1px solid var(--fs-line);border-radius:14px;padding:16px;background:#fff}
.flash-studio .fs-stat strong{display:block;font-size:1.5rem;letter-spacing:-.04em}
.flash-studio .table>:not(caption)>*>*{padding:1rem .85rem}
.flash-studio .fs-pill{display:inline-flex;border-radius:999px;padding:.25rem .65rem;font-size:.72rem;font-weight:750;background:#eef2ff;color:#3730a3}
.flash-studio .fs-help{color:var(--fs-muted);font-size:.8rem}
.flash-studio details>summary{cursor:pointer;list-style:none}
.flash-studio details>summary::-webkit-details-marker{display:none}
</style>
<div class="container-fluid py-4 flash-studio">
    <div class="fs-hero mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div><div class="fs-kicker mb-2">Growth • Promotions • Revenue</div><h1 class="h2 fw-bold mb-2">Flash Sale Studio</h1><p class="mb-0 opacity-75">Create premium, time-bound course campaigns with consistent server-side pricing.</p></div>
            <a href="{{ url('/flash-sale') }}" target="_blank" rel="noopener" class="btn btn-light fw-semibold">Preview storefront ↗</a>
        </div>
    </div>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="fs-stat"><span class="fs-help">Total campaigns</span><strong>{{ $sales->total() }}</strong><span class="fs-help">Across all pages</span></div></div>
        <div class="col-sm-6 col-xl-3"><div class="fs-stat"><span class="fs-help">Current page</span><strong>{{ $sales->count() }}</strong><span class="fs-help">Campaign records</span></div></div>
        <div class="col-sm-6 col-xl-3"><div class="fs-stat"><span class="fs-help">Storefront</span><strong class="fs-pill mt-2">{{ ($settings?->page_enabled ?? false) ? 'Visible' : 'Hidden' }}</strong><span class="fs-help">Public campaign landing page</span></div></div>
        <div class="col-sm-6 col-xl-3"><div class="fs-stat"><span class="fs-help">Pricing engine</span><strong class="fs-pill mt-2">Server-side quote</strong><span class="fs-help">Enrollment and cart integration tracked separately</span></div></div>
    </div>

    <div class="fs-card card mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><div class="fs-kicker text-primary">Storefront experience</div><h2 class="h5 fw-bold mt-1 mb-1">Landing page settings</h2><p class="fs-help mb-0">Control the public Flash Sale page without changing campaign records.</p></div><span class="fs-pill">Global settings</span></div>
            <form method="post" action="{{ url('/admin/flash-sales/page-settings') }}">@csrf
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6"><label class="form-label" for="flash-sale-page-title">Page headline</label><input id="flash-sale-page-title" class="form-control" name="page_title" maxlength="160" value="{{ $settings->page_title ?? 'Flash Sale' }}" required></div>
                    <div class="col-lg-3"><input type="hidden" name="page_enabled" value="0"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="page_enabled" name="page_enabled" value="1" @checked($settings?->page_enabled ?? true)><label class="form-check-label fw-semibold" for="page_enabled">Publish storefront</label></div></div>
                    <div class="col-lg-3"><button class="btn btn-dark w-100">Save storefront</button></div>
                </div>
            </form>
        </div>
    </div>

    <div class="fs-card card mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><div class="fs-kicker text-primary">Campaign builder</div><h2 class="h5 fw-bold mt-1 mb-1">Create a premium offer</h2><p class="fs-help mb-0">Use a clear offer, accurate schedule and course-specific eligibility.</p></div><span class="fs-pill">New campaign</span></div>
            <form method="post" action="{{ url('/admin/flash-sales') }}">@csrf
                <div class="row g-3">
                    <div class="col-lg-4"><label class="form-label">Campaign name</label><input class="form-control" name="title" maxlength="160" placeholder="e.g. IELTS Masterclass Weekend" required></div>
                    <div class="col-lg-4"><label class="form-label">Course</label><select class="form-select" name="course_id" required><option value="">Choose a course</option>@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->title }}</option>@endforeach</select></div>
                    <div class="col-lg-4"><label class="form-label">Short headline</label><input class="form-control" name="subtitle" maxlength="220" placeholder="A limited-time opportunity to level up"></div>
                    <div class="col-lg-4"><label class="form-label">Discount model</label><select class="form-select" name="discount_type"><option value="percent">Percentage discount</option><option value="fixed">Fixed amount off</option></select></div>
                    <div class="col-lg-4"><label class="form-label">Discount value</label><input class="form-control" type="number" step="0.01" min="0.01" max="1000000" name="discount_value" placeholder="25" required></div>
                    <div class="col-lg-4"><label class="form-label">Highlight badge</label><input class="form-control" name="badge_text" maxlength="40" placeholder="BEST VALUE / 25% OFF"></div>
                    <div class="col-lg-6"><label class="form-label">Campaign banner image URL (HTTPS)</label><input class="form-control" type="url" name="banner_image_url" maxlength="2048" placeholder="https://example.com/campaign.webp"></div>
                    <div class="col-lg-3"><label class="form-label">Button label</label><input class="form-control" name="cta_label" maxlength="40" value="Explore offer"></div>
                    <div class="col-lg-3"><label class="form-label">Display priority</label><input class="form-control" type="number" name="priority" min="0" max="10000" value="0"><div class="fs-help mt-1">Higher priority wins for overlapping offers.</div></div>
                    <div class="col-lg-6"><label class="form-label">Starts at</label><input class="form-control" type="datetime-local" name="starts_at" required></div>
                    <div class="col-lg-6"><label class="form-label">Ends at</label><input class="form-control" type="datetime-local" name="ends_at" required></div>
                    <div class="col-12"><label class="form-label">Campaign description</label><textarea class="form-control" name="description" rows="3" maxlength="5000" placeholder="Explain the learning outcome and what makes this offer valuable."></textarea></div>
                    <div class="col-12"><label class="form-label">Terms & conditions</label><textarea class="form-control" name="terms" rows="2" maxlength="5000" placeholder="Eligibility, exclusions and important offer terms."></textarea></div>
                    <div class="col-md-4"><label class="form-label">Branch targeting</label><select class="form-select" name="branch_id"><option value="">All eligible branches</option>@if(\Illuminate\Support\Facades\Schema::hasTable('branches'))@foreach(\Illuminate\Support\Facades\DB::table('branches')->where('is_active', true)->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach @endif</select><div class="fs-help mt-1">Branch managers are automatically scoped to their assigned branch.</div></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input type="checkbox" class="form-check-input" id="create-published" name="is_published" value="1"><label class="form-check-label" for="create-published">Publish immediately</label></div></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input type="checkbox" class="form-check-input" id="create-active" name="is_active" value="1" checked><label class="form-check-label" for="create-active">Campaign enabled</label></div></div>
                </div>
                <div class="d-flex justify-content-end mt-4"><button class="btn btn-primary px-4 fw-semibold">Create campaign</button></div>
            </form>
        </div>
    </div>

    <div class="fs-card card">
        <div class="card-body p-0">
            <div class="p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2"><div><div class="fs-kicker text-primary">Campaign library</div><h2 class="h5 fw-bold mb-0 mt-1">Manage offers</h2></div><span class="fs-help">Schedule-aware • Priority-ranked</span></div>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Campaign</th><th>Course</th><th>Offer</th><th>Schedule</th><th>Visibility</th><th class="text-end">Manage</th></tr></thead>
                <tbody>
                @forelse($sales as $sale)
                    <tr>
                        <td><div class="fw-bold">{{ $sale->title }}</div><div class="fs-help">{{ $sale->subtitle }}</div>@if($sale->badge_text)<span class="fs-pill mt-1">{{ $sale->badge_text }}</span>@endif</td>
                        <td>{{ $sale->course_title ?? 'Course integration unavailable' }}</td>
                        <td class="fw-semibold">{{ $sale->discount_type === 'percent' ? $sale->discount_value.'%' : number_format((float)$sale->discount_value,2) }} off<div class="fs-help">Priority {{ $sale->priority ?? 0 }}</div></td>
                        <td><div>{{ \Illuminate\Support\Carbon::parse($sale->starts_at)->format('d M Y, H:i') }}</div><div class="fs-help">to {{ \Illuminate\Support\Carbon::parse($sale->ends_at)->format('d M Y, H:i') }}</div></td>
                        <td><span class="fs-pill">{{ $sale->is_published ? 'Published' : 'Draft' }}</span><div class="fs-help mt-1">{{ $sale->is_active ? 'Enabled' : 'Paused' }}</div></td>
                        <td class="text-end">
                            <details class="text-start">
                                <summary class="btn btn-sm btn-outline-primary">Edit campaign</summary>
                                <form class="border rounded-3 p-3 mt-2 bg-light" method="post" action="{{ url('/admin/flash-sales/'.$sale->id) }}">@csrf @method('PUT')
                                    <div class="row g-2">
                                        <div class="col-12"><label class="form-label">Campaign name</label><input class="form-control" name="title" value="{{ $sale->title }}" required maxlength="160"></div>
                                        <div class="col-12"><label class="form-label">Course</label><select class="form-select" name="course_id" required>@foreach($courses as $course)<option value="{{ $course->id }}" @selected((int)$course->id === (int)$sale->course_id)>{{ $course->title }}</option>@endforeach</select></div>
                                        <div class="col-12"><label class="form-label">Headline</label><input class="form-control" name="subtitle" value="{{ $sale->subtitle }}" maxlength="220"></div>
                                        <div class="col-6"><label class="form-label">Type</label><select class="form-select" name="discount_type"><option value="percent" @selected($sale->discount_type === 'percent')>Percent</option><option value="fixed" @selected($sale->discount_type === 'fixed')>Fixed</option></select></div>
                                        <div class="col-6"><label class="form-label">Value</label><input class="form-control" type="number" step="0.01" min="0.01" name="discount_value" value="{{ $sale->discount_value }}" required></div>
                                        <div class="col-6"><label class="form-label">Badge</label><input class="form-control" name="badge_text" value="{{ $sale->badge_text }}" maxlength="40"></div>
                                        <div class="col-6"><label class="form-label">Priority</label><input class="form-control" type="number" name="priority" min="0" max="10000" value="{{ $sale->priority ?? 0 }}"></div>
                                        <div class="col-12"><label class="form-label">Banner URL (HTTPS)</label><input class="form-control" type="url" name="banner_image_url" value="{{ $sale->banner_image_url }}" maxlength="2048"></div>
                                        <div class="col-12"><label class="form-label">Button label</label><input class="form-control" name="cta_label" value="{{ $sale->cta_label ?? 'Explore offer' }}" maxlength="40"></div>
                                        <div class="col-6"><label class="form-label">Starts</label><input class="form-control" type="datetime-local" name="starts_at" value="{{ \Illuminate\Support\Carbon::parse($sale->starts_at)->format('Y-m-d\\TH:i') }}" required></div>
                                        <div class="col-6"><label class="form-label">Ends</label><input class="form-control" type="datetime-local" name="ends_at" value="{{ \Illuminate\Support\Carbon::parse($sale->ends_at)->format('Y-m-d\\TH:i') }}" required></div>
                                        <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2" maxlength="5000">{{ $sale->description }}</textarea></div>
                                        <div class="col-12"><label class="form-label">Terms</label><textarea class="form-control" name="terms" rows="2" maxlength="5000">{{ $sale->terms }}</textarea></div>
                                        <div class="col-12"><label class="form-label">Branch ID (blank = all)</label><input class="form-control" type="number" name="branch_id" min="1" value="{{ $sale->branch_id }}"></div>
                                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_published" value="1" @checked($sale->is_published)><label class="form-check-label">Published</label></div></div>
                                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($sale->is_active)><label class="form-check-label">Enabled</label></div></div>
                                    </div>
                                    <button class="btn btn-primary btn-sm mt-3">Save campaign</button>
                                </form>
                            </details>
                            <form class="d-inline-block mt-1" method="post" action="{{ url('/admin/flash-sales/'.$sale->id.'/toggle') }}">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $sale->is_active ? 'Pause' : 'Resume' }}</button></form>
                            <form class="d-inline-block mt-1" method="post" action="{{ url('/admin/flash-sales/'.$sale->id) }}" onsubmit="return confirm('Permanently delete this campaign?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>
                        </td>
                    </tr>
                @empty<tr><td colspan="6" class="text-center text-muted py-5">No campaigns yet. Create your first premium offer above.</td></tr>@endforelse
                </tbody>
            </table></div>
            <div class="p-3 border-top">{{ $sales->links() }}</div>
        </div>
    </div>
</div>
@endsection
