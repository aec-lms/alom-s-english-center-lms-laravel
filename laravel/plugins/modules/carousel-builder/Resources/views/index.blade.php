@extends('layouts.admin')
@section('title', 'Carousel Builder — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Independent Carousel Builder</h1>
        <p class="text-xs text-slate-500 mt-0.5">Manage multi-item carousels across Student Reviews, Featured Courses, and Partner Showcases independently from Website CMS and Slider Builder.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Add Carousel Card</h2>
        <form method="POST" action="{{ route('plugins.carousel_builder.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-2 gap-3 text-xs">
            @csrf
            <select name="carousel_group" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="featured_carousel">Featured Showcase Carousel</option>
                <option value="testimonial_left">Student Review Carousel (Row 1)</option>
                <option value="testimonial_right">Student Review Carousel (Row 2)</option>
                <option value="course_showcase">Course Showcase Carousel</option>
                <option value="partner_logos">Partner &amp; Accreditation Carousel</option>
            </select>
            <input name="sort_order" type="number" min="0" value="0" placeholder="Sort Order" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="title" required placeholder="Card Title / Student Name *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="rating" type="number" min="1" max="5" value="5" placeholder="Rating (1-5)" class="rounded-lg border border-slate-300 px-3 py-2">
            <input type="file" name="image" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
            <div class="grid grid-cols-2 gap-2">
                <input name="image_path" placeholder="/assets/images/..." class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="cta_url" placeholder="/courses" class="rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <textarea name="subtitle" rows="2" placeholder="Quote / Card Summary" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2"></textarea>
            <div class="sm:col-span-2 flex items-center justify-between">
                <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_active" value="1" checked> Active</label>
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Add Carousel Item</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <h2 class="text-sm font-bold text-slate-900">Carousel Items ({{ $items->count() }})</h2>
        @forelse ($items as $item)
            <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-3 text-xs">
                <div>
                    <div class="font-bold text-sm text-slate-900">{{ $item->title }} <span class="text-amber-500 font-mono">({{ $item->rating }}★)</span></div>
                    <div class="text-slate-600 mt-0.5">{{ $item->subtitle }}</div>
                    <div class="text-slate-400 font-mono mt-1">{{ $item->carousel_group }} &middot; Order {{ $item->sort_order }} &middot; {{ $item->is_active ? 'Active' : 'Hidden' }}</div>
                </div>
                <details class="mt-3 border-t border-slate-100 pt-3">
                    <summary class="cursor-pointer font-semibold text-sky-700">Edit Carousel Item</summary>
                    <form method="POST" action="{{ route('plugins.carousel_builder.update', $item) }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-2 gap-2">
                        @csrf @method('PUT')
                        <select name="carousel_group" class="rounded-lg border border-slate-300 px-3 py-2">
                            @foreach (['featured_carousel' => 'Featured Showcase', 'testimonial_left' => 'Student Reviews Row 1', 'testimonial_right' => 'Student Reviews Row 2', 'course_showcase' => 'Course Showcase', 'partner_logos' => 'Partner Logos'] as $key => $label)
                                <option value="{{ $key }}" @selected($item->carousel_group === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input name="sort_order" type="number" min="0" value="{{ $item->sort_order }}" class="rounded-lg border border-slate-300 px-3 py-2">
                        <input name="title" required value="{{ $item->title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                        <input name="rating" type="number" min="1" max="5" value="{{ $item->rating }}" class="rounded-lg border border-slate-300 px-3 py-2">
                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="rounded-lg border border-slate-300 px-2 py-1.5">
                        <input name="image_path" value="{{ $item->image_path }}" placeholder="/assets/images/..." class="rounded-lg border border-slate-300 px-3 py-2">
                        <input name="cta_url" value="{{ $item->cta_url }}" placeholder="/courses or https://..." class="rounded-lg border border-slate-300 px-3 py-2">
                        <textarea name="subtitle" rows="2" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">{{ $item->subtitle }}</textarea>
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($item->is_active)> Active</label>
                        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-white font-bold">Save Item</button>
                    </form>
                </details>
                <form method="POST" action="{{ route('plugins.carousel_builder.destroy', $item) }}" onsubmit="return confirm('Delete carousel item?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold">Delete</button>
                </form>
            </div>
        @empty
            <p class="text-xs text-slate-400">No carousel items created yet.</p>
        @endforelse
    </div>
</div>
@endsection
