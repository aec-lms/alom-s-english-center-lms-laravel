@extends('layouts.admin')
@section('title', 'Course Bundles — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-6">
    <div><h1 class="text-2xl font-extrabold text-slate-900">Course Bundles</h1><p class="mt-1 text-sm text-slate-500">Sell multiple published courses as one fixed-price package. Existing purchase snapshots remain unchanged when a bundle is edited.</p></div>
    @if(session('status'))<div class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->first() }}</div>@endif
    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <h2 class="font-bold">Create a bundle</h2>
        <form method="POST" action="{{ route('plugins.course-bundles.store') }}" class="grid gap-3 sm:grid-cols-2">
            @csrf
            <input name="title" required maxlength="180" placeholder="Bundle title" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="price" type="number" min="0" step="0.01" required placeholder="Bundle price (BDT)" class="rounded-lg border border-slate-300 px-3 py-2">
            <textarea name="description" rows="3" maxlength="5000" placeholder="Description" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2"></textarea>
            <label class="text-sm">Courses (select at least 2)<select name="course_ids[]" multiple required size="7" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->title }} · #{{ $course->id }}</option>@endforeach</select></label>
            <div class="space-y-3"><label class="block text-sm">Status<select name="status" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2"><option value="draft">Draft</option><option value="published">Published</option></select></label><button class="w-full rounded-lg bg-sky-600 px-4 py-2.5 font-bold text-white">Create bundle</button></div>
        </form>
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <h2 class="font-bold">Existing bundles</h2>
        @forelse($bundles as $bundle)
            <article class="rounded-xl border border-slate-200 p-4 space-y-3">
                <div class="flex flex-wrap justify-between gap-3"><div><h3 class="font-bold">{{ $bundle->title }}</h3><p class="text-xs text-slate-500">{{ $bundle->slug }} · {{ $bundle->status }} · {{ $bundle->orders_count }} orders · {{ $bundle->items->count() }} courses</p></div><strong>৳{{ number_format((float)$bundle->price, 2) }}</strong></div>
                <form method="POST" action="{{ route('plugins.course-bundles.update', $bundle->id) }}" class="grid gap-2 sm:grid-cols-2">@csrf @method('PUT')
                    <input name="title" required value="{{ $bundle->title }}" class="rounded border px-3 py-2">
                    <input name="price" type="number" min="0" step="0.01" required value="{{ $bundle->price }}" class="rounded border px-3 py-2">
                    <textarea name="description" rows="2" class="sm:col-span-2 rounded border px-3 py-2">{{ $bundle->description }}</textarea>
                    <select name="course_ids[]" multiple required size="5" class="rounded border px-3 py-2">@foreach($courses as $course)<option value="{{ $course->id }}" @selected($bundle->items->contains('course_id', $course->id))>{{ $course->title }}</option>@endforeach</select>
                    <div class="space-y-2"><select name="status" class="w-full rounded border px-3 py-2"><option value="draft" @selected($bundle->status==='draft')>Draft</option><option value="published" @selected($bundle->status==='published')>Published</option><option value="archived" @selected($bundle->status==='archived')>Archived</option></select><button class="rounded bg-slate-900 px-4 py-2 text-white">Update bundle</button></div>
                </form>
                @if($bundle->status !== 'archived')<form method="POST" action="{{ route('plugins.course-bundles.archive', $bundle->id) }}">@csrf<button class="text-sm text-rose-700 underline">Archive bundle</button></form>@endif
            </article>
        @empty<p class="text-sm text-slate-500">No bundles created.</p>@endforelse
        {{ $bundles->links() }}
    </section>
</div>
@endsection
