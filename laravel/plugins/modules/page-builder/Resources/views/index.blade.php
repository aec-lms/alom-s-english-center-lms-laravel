@extends('layouts.admin')
@section('title', 'Page Builder — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Independent Page Builder</h1>
        <p class="text-xs text-slate-500 mt-0.5">Build, schedule, and publish custom landing pages, admission policies, and campaign pages with block layouts and SEO metadata.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Create New Custom Page</h2>
        <form method="POST" action="{{ route('plugins.page_builder.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-2 gap-3 text-xs">
            @csrf
            <input name="title" required placeholder="Page Title (e.g. Scholarship Policy) *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="slug" required placeholder="URL Slug (e.g. scholarship-policy) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
            <select name="template" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="standard">Standard Article Layout</option>
                <option value="landing">Campaign Landing Page</option>
                <option value="fullwidth">Full-Width Showcase</option>
            </select>
            <input type="file" name="banner" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
            <input name="seo_title" placeholder="SEO Meta Title" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="seo_description" placeholder="SEO Meta Description" class="rounded-lg border border-slate-300 px-3 py-2">
            <textarea name="content_body" rows="5" required placeholder="Page Content / Rich HTML or Text Block *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2"></textarea>
            <div class="sm:col-span-2 flex items-center justify-between">
                <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_published" value="1" checked> Publish immediately</label>
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Publish Page</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <h2 class="text-sm font-bold text-slate-900">Published &amp; Draft Pages ({{ $pages->count() }})</h2>
        @forelse ($pages as $page)
            @php $body = is_array($page->blocks_json) ? ($page->blocks_json[0]['content'] ?? '') : ''; @endphp
            <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-bold text-sm text-slate-900">{{ $page->title }}</div>
                        <div class="font-mono text-slate-500">
                            <a href="{{ route('public.custom_page', $page->slug) }}" target="_blank" class="text-[#0284C7] hover:underline">/page/{{ $page->slug }}</a>
                            &middot; Template: {{ $page->template ?? 'standard' }} &middot; {{ $page->is_published ? 'Published' : 'Draft' }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('plugins.page_builder.destroy', $page) }}" onsubmit="return confirm('Delete page?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold">Delete</button>
                    </form>
                </div>
                <details class="pt-2 border-t border-slate-100">
                    <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit Page Blocks</summary>
                    <form method="POST" action="{{ route('plugins.page_builder.update', $page) }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-2 gap-3">
                        @csrf @method('PUT')
                        <input name="title" required value="{{ $page->title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                        <input name="slug" required value="{{ $page->slug }}" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                        <textarea name="content_body" rows="4" required class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">{{ $body }}</textarea>
                        <div class="sm:col-span-2 flex items-center justify-between">
                            <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_published" value="1" @checked($page->is_published)> Published</label>
                            <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Page</button>
                        </div>
                    </form>
                </details>
            </div>
        @empty
            <p class="text-xs text-slate-400">No custom pages yet.</p>
        @endforelse
    </div>
</div>
@endsection
