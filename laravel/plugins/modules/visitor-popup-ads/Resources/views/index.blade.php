@extends('layouts.admin')
@section('title', 'Visitor Pop-Up Ads — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-800 text-[11px] font-bold">Independent Visitor Marketing Plugin</div>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Pop-Up Ads for Visitors</h1>
        <p class="text-xs text-slate-500">Create, edit, upload banners, schedule timing, and track impressions/clicks for public website visitors.</p>
    </div>

    <form method="POST" action="{{ route('plugins.popups.store') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
        @csrf
        <h2 class="text-sm font-bold text-slate-900">Create Visitor Pop-Up Ad</h2>
        <div class="grid md:grid-cols-3 gap-4">
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Ad Headline *</label>
                <input name="title" required placeholder="Get 30% OFF IELTS & SSC Batches" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Top Badge Text</label>
                <input name="badge_text" value="SPECIAL OFFER" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Banner Image (Upload)</label>
                <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp" class="w-full rounded-xl border border-slate-300 px-3 py-1.5">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">CTA Button Text</label>
                <input name="cta_text" value="Enroll Now" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">CTA Destination URL</label>
                <input name="cta_url" value="/courses" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Delay (sec)</label>
                    <input type="number" min="0" max="120" name="delay_seconds" value="3" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">Frequency</label>
                    <select name="frequency" class="w-full rounded-xl border border-slate-300 px-2 py-2 bg-white">
                        <option value="once_per_session">Once / session</option>
                        <option value="once_per_day">Once / day</option>
                        <option value="always">Every visit</option>
                    </select>
                </div>
            </div>
        </div>
        <div>
            <label class="font-semibold text-slate-700 block mb-1">Offer Description</label>
            <textarea name="description" rows="2" placeholder="Limited seats available for the upcoming live cohort..." class="w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
        </div>
        <label class="inline-flex items-center gap-2 font-semibold text-slate-700">
            <input type="checkbox" name="is_active" value="1" checked class="rounded text-blue-600">
            <span>Activate immediately on public website</span>
        </label>
        <div>
            <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Publish Visitor Pop-Up Ad</button>
        </div>
    </form>

    <div class="grid md:grid-cols-2 gap-4">
        @forelse ($popups as $popup)
            <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $popup->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $popup->is_active ? 'ACTIVE' : 'PAUSED' }}</span>
                        <h3 class="text-sm font-bold text-slate-900 mt-1">{{ $popup->title }}</h3>
                        <p class="text-slate-500">Delay: {{ $popup->delay_seconds }}s · {{ $popup->frequency }} · Views: {{ $popup->views_count }} · Clicks: {{ $popup->clicks_count }}</p>
                    </div>
                </div>
                @if ($popup->image_path)
                    <img src="{{ $popup->image_path }}" alt="{{ $popup->title }}" class="w-full h-36 object-cover rounded-xl border border-slate-200">
                @endif
                <form method="POST" action="{{ route('plugins.popups.update', $popup) }}" enctype="multipart/form-data" class="space-y-2 border-t border-slate-100 pt-3">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-2 gap-2">
                        <input name="title" value="{{ $popup->title }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                        <input name="cta_url" value="{{ $popup->cta_url }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                        <input type="number" name="delay_seconds" value="{{ $popup->delay_seconds }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                        <select name="frequency" class="rounded-lg border border-slate-300 px-2.5 py-1.5 bg-white">
                            <option value="once_per_session" {{ $popup->frequency === 'once_per_session' ? 'selected' : '' }}>Once / session</option>
                            <option value="once_per_day" {{ $popup->frequency === 'once_per_day' ? 'selected' : '' }}>Once / day</option>
                            <option value="always" {{ $popup->frequency === 'always' ? 'selected' : '' }}>Every visit</option>
                        </select>
                    </div>
                    <input type="hidden" name="badge_text" value="{{ $popup->badge_text }}">
                    <input type="hidden" name="description" value="{{ $popup->description }}">
                    <input type="hidden" name="cta_text" value="{{ $popup->cta_text }}">
                    <div class="flex items-center justify-between">
                        <label class="inline-flex items-center gap-1.5 font-semibold">
                            <input type="checkbox" name="is_active" value="1" {{ $popup->is_active ? 'checked' : '' }} class="rounded text-blue-600">
                            <span>Active</span>
                        </label>
                        <div class="flex items-center gap-2">
                            <button class="px-3 py-1 rounded-lg bg-slate-900 text-white font-semibold">Update</button>
                        </div>
                    </div>
                </form>
                <form method="POST" action="{{ route('plugins.popups.destroy', $popup) }}" onsubmit="return confirm('Delete this pop-up ad?')">
                    @csrf @method('DELETE')
                    <button class="text-rose-600 font-semibold">Delete Pop-Up</button>
                </form>
            </div>
        @empty
            <div class="bg-white rounded-2xl border border-slate-200 p-6 text-xs text-slate-400">No visitor pop-up ads created yet.</div>
        @endforelse
    </div>
</div>
@endsection
