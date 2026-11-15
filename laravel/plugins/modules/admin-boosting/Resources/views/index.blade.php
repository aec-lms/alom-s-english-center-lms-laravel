@extends('layouts.admin')
@section('title', 'Admin Boosting — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold">Independent Boosting Plugin</div>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Admin Boosting & Featured Promos</h1>
        <p class="text-xs text-slate-500">Promote priority courses, offers, and institution notices across homepage and course catalog placements.</p>
    </div>

    <form method="POST" action="{{ route('plugins.boost.store') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
        @csrf
        <h2 class="text-sm font-bold text-slate-900">Launch New Boost Campaign</h2>
        <div class="grid md:grid-cols-3 gap-4">
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Campaign Title *</label>
                <input name="campaign_name" required placeholder="SSC & IELTS Mega Scholarship Batch" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Target Type</label>
                <select name="entity_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    <option value="course">Course</option>
                    <option value="institution">Institution</option>
                    <option value="offer">Special Offer</option>
                    <option value="branch">Branch</option>
                </select>
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Placement</label>
                <select name="placement" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    <option value="homepage_featured">Homepage Featured Strip</option>
                    <option value="course_listing">Course Catalog Top</option>
                    <option value="notice_banner">Top Notice Promo</option>
                </select>
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Badge Text</label>
                <input name="badge_text" value="BOOSTED OFFER" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">CTA Button Label</label>
                <input name="cta_text" value="Enroll Now" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Destination URL</label>
                <input name="destination_url" value="/courses" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Priority (1-10)</label>
                <input type="number" min="1" max="10" name="priority" value="8" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Starts At</label>
                <input type="datetime-local" name="starts_at" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Ends At</label>
                <input type="datetime-local" name="ends_at" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
        </div>
        <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Launch Boost Campaign</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
        <h2 class="text-sm font-bold text-slate-900">Active & Scheduled Boost Campaigns ({{ $campaigns->count() }})</h2>
        @forelse ($campaigns as $item)
            <div class="border border-slate-200 rounded-xl p-4 flex flex-col lg:flex-row lg:items-center justify-between gap-4 text-xs">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $item->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $item->status }}</span>
                        <span class="font-bold text-sm text-slate-900">{{ $item->campaign_name }}</span>
                        <span class="text-slate-400">Priority {{ $item->priority }}</span>
                    </div>
                    <div class="text-slate-500 mt-1">{{ $item->placement }} · CTA: "{{ $item->cta_text }}" → {{ $item->destination_url }} · Impressions: {{ $item->impressions }} · Clicks: {{ $item->clicks }}</div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('plugins.boost.update', $item) }}" class="flex flex-wrap items-center gap-2">
                        @csrf @method('PUT')
                        <input type="hidden" name="campaign_name" value="{{ $item->campaign_name }}">
                        <input type="hidden" name="placement" value="{{ $item->placement }}">
                        <input type="hidden" name="badge_text" value="{{ $item->badge_text }}">
                        <input type="hidden" name="cta_text" value="{{ $item->cta_text }}">
                        <input type="hidden" name="destination_url" value="{{ $item->destination_url }}">
                        <input type="hidden" name="priority" value="{{ $item->priority }}">
                        <select name="status" class="rounded-lg border border-slate-300 px-2 py-1">
                            <option value="active" {{ $item->status === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="paused" {{ $item->status === 'paused' ? 'selected' : '' }}>Paused</option>
                            <option value="completed" {{ $item->status === 'completed' ? 'selected' : '' }}>Completed</option>
                        </select>
                        <button class="px-3 py-1 rounded-lg bg-slate-900 text-white font-semibold">Update</button>
                    </form>
                    <form method="POST" action="{{ route('plugins.boost.destroy', $item) }}" onsubmit="return confirm('Delete boost campaign?')">
                        @csrf @method('DELETE')
                        <button class="px-3 py-1 rounded-lg border border-rose-200 text-rose-700 font-semibold">Delete</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="text-xs text-slate-400">No boost campaigns yet.</p>
        @endforelse
    </div>
</div>
@endsection
