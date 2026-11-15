@extends('layouts.admin')
@section('title', 'Slider Builder — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Independent Slider Builder</h1>
        <p class="text-xs text-slate-500 mt-0.5">Manage hero showcase slides, promotional banners, sort ordering, and destination CTAs.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Add Hero / Banner Slide</h2>
        <form method="POST" action="{{ route('plugins.slider_builder.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-2 gap-3 text-xs">
            @csrf
            <select name="key_name" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="hero">Hero Showcase Slide</option>
                <option value="banner_slider">Promotional Banner Slide</option>
            </select>
            <input name="sort_order" type="number" min="0" value="0" placeholder="Sort Order" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="title" required placeholder="Slide Headline *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="subtitle" placeholder="Subtitle / Badge" class="rounded-lg border border-slate-300 px-3 py-2">
            <input type="file" name="image" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
            <div class="grid grid-cols-2 gap-2">
                <input name="image_path" placeholder="/assets/images/..." class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="cta_url" placeholder="/courses" class="rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div class="sm:col-span-2 flex items-center justify-between">
                <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_active" value="1" checked> Active</label>
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Add Slide</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <h2 class="text-sm font-bold text-slate-900">Active Sliders ({{ $sliders->count() }})</h2>
        @forelse ($sliders as $slide)
            <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-3 text-xs">
                <div class="flex items-center gap-3">
                    @if ($slide->image_path)
                        <img src="{{ $slide->image_path }}" alt="{{ $slide->title }}" class="w-20 h-12 rounded-lg object-cover border">
                    @endif
                    <div>
                        <div class="font-bold text-sm text-slate-900">{{ $slide->title }}</div>
                        <div class="text-slate-500 font-mono">{{ $slide->key_name }} &middot; Order {{ $slide->sort_order }} &middot; {{ $slide->is_active ? 'Active' : 'Hidden' }}</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('plugins.slider_builder.destroy', $slide) }}" onsubmit="return confirm('Delete slide?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold">Delete</button>
                </form>
            </div>
        @empty
            <p class="text-xs text-slate-400">No slides added yet.</p>
        @endforelse
    </div>
</div>
@endsection
