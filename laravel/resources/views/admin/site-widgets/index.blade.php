@extends('layouts.admin')
@section('title', 'Widget Area Builder — AEC LMS')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <header>
        <p class="text-xs font-bold uppercase tracking-[.18em] text-blue-600">Website Studio</p>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900">Widget Area &amp; Sidebar Builder</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-500">Create, reorder, enable or disable reusable website widgets. Content is plain text; scripts and arbitrary HTML are never executed.</p>
    </header>
    @if(session('status'))<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('admin.site-widgets.update') }}" class="space-y-5">
        @csrf
        <div id="widget-list" class="space-y-4">
            @foreach(old('widgets', $widgets) as $i => $widget)
                <section class="widget-row rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
                    <div class="flex items-center justify-between gap-3"><h2 class="font-bold text-slate-900">Widget <span class="widget-number">{{ $loop->iteration }}</span></h2><button type="button" class="remove-widget rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700">Remove</button></div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="text-xs font-semibold text-slate-600">Widget type
                            <select name="widgets[{{ $i }}][type]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @foreach(['text'=>'Text','notice'=>'Notice / Announcement','search'=>'Course Search','cta'=>'Call to Action'] as $value=>$label)<option value="{{ $value }}" @selected(($widget['type'] ?? 'text') === $value)>{{ $label }}</option>@endforeach
                            </select>
                        </label>
                        <label class="text-xs font-semibold text-slate-600">Display area
                            <select name="widgets[{{ $i }}][area]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="course-sidebar" @selected(($widget['area'] ?? '') === 'course-sidebar')>Course detail sidebar</option>
                                <option value="site-sidebar" @selected(($widget['area'] ?? '') === 'site-sidebar')>General website sidebar</option>
                            </select>
                        </label>
                        <label class="text-xs font-semibold text-slate-600">Widget title
                            <input name="widgets[{{ $i }}][title]" value="{{ $widget['title'] ?? '' }}" maxlength="80" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="e.g. Need help?">
                        </label>
                        <label class="text-xs font-semibold text-slate-600">Order (1–100)
                            <input type="number" name="widgets[{{ $i }}][order]" value="{{ $widget['order'] ?? (($i + 1) * 10) }}" min="1" max="100" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>
                    </div>
                    <label class="block text-xs font-semibold text-slate-600">Text / notice / description
                        <textarea name="widgets[{{ $i }}][content]" maxlength="600" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Plain text only">{{ $widget['content'] ?? '' }}</textarea>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-xs font-semibold text-slate-600">CTA button label<input name="widgets[{{ $i }}][label]" value="{{ $widget['label'] ?? '' }}" maxlength="50" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
                        <label class="text-xs font-semibold text-slate-600 sm:col-span-2">CTA link (internal path or http/https URL)<input name="widgets[{{ $i }}][url]" value="{{ $widget['url'] ?? '' }}" maxlength="500" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="/contact-us"></label>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700"><input type="hidden" name="widgets[{{ $i }}][visible]" value="0"><input type="checkbox" name="widgets[{{ $i }}][visible]" value="1" @checked($widget['visible'] ?? true) class="rounded border-slate-300"> Enabled on website</label>
                </section>
            @endforeach
        </div>
        <div class="flex flex-wrap justify-between gap-3">
            <button type="button" id="add-widget" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-bold text-slate-700">+ Add Widget</button>
            <button type="submit" class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white">Save Widget Areas</button>
        </div>
    </form>
</div>
<template id="widget-template">
<section class="widget-row rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
    <div class="flex items-center justify-between gap-3"><h2 class="font-bold text-slate-900">Widget <span class="widget-number"></span></h2><button type="button" class="remove-widget rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700">Remove</button></div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-xs font-semibold text-slate-600">Widget type<select data-field="type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="text">Text</option><option value="notice">Notice / Announcement</option><option value="search">Course Search</option><option value="cta">Call to Action</option></select></label>
        <label class="text-xs font-semibold text-slate-600">Display area<select data-field="area" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="course-sidebar">Course detail sidebar</option><option value="site-sidebar">General website sidebar</option></select></label>
        <label class="text-xs font-semibold text-slate-600">Widget title<input data-field="title" maxlength="80" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
        <label class="text-xs font-semibold text-slate-600">Order<input data-field="order" type="number" min="1" max="100" value="10" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label>
    </div>
    <label class="block text-xs font-semibold text-slate-600">Text / notice / description<textarea data-field="content" maxlength="600" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea></label>
    <div class="grid gap-3 sm:grid-cols-3"><label class="text-xs font-semibold text-slate-600">CTA button label<input data-field="label" maxlength="50" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label><label class="text-xs font-semibold text-slate-600 sm:col-span-2">CTA link<input data-field="url" maxlength="500" placeholder="/contact-us" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></label></div>
    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700"><input data-field="visible" type="checkbox" value="1" checked class="rounded border-slate-300"> Enabled on website</label>
</section>
</template>
<script>
(() => {
    const list = document.getElementById('widget-list');
    const template = document.getElementById('widget-template');
    const add = document.getElementById('add-widget');
    const renumber = () => list.querySelectorAll('.widget-row').forEach((row, index) => row.querySelector('.widget-number').textContent = String(index + 1));
    const addWidget = () => {
        const count = list.querySelectorAll('.widget-row').length;
        if (count >= 16) { alert('Maximum 16 widgets.'); return; }
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('.widget-row');
        row.querySelectorAll('[data-field]').forEach(field => {
            const name = 'widgets[' + count + '][' + field.dataset.field + ']';
            field.name = name;
            if (field.type === 'checkbox') {
                const hidden = document.createElement('input');
                hidden.type = 'hidden'; hidden.name = name; hidden.value = '0';
                field.before(hidden);
            }
        });
        list.appendChild(fragment);
        renumber();
    };
    add.addEventListener('click', addWidget);
    list.addEventListener('click', event => {
        if (event.target.closest('.remove-widget')) { event.target.closest('.widget-row').remove(); renumber(); }
    });
    renumber();
})();
</script>
@endsection
