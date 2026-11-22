@extends('layouts.admin')
@section('title', 'Visual Page Builder — AEC LMS')
@section('content')
@php
    $editorBlocks = static function ($blocks): array {
        $result = [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (!is_array($block)) continue;
            $type = $block['type'] ?? '';
            if ($type === 'rich_text') $type = 'text';
            if ($type === 'cta') $type = 'button';
            if (!in_array($type, ['heading', 'text', 'image', 'button', 'spacer', 'course_grid', 'instructor_grid'], true)) continue;
            $result[] = [
                'type' => $type,
                'content' => $block['content'] ?? $block['label'] ?? '',
                'url' => $block['url'] ?? '',
                'alt' => $block['alt'] ?? '',
                'align' => $block['align'] ?? 'left',
                'height' => $block['height'] ?? 48,
            ];
        }
        return $result ?: [['type' => 'text', 'content' => '', 'url' => '', 'alt' => '', 'align' => 'left', 'height' => 48]];
    };
@endphp
<div class="mx-auto max-w-7xl space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="text-xs font-bold uppercase tracking-[.18em] text-blue-600">Website Studio</div>
            <h1 class="mt-1 text-2xl font-extrabold text-slate-900">Visual Page Builder</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-500">Create landing pages with reusable content blocks. No HTML or coding required.</p>
            <p data-block-status role="status" aria-live="polite" aria-atomic="true" class="mt-2 text-xs text-slate-500">Use drag-and-drop or the Up and Down controls to reorder blocks.</p>
        </div>
        <div class="flex flex-wrap gap-2"><a href="{{ route('plugins.page_builder.reusable.index') }}" class="rounded-xl border border-violet-200 bg-white px-4 py-2.5 text-sm font-semibold text-violet-700">Reusable Blocks</a><a href="{{ route('home') }}" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700">View Website ↗</a></div>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <strong>Please review the page fields.</strong>
            <ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex items-center justify-between gap-3">
                <div><h2 class="text-lg font-extrabold text-slate-900">Create a page</h2><p class="text-xs text-slate-500">Start with a title, then add and arrange content blocks.</p></div>
                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">New page</span>
            </div>
            <form method="POST" action="{{ route('plugins.page_builder.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-semibold text-slate-600">Page title
                        <input name="title" required maxlength="180" placeholder="e.g. About our English Center" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    </label>
                    <label class="text-xs font-semibold text-slate-600">Page URL slug
                        <input name="slug" required maxlength="160" pattern="[A-Za-z0-9_-]+" placeholder="about-our-center" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-mono text-sm">
                    </label>
                    <label class="text-xs font-semibold text-slate-600">Page layout
                        <select name="template" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                            <option value="standard">Standard</option><option value="landing">Landing page</option><option value="fullwidth">Full width</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-600">Cover image
                        <input type="file" name="banner" accept="image/jpeg,image/png,image/webp" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="text-xs font-semibold text-slate-600">SEO title
                        <input name="seo_title" maxlength="180" placeholder="Search result title" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    </label>
                    <label class="text-xs font-semibold text-slate-600">SEO description
                        <input name="seo_description" maxlength="320" placeholder="Short search description" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    </label>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div><h3 class="font-bold text-slate-900">Page content</h3><p class="text-xs text-slate-500">Add headings, text, images, buttons and spacing.</p></div>
                        <div class="flex flex-wrap gap-2">
                            <select data-reusable-select class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs" aria-label="Choose reusable block">
                                <option value="">Insert reusable block…</option>
                                @foreach(($reusableBlocks ?? []) as $saved)
                                    <option value="{{ $saved['id'] }}">{{ $saved['title'] }}</option>
                                @endforeach
                            </select>
                            <button type="button" data-insert-reusable class="rounded-lg border border-violet-200 bg-white px-3 py-2 text-xs font-bold text-violet-700">Insert</button>
                            <button type="button" data-add-block class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white">＋ Add block</button>
                        </div>
                    </div>
                    <div data-block-list class="space-y-3">
                        <div data-block draggable="true" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="mb-3 flex items-center justify-between gap-2"><span class="text-xs font-bold text-slate-700">Block 1 · drag to reorder</span><div class="flex items-center gap-3"><button type="button" data-move-up aria-label="Move block up" class="text-xs font-semibold text-slate-600">↑ Up</button><button type="button" data-move-down aria-label="Move block down" class="text-xs font-semibold text-slate-600">↓ Down</button><button type="button" data-remove-block class="text-xs font-semibold text-rose-600">Remove</button></div></div>
                            <label class="text-xs font-semibold text-slate-600">Block type
                                <select name="blocks[0][type]" data-block-type class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="text">Text</option><option value="heading">Heading</option><option value="image">Image</option><option value="button">Button</option><option value="spacer">Spacing</option><option value="course_grid">Course Grid</option><option value="instructor_grid">Instructor Grid</option></select>
                            </label>
                            <div data-field="content" class="mt-3"><label class="text-xs font-semibold text-slate-600">Text / Heading / Button label<textarea name="blocks[0][content]" rows="3" maxlength="12000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Write your content here"></textarea></label></div>
                            <div data-field="url" class="mt-3 hidden"><label class="text-xs font-semibold text-slate-600">Image or button URL<input name="blocks[0][url]" maxlength="2048" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="/storage/... or https://..."></label></div>
                            <div data-field="alt" class="mt-3 hidden"><label class="text-xs font-semibold text-slate-600">Image description (alt text)<input name="blocks[0][alt]" maxlength="180" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label></div>
                            <div data-field="align" class="mt-3"><label class="text-xs font-semibold text-slate-600">Alignment<select name="blocks[0][align]" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></label></div>
                            <div data-field="height" class="mt-3 hidden"><label class="text-xs font-semibold text-slate-600">Space height (px)<input type="number" name="blocks[0][height]" min="16" max="240" value="48" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label></div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <label class="flex items-center gap-2 text-sm font-semibold text-slate-700"><input type="checkbox" name="is_published" value="1" checked> Publish page immediately</label>
                    <button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-blue-700">Save page</button>
                </div>
            </form>
        </div>

        <aside class="space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <h3 class="font-bold text-slate-900">Available blocks</h3>
                <div class="mt-3 space-y-3 text-sm">
                    <div><span class="font-semibold">Heading</span><p class="text-xs text-slate-500">Section titles and page headings</p></div>
                    <div><span class="font-semibold">Text</span><p class="text-xs text-slate-500">Paragraphs and descriptions</p></div>
                    <div><span class="font-semibold">Image</span><p class="text-xs text-slate-500">Public image URL with accessible alt text</p></div>
                    <div><span class="font-semibold">Button</span><p class="text-xs text-slate-500">Calls to action linking to LMS pages or HTTPS URLs</p></div>
                    <div><span class="font-semibold">Spacing</span><p class="text-xs text-slate-500">Control vertical space between sections</p></div>
                    <div><span class="font-semibold">Course Grid</span><p class="text-xs text-slate-500">Automatically show published LMS courses</p></div>
                    <div><span class="font-semibold">Instructor Grid</span><p class="text-xs text-slate-500">Automatically show active teachers</p></div>
                </div>
            </div>
            <div class="rounded-2xl border border-blue-100 bg-blue-50 p-5">
                <h3 class="font-bold text-blue-900">Safe by design</h3>
                <p class="mt-2 text-xs leading-5 text-blue-800">Content is stored as structured blocks, not executable HTML. Links are restricted to safe relative paths or HTTP(S), and page access follows the publish status.</p>
            </div>
        </aside>
    </section>

    <section class="space-y-3">
        <div class="flex items-end justify-between"><div><h2 class="text-lg font-extrabold text-slate-900">Your pages</h2><p class="text-xs text-slate-500">Edit, preview or move a page to the recycle bin.</p></div><span class="text-xs font-bold text-slate-500">{{ $pages->count() }} total</span></div>
        @forelse ($pages as $page)
            @php $blocks = $editorBlocks($page->blocks_json); @endphp
            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0"><div class="font-bold text-slate-900">{{ $page->title }}</div><div class="mt-1 text-xs text-slate-500">/page/{{ $page->slug }} · {{ $page->template }} · {{ count($blocks) }} blocks</div></div>
                    <div class="flex items-center gap-2"><span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ $page->is_published ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $page->is_published ? 'Published' : 'Draft' }}</span><a href="{{ route('public.custom_page', $page->slug) }}" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-blue-700">Preview ↗</a></div>
                </summary>
                <div class="border-t border-slate-100 p-4">
                    <form method="POST" action="{{ route('plugins.page_builder.update', $page) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf @method('PUT')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-xs font-semibold text-slate-600">Page title<input name="title" required maxlength="180" value="{{ $page->title }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
                            <label class="text-xs font-semibold text-slate-600">URL slug<input name="slug" required maxlength="160" pattern="[A-Za-z0-9_-]+" value="{{ $page->slug }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm"></label>
                            <label class="text-xs font-semibold text-slate-600">Layout<select name="template" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">@foreach (['standard'=>'Standard','landing'=>'Landing page','fullwidth'=>'Full width'] as $key=>$label)<option value="{{ $key }}" @selected(($page->template ?? 'standard') === $key)>{{ $label }}</option>@endforeach</select></label>
                            <label class="text-xs font-semibold text-slate-600">Replace cover image<input type="file" name="banner" accept="image/jpeg,image/png,image/webp" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
                            <label class="text-xs font-semibold text-slate-600">SEO title<input name="seo_title" maxlength="180" value="{{ $page->seo_title }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
                            <label class="text-xs font-semibold text-slate-600">SEO description<input name="seo_description" maxlength="320" value="{{ $page->seo_description }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
                        </div>
                        <div class="space-y-3" data-block-list>
                            @foreach ($blocks as $index => $block)
                                <div data-block draggable="true" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="mb-3 flex items-center justify-between gap-2"><span class="text-xs font-bold text-slate-700">Block {{ $index + 1 }} · drag to reorder</span><div class="flex items-center gap-3"><button type="button" data-move-up aria-label="Move block up" class="text-xs font-semibold text-slate-600">↑ Up</button><button type="button" data-move-down aria-label="Move block down" class="text-xs font-semibold text-slate-600">↓ Down</button><button type="button" data-remove-block class="text-xs font-semibold text-rose-600">Remove</button></div></div>
                                    <label class="text-xs font-semibold text-slate-600">Block type<select name="blocks[{{ $index }}][type]" data-block-type class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">@foreach (['text'=>'Text','heading'=>'Heading','image'=>'Image','button'=>'Button','spacer'=>'Spacing','course_grid'=>'Course Grid','instructor_grid'=>'Instructor Grid'] as $key=>$label)<option value="{{ $key }}" @selected($block['type'] === $key)>{{ $label }}</option>@endforeach</select></label>
                                    <div data-field="content" class="mt-3 {{ $block['type'] === 'spacer' || $block['type'] === 'image' ? 'hidden' : '' }}"><label class="text-xs font-semibold text-slate-600">Text / Heading / Button label<textarea name="blocks[{{ $index }}][content]" rows="3" maxlength="12000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ $block['content'] }}</textarea></label></div>
                                    <div data-field="url" class="mt-3 {{ in_array($block['type'], ['image','button'], true) ? '' : 'hidden' }}"><label class="text-xs font-semibold text-slate-600">Image or button URL<input name="blocks[{{ $index }}][url]" maxlength="2048" value="{{ $block['url'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label></div>
                                    <div data-field="alt" class="mt-3 {{ $block['type'] === 'image' ? '' : 'hidden' }}"><label class="text-xs font-semibold text-slate-600">Image alt text<input name="blocks[{{ $index }}][alt]" maxlength="180" value="{{ $block['alt'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label></div>
                                    <div data-field="align" class="mt-3 {{ in_array($block['type'], ['heading','text','button'], true) ? '' : 'hidden' }}"><label class="text-xs font-semibold text-slate-600">Alignment<select name="blocks[{{ $index }}][align]" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">@foreach (['left'=>'Left','center'=>'Center','right'=>'Right'] as $key=>$label)<option value="{{ $key }}" @selected($block['align'] === $key)>{{ $label }}</option>@endforeach</select></label></div>
                                    <div data-field="height" class="mt-3 {{ $block['type'] === 'spacer' ? '' : 'hidden' }}"><label class="text-xs font-semibold text-slate-600">Space height (px)<input type="number" name="blocks[{{ $index }}][height]" min="16" max="240" value="{{ $block['height'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                            <div class="flex flex-wrap items-center gap-3"><select data-reusable-select class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs" aria-label="Choose reusable block"><option value="">Insert reusable block…</option>@foreach(($reusableBlocks ?? []) as $saved)<option value="{{ $saved['id'] }}">{{ $saved['title'] }}</option>@endforeach</select><button type="button" data-insert-reusable class="rounded-lg border border-violet-200 bg-white px-3 py-2 text-xs font-bold text-violet-700">Insert</button><button type="button" data-add-block class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700">＋ Add block</button><label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" name="is_published" value="1" @checked($page->is_published)> Published</label></div>
                            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2.5 text-xs font-bold text-white">Save changes</button>
                        </div>
                    </form>
                    <section class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4" aria-label="Page revision history">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div><h3 class="text-sm font-bold text-slate-800">Revision history</h3><p class="text-xs text-slate-500">Every saved version is retained. Restoring a version creates a new revision; it does not erase newer versions.</p></div>
                            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $page->revisions->count() }} versions</span>
                        </div>
                        <div class="space-y-2">
                            @forelse ($page->revisions as $revision)
                                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3">
                                    <div class="text-xs text-slate-600">
                                        <strong class="text-slate-800">Version {{ $revision->version }}</strong>
                                        <span class="mx-1">·</span>{{ $revision->creator?->name ?? 'System / former user' }}
                                        <span class="mx-1">·</span>{{ $revision->created_at?->format('d M Y, h:i A') }}
                                    </div>
                                    <form method="POST" action="{{ route('plugins.page_builder.revisions.restore', [$page, $revision]) }}" onsubmit="return confirm('Restore version {{ $revision->version }}? The current page will be saved as a new version first.')">
                                        @csrf
                                        <button type="submit" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-bold text-sky-800">Restore version {{ $revision->version }}</button>
                                    </form>
                                </div>
                            @empty
                                <p class="text-xs text-slate-500">No saved revisions yet.</p>
                            @endforelse
                        </div>
                    </section>
                    <form method="POST" action="{{ route('plugins.page_builder.destroy', $page) }}" class="mt-3" onsubmit="return confirm('Move this page to the recycle bin?')">@csrf @method('DELETE')<button type="submit" class="text-xs font-semibold text-rose-600">Move to recycle bin</button></form>
                </div>
            </details>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center"><div class="text-sm font-bold text-slate-700">No pages yet</div><p class="mt-1 text-xs text-slate-500">Create your first page using the visual editor above.</p></div>
        @endforelse
    </section>
</div>

<template id="page-block-template">
    <div data-block draggable="true" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
        <div class="mb-3 flex items-center justify-between gap-2"><span data-block-label class="text-xs font-bold text-slate-700">New block</span><div class="flex items-center gap-3"><button type="button" data-move-up aria-label="Move block up" class="text-xs font-semibold text-slate-600">↑ Up</button><button type="button" data-move-down aria-label="Move block down" class="text-xs font-semibold text-slate-600">↓ Down</button><button type="button" data-remove-block class="text-xs font-semibold text-rose-600">Remove</button></div></div>
        <label class="text-xs font-semibold text-slate-600">Block type<select data-name="type" data-block-type class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="text">Text</option><option value="heading">Heading</option><option value="image">Image</option><option value="button">Button</option><option value="spacer">Spacing</option><option value="course_grid">Course Grid</option><option value="instructor_grid">Instructor Grid</option></select></label>
        <div data-field="content" class="mt-3"><label class="text-xs font-semibold text-slate-600">Text / Heading / Button label<textarea data-name="content" rows="3" maxlength="12000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea></label></div>
        <div data-field="url" class="mt-3 hidden"><label class="text-xs font-semibold text-slate-600">Image or button URL<input data-name="url" maxlength="2048" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="/storage/... or https://..."></label></div>
        <div data-field="alt" class="mt-3 hidden"><label class="text-xs font-semibold text-slate-600">Image alt text<input data-name="alt" maxlength="180" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label></div>
        <div data-field="align" class="mt-3"><label class="text-xs font-semibold text-slate-600">Alignment<select data-name="align" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></label></div>
        <div data-field="height" class="mt-3 hidden"><label class="text-xs font-semibold text-slate-600">Space height (px)<input data-name="height" type="number" min="16" max="240" value="48" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label></div>
    </div>
</template>
<script>
(function () {
    const reusableLibrary = @json(($reusableBlocks ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    const template = document.getElementById('page-block-template');
    document.querySelectorAll('[data-block-list]').forEach((list) => {
        const form = list.closest('form');
        const addButton = form?.querySelector('[data-add-block]');
        const insertButton = form?.querySelector('[data-insert-reusable]');
        const reusableSelect = form?.querySelector('[data-reusable-select]');
        const status = document.querySelector('[data-block-status]');
        const announce = (message) => { if (status) status.textContent = message; };
        const sync = (block) => {
            const type = block.querySelector('[data-block-type]')?.value || 'text';
            const visible = {
                content: ['heading', 'text', 'button'].includes(type),
                url: ['image', 'button'].includes(type),
                alt: type === 'image',
                align: ['heading', 'text', 'button'].includes(type),
                height: type === 'spacer'
            };
            Object.entries(visible).forEach(([field, show]) => {
                const wrapper = block.querySelector('[data-field="' + field + '"]');
                if (wrapper) wrapper.classList.toggle('hidden', !show);
            });
        };
        const renumber = () => {
            const blocks = [...list.querySelectorAll('[data-block]')];
            blocks.forEach((block, index) => {
                block.querySelectorAll('[data-name]').forEach((input) => {
                    input.name = 'blocks[' + index + '][' + input.dataset.name + ']';
                });
                const type = block.querySelector('[data-block-type]');
                if (type) type.name = 'blocks[' + index + '][type]';
                const label = block.querySelector('[data-block-label]') || block.querySelector(':scope > div:first-child > span');
                if (label) label.textContent = 'Block ' + (index + 1) + ' · drag to reorder';
                const up = block.querySelector('[data-move-up]');
                const down = block.querySelector('[data-move-down]');
                if (up) up.disabled = index === 0;
                if (down) down.disabled = index === blocks.length - 1;
            });
        };

        list.querySelectorAll('[data-block]').forEach((block) => {
            block.querySelector('[data-block-type]')?.addEventListener('change', () => sync(block));
            sync(block);
        });
        renumber();

        list.addEventListener('click', (event) => {
            const current = event.target.closest('[data-block]');
            if (event.target.closest('[data-move-up]') && current?.previousElementSibling) {
                list.insertBefore(current, current.previousElementSibling);
                renumber();
                announce('Block moved up to position ' + ([...list.querySelectorAll('[data-block]')].indexOf(current) + 1) + '.');
                return;
            }
            if (event.target.closest('[data-move-down]') && current?.nextElementSibling) {
                list.insertBefore(current.nextElementSibling, current);
                renumber();
                announce('Block moved down to position ' + ([...list.querySelectorAll('[data-block]')].indexOf(current) + 1) + '.');
                return;
            }
            if (event.target.closest('[data-remove-block]')) {
                const blocks = list.querySelectorAll('[data-block]');
                if (blocks.length <= 1) return;
                current?.remove();
                renumber();
                announce('Block removed. ' + list.querySelectorAll('[data-block]').length + ' blocks remain.');
            }
        });

        let dragged = null;
        list.addEventListener('dragstart', (event) => {
            dragged = event.target.closest('[data-block]');
            if (dragged) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', 'page-block');
                dragged.classList.add('opacity-50');
            }
        });
        list.addEventListener('dragover', (event) => {
            if (!dragged) return;
            event.preventDefault();
            const target = event.target.closest('[data-block]');
            if (!target || target === dragged) return;
            const bounds = target.getBoundingClientRect();
            const after = event.clientY > bounds.top + bounds.height / 2;
            list.insertBefore(dragged, after ? target.nextElementSibling : target);
        });
        list.addEventListener('drop', () => {
            if (dragged) {
                const position = [...list.querySelectorAll('[data-block]')].indexOf(dragged) + 1;
                dragged.classList.remove('opacity-50');
                announce('Block moved to position ' + position + '.');
            }
            dragged = null;
            renumber();
        });
        list.addEventListener('dragend', () => {
            if (dragged) dragged.classList.remove('opacity-50');
            dragged = null;
            renumber();
        });

        insertButton?.addEventListener('click', () => {
            const id = reusableSelect?.value;
            const saved = reusableLibrary.find((item) => item.id === id);
            if (!saved) return;
            const fragment = template.content.cloneNode(true);
            const block = fragment.querySelector('[data-block]');
            const typeSelect = block.querySelector('[data-block-type]');
            if (!['heading', 'text', 'button'].includes(saved.type)) return;
            typeSelect.value = saved.type;
            const contentInput = block.querySelector('[data-field="content"] textarea');
            if (contentInput) contentInput.value = saved.content || '';
            const urlInput = block.querySelector('[data-field="url"] input');
            if (urlInput) urlInput.value = saved.url || '';
            list.appendChild(fragment);
            renumber();
            sync(block);
            announce('Reusable block inserted. ' + list.querySelectorAll('[data-block]').length + ' blocks on this page.');
            if (reusableSelect) reusableSelect.value = '';
        });
        addButton?.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            const block = fragment.querySelector('[data-block]');
            list.appendChild(fragment);
            renumber();
            announce('Block ' + list.querySelectorAll('[data-block]').length + ' added.');
            block.querySelector('[data-block-type]')?.addEventListener('change', () => sync(block));
            sync(block);
        });
    });
})();
</script>
@endsection
