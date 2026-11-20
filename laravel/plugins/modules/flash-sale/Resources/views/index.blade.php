@extends('layouts.admin')
@section('title', 'Flash Sale Studio')
@section('content')
<style>
.flash-studio{--fs-ink:#172033;--fs-muted:#64748b;--fs-line:#e6eaf0;color:var(--fs-ink)}
.flash-studio .fs-hero{background:radial-gradient(circle at 85% 15%,rgba(255,255,255,.18),transparent 28%),linear-gradient(120deg,#111827,#312e81 58%,#7c2d12);border-radius:20px;color:#fff;padding:28px}
.flash-studio .fs-card{border:1px solid var(--fs-line);border-radius:16px;box-shadow:0 8px 28px rgba(15,23,42,.045)}
.flash-studio .fs-kicker{font-size:.72rem;letter-spacing:.13em;text-transform:uppercase;font-weight:800;opacity:.72}
.flash-studio .form-label{font-size:.82rem;font-weight:650;color:#334155}
.flash-studio .fs-stat{border:1px solid var(--fs-line);border-radius:14px;padding:16px;background:#fff;min-width:0}
.flash-studio .fs-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}
.flash-studio .fs-hero h1{font-size:clamp(1.85rem,3vw,2.6rem);line-height:1.12;letter-spacing:-.045em}
.flash-studio .fs-preview-link{display:inline-flex;align-items:center;justify-content:center;background:#fff;color:#172033;border:1px solid rgba(255,255,255,.75);border-radius:12px;padding:.7rem 1rem;font-weight:750;text-decoration:none}
.flash-studio .fs-preview-link:hover{background:#eef2ff;color:#1e1b4b}
.flash-studio .fs-preview-link:focus-visible{outline:3px solid #fbbf24;outline-offset:3px}
@media(max-width:1199.98px){.flash-studio .fs-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:575.98px){.flash-studio .fs-metrics{grid-template-columns:1fr}.flash-studio .fs-hero{padding:1.35rem}}
.flash-studio .fs-stat strong{display:block;font-size:1.5rem;letter-spacing:-.04em}
.flash-studio .table>:not(caption)>*>*{padding:1rem .85rem}
.flash-studio .fs-pill{display:inline-flex;border-radius:999px;padding:.25rem .65rem;font-size:.72rem;font-weight:750;background:#eef2ff;color:#3730a3}
.flash-studio .fs-help{color:var(--fs-muted);font-size:.8rem}

/* The protected admin shell provides Tailwind utilities, not Bootstrap components.
   Keep the Flash Sale Studio's grid, form and table styles self-contained. */
.flash-studio,.flash-studio *,.flash-studio *::before,.flash-studio *::after{box-sizing:border-box}
.flash-studio{width:100%;max-width:100%;margin:0 auto;padding:1.5rem;min-width:0}
.flash-studio .container-fluid{width:100%;max-width:none;margin:0;padding:0}
.flash-studio .row{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:1rem;margin:0}
.flash-studio .col-12{grid-column:span 12;min-width:0}
.flash-studio .col-lg-3{grid-column:span 3;min-width:0}
.flash-studio .col-lg-4{grid-column:span 4;min-width:0}
.flash-studio .col-lg-6{grid-column:span 6;min-width:0}
.flash-studio .col-md-4{grid-column:span 4;min-width:0}
.flash-studio .col-6{grid-column:span 6;min-width:0}
.flash-studio .d-flex{display:flex}
.flash-studio .flex-wrap{flex-wrap:wrap}
.flash-studio .d-inline-block{display:inline-block}
.flash-studio .justify-content-between{justify-content:space-between}
.flash-studio .justify-content-end{justify-content:flex-end}
.flash-studio .align-items-center{align-items:center}
.flash-studio .align-items-start{align-items:flex-start}
.flash-studio .align-items-end{align-items:flex-end}
.flash-studio .gap-2{gap:.5rem}
.flash-studio .gap-3{gap:1rem}
.flash-studio .mb-0{margin-bottom:0}
.flash-studio .mb-1{margin-bottom:.25rem}
.flash-studio .mb-2{margin-bottom:.5rem}
.flash-studio .mb-3{margin-bottom:1rem}
.flash-studio .mb-4{margin-bottom:1.5rem}
.flash-studio .mt-1{margin-top:.25rem}
.flash-studio .mt-2{margin-top:.5rem}
.flash-studio .mt-3{margin-top:1rem}
.flash-studio .p-0{padding:0}
.flash-studio .p-3{padding:1rem}
.flash-studio .p-4{padding:1.5rem}
.flash-studio .py-4{padding-top:1.5rem;padding-bottom:1.5rem}
.flash-studio .px-4{padding-left:1.5rem;padding-right:1.5rem}
.flash-studio .w-100{width:100%}
.flash-studio .text-start{text-align:left}
.flash-studio .text-end{text-align:right}
.flash-studio .text-center{text-align:center}
.flash-studio .text-primary{color:#4338ca}
.flash-studio .text-muted,.flash-studio .text-secondary{color:var(--fs-muted)}
.flash-studio .fw-bold{font-weight:800}
.flash-studio .fw-semibold{font-weight:700}
.flash-studio .border-bottom{border-bottom:1px solid var(--fs-line)}
.flash-studio .border-top{border-top:1px solid var(--fs-line)}
.flash-studio .border{border:1px solid var(--fs-line)}
.flash-studio .rounded-3{border-radius:12px}
.flash-studio .bg-light{background:#f8fafc}
.flash-studio .card-body{padding:1.5rem}
.flash-studio .card-body.p-0{padding:0}
.flash-studio .form-label{display:block;width:100%;margin:0 0 .4rem;font-size:.82rem;font-weight:700;color:#334155}
.flash-studio .form-control,.flash-studio .form-select{
  display:block;width:100%;min-width:0;min-height:44px;margin:0;padding:.65rem .75rem;
  border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#172033;
  font:inherit;font-size:.92rem;line-height:1.35;box-shadow:none;
}
.flash-studio textarea.form-control{min-height:74px;resize:vertical}
.flash-studio .form-control:focus,.flash-studio .form-select:focus{
  outline:3px solid rgba(99,102,241,.2);border-color:#6366f1;
}
.flash-studio .form-check{display:flex;align-items:center;gap:.55rem;min-height:28px}
.flash-studio .form-check-input{width:1.15rem;height:1.15rem;flex:0 0 auto;margin:0;accent-color:#4338ca}
.flash-studio .form-check-label{font-size:.9rem;font-weight:650;color:#334155;cursor:pointer}
.flash-studio .btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:40px;
  padding:.6rem .9rem;border:1px solid transparent;border-radius:10px;
  font:inherit;font-size:.88rem;font-weight:700;line-height:1.2;text-decoration:none;cursor:pointer;
}
.flash-studio .btn-sm{min-height:34px;padding:.45rem .65rem;font-size:.8rem}
.flash-studio .btn-primary{background:#4338ca;color:#fff}
.flash-studio .btn-primary:hover{background:#3730a3}
.flash-studio .btn-dark{background:#172033;color:#fff}
.flash-studio .btn-outline-primary{background:#fff;color:#4338ca;border-color:#a5b4fc}
.flash-studio .btn-outline-secondary{background:#fff;color:#475569;border-color:#cbd5e1}
.flash-studio .btn-outline-danger{background:#fff;color:#b91c1c;border-color:#fecaca}
.flash-studio .btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.flash-studio .alert{padding:.85rem 1rem;margin-bottom:1rem;border-radius:10px}
.flash-studio .alert-success{background:#ecfdf5;color:#065f46}
.flash-studio .alert-danger{background:#fef2f2;color:#991b1b}
.flash-studio .table-responsive{width:100%;overflow-x:auto}
.flash-studio .table{width:100%;border-collapse:separate;border-spacing:0;text-align:left}
.flash-studio .table th,.flash-studio .table td{padding:.9rem .75rem;border-bottom:1px solid var(--fs-line);vertical-align:middle}
.flash-studio .table th{font-size:.75rem;font-weight:800;color:#475569;white-space:nowrap}
.flash-studio .table td{font-size:.86rem;color:#263247}
.flash-studio .fs-edit-details[open]>form{
  position:relative;width:min(100%,720px);max-height:none;overflow:visible;
  margin:.75rem 0 1rem;padding:1.35rem;background:#fff;
  border:1px solid #cbd5e1;border-radius:14px;
  box-shadow:0 8px 24px rgba(15,23,42,.08);
}
.flash-studio .fs-edit-details[open]>form .row{row-gap:.9rem}
.flash-studio .fs-edit-disclosure-bar{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding-bottom:.85rem;margin-bottom:1rem;border-bottom:1px solid var(--fs-line);
}
.flash-studio details>summary{display:inline-flex;align-items:center;justify-content:center}
.flash-studio details[open]>summary{margin-bottom:.5rem}
.flash-studio .fs-card details form{background:#f8fafc}
.flash-studio .fs-stat{overflow-wrap:anywhere}
.flash-studio .fs-metrics{width:100%}
.flash-studio .fs-hero{margin-bottom:1.5rem}
.flash-studio h1{font-size:clamp(1.9rem,3vw,2.6rem);line-height:1.12}
.flash-studio h2{font-size:1.25rem;line-height:1.35}
@media(max-width:991.98px){
  .flash-studio .col-lg-3,.flash-studio .col-lg-4,.flash-studio .col-lg-6{grid-column:span 12}
}
@media(max-width:767.98px){
  .flash-studio{padding:1rem}
  .flash-studio .fs-edit-details[open]>form{width:100%;padding:1rem}
  .flash-studio .col-6{grid-column:span 12}
  .flash-studio .col-md-4{grid-column:span 12}
  .flash-studio .card-body{padding:1rem}
  .flash-studio .table{min-width:760px}
}
.flash-studio details>summary{cursor:pointer;list-style:none}
.flash-studio details>summary::-webkit-details-marker{display:none}
</style>
<div class="container-fluid py-4 flash-studio">
    <div class="fs-hero mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div><div class="fs-kicker mb-2">Growth • Promotions • Revenue</div><h1 class="h2 fw-bold mb-2">Flash Sale Studio</h1><p class="mb-0 opacity-75">Create premium, time-bound course campaigns with consistent server-side pricing.</p></div>
            <a href="{{ url('/flash-sale') }}" target="_blank" rel="noopener" class="fs-preview-link">Preview storefront ↗</a>
        </div>
    </div>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="fs-metrics mb-4">
        <div class="fs-stat"><span class="fs-help">Total campaigns</span><strong>{{ $sales->total() }}</strong><span class="fs-help">Across all pages</span></div>
        <div class="fs-stat"><span class="fs-help">Current page</span><strong>{{ $sales->count() }}</strong><span class="fs-help">Campaign records</span></div>
        <div class="fs-stat"><span class="fs-help">Storefront</span><strong class="fs-pill mt-2">{{ ($settings?->page_enabled ?? false) ? 'Visible' : 'Hidden' }}</strong><span class="fs-help">Public campaign landing page</span></div>
        <div class="fs-stat"><span class="fs-help">Pricing engine</span><strong class="fs-pill mt-2">Server-side quote</strong><span class="fs-help">Enrollment and cart integration tracked separately</span></div>
    </div>

    <div class="fs-card card mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><div class="fs-kicker text-primary">Storefront experience</div><h2 class="h5 fw-bold mt-1 mb-1">Landing page settings</h2><p class="fs-help mb-0">Control the public Flash Sale page without changing campaign records.</p></div><span class="fs-pill">Global settings</span></div>
            <form method="post" action="{{ url('/admin/flash-sales/page-settings') }}">@csrf
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6"><label class="form-label" for="flash-sale-page-title">Page headline</label><input id="flash-sale-page-title" class="form-control" name="page_title" maxlength="160" value="{{ $settings->page_title ?? 'Flash Sale' }}" required></div>
                    <div class="col-lg-3"><input type="hidden" name="page_enabled" value="0"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="page_enabled" name="page_enabled" value="1" @checked($settings?->page_enabled ?? false)><label class="form-check-label fw-semibold" for="page_enabled">Publish storefront</label></div></div>
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
                    <div class="col-lg-4"><label class="form-label" for="create-title">Campaign name</label><input id="create-title" class="form-control" name="title" maxlength="160" placeholder="e.g. IELTS Masterclass Weekend" required></div>
                    <div class="col-lg-4"><label class="form-label" for="create-course">Course</label><select id="create-course" class="form-select" name="course_id" required><option value="">Choose a course</option>@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->title }}</option>@endforeach</select></div>
                    <div class="col-lg-4"><label class="form-label" for="create-subtitle">Short headline</label><input id="create-subtitle" class="form-control" name="subtitle" maxlength="220" placeholder="A limited-time opportunity to level up"></div>
                    <div class="col-lg-4"><label class="form-label" for="create-discount-type">Discount model</label><select id="create-discount-type" class="form-select" name="discount_type"><option value="percent">Percentage discount</option><option value="fixed">Fixed amount off</option></select></div>
                    <div class="col-lg-4"><label class="form-label" for="create-discount-value">Discount value</label><input id="create-discount-value" class="form-control" type="number" step="0.01" min="0.01" max="1000000" name="discount_value" placeholder="25" required></div>
                    <div class="col-lg-4"><label class="form-label" for="create-badge-text">Highlight badge</label><input id="create-badge-text" class="form-control" name="badge_text" maxlength="40" placeholder="BEST VALUE / 25% OFF"></div>
                    <div class="col-lg-6"><label class="form-label" for="create-banner-url">Campaign banner image URL (HTTPS)</label><input id="create-banner-url" class="form-control" type="url" name="banner_image_url" maxlength="2048" placeholder="https://example.com/campaign.webp"></div>
                    <div class="col-lg-3"><label class="form-label" for="create-cta-label">Button label</label><input id="create-cta-label" class="form-control" name="cta_label" maxlength="40" value="Explore offer"></div>
                    <div class="col-lg-3"><label class="form-label" for="create-priority">Display priority</label><input id="create-priority" class="form-control" type="number" name="priority" min="0" max="10000" value="0"><div class="fs-help mt-1">Higher priority wins for overlapping offers.</div></div>
                    <div class="col-lg-6"><label class="form-label" for="create-starts-at">Starts at</label><input id="create-starts-at" class="form-control" type="datetime-local" name="starts_at" required></div>
                    <div class="col-lg-6"><label class="form-label" for="create-ends-at">Ends at</label><input id="create-ends-at" class="form-control" type="datetime-local" name="ends_at" required></div>
                    <div class="col-12"><label class="form-label" for="create-description">Campaign description</label><textarea id="create-description" class="form-control" name="description" rows="3" maxlength="5000" placeholder="Explain the learning outcome and what makes this offer valuable."></textarea></div>
                    <div class="col-12"><label class="form-label" for="create-terms">Terms & conditions</label><textarea id="create-terms" class="form-control" name="terms" rows="2" maxlength="5000" placeholder="Eligibility, exclusions and important offer terms."></textarea></div>
                    <div class="col-md-4"><label class="form-label" for="create-branch">Branch targeting</label><select id="create-branch" class="form-select" name="branch_id"><option value="">All eligible branches</option>@if(\Illuminate\Support\Facades\Schema::hasTable('branches'))@foreach(\Illuminate\Support\Facades\DB::table('branches')->where('is_active', true)->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach @endif</select><div class="fs-help mt-1">Branch managers are automatically scoped to their assigned branch.</div></div>
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
                            <details class="text-start fs-edit-details">
                                <summary class="btn btn-sm btn-outline-primary">Edit campaign</summary>
                                <form class="border rounded-3 p-3 mt-2 bg-light" aria-labelledby="edit-campaign-heading-{{ $sale->id }}" method="post" action="{{ url('/admin/flash-sales/'.$sale->id) }}">@csrf @method('PUT')
                                    <div class="fs-edit-disclosure-bar">
                                        <h3 class="mb-0" id="edit-campaign-heading-{{ $sale->id }}">Edit campaign: {{ $sale->title }}</h3>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Close editor" onclick="const disclosure = this.closest('details'); disclosure.open = false; disclosure.querySelector('summary').focus();">Close editor</button>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-12"><label class="form-label" for="edit-title-{{ $sale->id }}">Campaign name</label><input id="edit-title-{{ $sale->id }}" class="form-control" name="title" value="{{ $sale->title }}" required maxlength="160"></div>
                                        <div class="col-12"><label class="form-label" for="edit-course-{{ $sale->id }}">Course</label><select id="edit-course-{{ $sale->id }}" class="form-select" name="course_id" required>@foreach($courses as $course)<option value="{{ $course->id }}" @selected((int)$course->id === (int)$sale->course_id)>{{ $course->title }}</option>@endforeach</select></div>
                                        <div class="col-12"><label class="form-label" for="edit-subtitle-{{ $sale->id }}">Headline</label><input id="edit-subtitle-{{ $sale->id }}" class="form-control" name="subtitle" value="{{ $sale->subtitle }}" maxlength="220"></div>
                                        <div class="col-6"><label class="form-label" for="edit-type-{{ $sale->id }}">Type</label><select id="edit-type-{{ $sale->id }}" class="form-select" name="discount_type"><option value="percent" @selected($sale->discount_type === 'percent')>Percent</option><option value="fixed" @selected($sale->discount_type === 'fixed')>Fixed</option></select></div>
                                        <div class="col-6"><label class="form-label" for="edit-value-{{ $sale->id }}">Value</label><input id="edit-value-{{ $sale->id }}" class="form-control" type="number" step="0.01" min="0.01" name="discount_value" value="{{ $sale->discount_value }}" required></div>
                                        <div class="col-6"><label class="form-label" for="edit-badge-{{ $sale->id }}">Badge</label><input id="edit-badge-{{ $sale->id }}" class="form-control" name="badge_text" value="{{ $sale->badge_text }}" maxlength="40"></div>
                                        <div class="col-6"><label class="form-label" for="edit-priority-{{ $sale->id }}">Priority</label><input id="edit-priority-{{ $sale->id }}" class="form-control" type="number" name="priority" min="0" max="10000" value="{{ $sale->priority ?? 0 }}"></div>
                                        <div class="col-12"><label class="form-label" for="edit-banner-url-{{ $sale->id }}">Banner URL (HTTPS)</label><input id="edit-banner-url-{{ $sale->id }}" class="form-control" type="url" name="banner_image_url" value="{{ $sale->banner_image_url }}" maxlength="2048"></div>
                                        <div class="col-12"><label class="form-label" for="edit-cta-label-{{ $sale->id }}">Button label</label><input id="edit-cta-label-{{ $sale->id }}" class="form-control" name="cta_label" value="{{ $sale->cta_label ?? 'Explore offer' }}" maxlength="40"></div>
                                        <div class="col-6"><label class="form-label" for="edit-starts-at-{{ $sale->id }}">Starts</label><input id="edit-starts-at-{{ $sale->id }}" class="form-control" type="datetime-local" name="starts_at" value="{{ \Illuminate\Support\Carbon::parse($sale->starts_at)->format('Y-m-d\\TH:i') }}" required></div>
                                        <div class="col-6"><label class="form-label" for="edit-ends-at-{{ $sale->id }}">Ends</label><input id="edit-ends-at-{{ $sale->id }}" class="form-control" type="datetime-local" name="ends_at" value="{{ \Illuminate\Support\Carbon::parse($sale->ends_at)->format('Y-m-d\\TH:i') }}" required></div>
                                        <div class="col-12"><label class="form-label" for="edit-description-{{ $sale->id }}">Description</label><textarea id="edit-description-{{ $sale->id }}" class="form-control" name="description" rows="2" maxlength="5000">{{ $sale->description }}</textarea></div>
                                        <div class="col-12"><label class="form-label" for="edit-terms-{{ $sale->id }}">Terms</label><textarea id="edit-terms-{{ $sale->id }}" class="form-control" name="terms" rows="2" maxlength="5000">{{ $sale->terms }}</textarea></div>
                                        <div class="col-12"><label class="form-label" for="edit-branch-{{ $sale->id }}">Branch ID (blank = all)</label><input id="edit-branch-{{ $sale->id }}" class="form-control" type="number" name="branch_id" min="1" value="{{ $sale->branch_id }}"></div>
                                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit-published-{{ $sale->id }}" name="is_published" value="1" @checked($sale->is_published)><label class="form-check-label" for="edit-published-{{ $sale->id }}">Published</label></div></div>
                                        <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit-active-{{ $sale->id }}" name="is_active" value="1" @checked($sale->is_active)><label class="form-check-label" for="edit-active-{{ $sale->id }}">Enabled</label></div></div>
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
