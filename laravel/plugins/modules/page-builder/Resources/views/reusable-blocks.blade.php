@extends('layouts.admin')
@section('title', 'Reusable Block Library — AEC LMS')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <header><p class="text-xs font-bold uppercase tracking-[.18em] text-blue-600">Website Studio</p><h1 class="mt-1 text-2xl font-extrabold text-slate-900">Reusable Block Library</h1><p class="mt-1 text-sm text-slate-500">Save frequently used headings, copy and call-to-action blocks for consistent page content.</p></header>
    @if(session('status'))<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="text-lg font-extrabold text-slate-900">Save a reusable block</h2>
        <form method="POST" action="{{ route('plugins.page_builder.reusable.store') }}" class="mt-4 grid gap-3 md:grid-cols-2">@csrf
            <label class="text-xs font-semibold text-slate-600">Block type<select name="type" required class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"><option value="heading">Heading</option><option value="text">Text</option><option value="button">Call to action</option></select></label>
            <label class="text-xs font-semibold text-slate-600">Library name<input name="title" required maxlength="80" value="{{ old('title') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="e.g. Course promotion heading"></label>
            <label class="text-xs font-semibold text-slate-600 md:col-span-2">Block content<textarea name="content" required maxlength="12000" rows="3" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">{{ old('content') }}</textarea></label>
            <label class="text-xs font-semibold text-slate-600 md:col-span-2">Button URL (required for CTA)<input name="url" maxlength="2048" value="{{ old('url') }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="/courses or https://example.com"></label>
            <div class="md:col-span-2 flex justify-end"><button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">Save Block</button></div>
        </form>
    </section>
    <section class="space-y-3"><h2 class="font-extrabold text-slate-900">Saved Blocks ({{ count($blocks) }})</h2>
        @forelse($blocks as $block)
        <article class="rounded-2xl border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-bold text-slate-900">{{ $block['title'] }}</h3><span class="mt-1 inline-block rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{{ ucfirst($block['type']) }}</span><p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $block['content'] }}</p>@if($block['url'])<p class="mt-1 break-all text-xs text-blue-600">{{ $block['url'] }}</p>@endif</div><form method="POST" action="{{ route('plugins.page_builder.reusable.destroy', $block['id']) }}" onsubmit="return confirm('Delete this reusable block?')">@csrf @method('DELETE')<button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700">Delete</button></form></div>
        </article>
        @empty
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">No reusable blocks saved yet.</div>
        @endforelse
    </section>
</div>
@endsection
