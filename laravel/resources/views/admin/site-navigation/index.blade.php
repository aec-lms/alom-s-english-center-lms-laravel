@extends('layouts.admin')
@section('title', 'Website Navigation — AEC LMS')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <header>
        <div class="text-xs font-bold uppercase tracking-[.18em] text-blue-600">Website Studio</div>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900">Website Navigation Builder</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-500">Create and organize the public website header and footer menus without editing Blade files. Use a page path such as /about-us or a trusted HTTPS URL.</p>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><strong>Please review the menu items.</strong><ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('admin.site-navigation.update') }}" class="space-y-5">
        @csrf
        @foreach (['primary' => ['title' => 'Header / Main Menu', 'description' => 'Shown in the desktop header and mobile navigation.', 'items' => $primary], 'footer' => ['title' => 'Footer Links', 'description' => 'Shown in the website footer links column.', 'items' => $footer]] as $location => $menu)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="text-lg font-extrabold text-slate-900">{{ $menu['title'] }}</h2><p class="text-xs text-slate-500">{{ $menu['description'] }}</p></div>
                    <button type="button" data-add-item="{{ $location }}" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white">＋ Add menu item</button>
                </div>
                <div data-menu-list="{{ $location }}" class="space-y-2">
                    @foreach ($menu['items'] as $index => $item)
                        <div data-menu-item draggable="true" class="grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 md:grid-cols-[minmax(140px,1fr)_minmax(180px,2fr)_90px_110px_auto] md:items-end">
                            <label class="text-[11px] font-semibold text-slate-600">Label<input data-field="label" name="menus[{{ $location }}][{{ $index }}][label]" required maxlength="60" value="{{ $item['label'] }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"></label>
                            <label class="text-[11px] font-semibold text-slate-600">URL / Path<input data-field="url" name="menus[{{ $location }}][{{ $index }}][url]" required maxlength="2048" value="{{ $item['url'] }}" placeholder="/courses or https://example.com" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-sm"></label>
                            <label class="text-[11px] font-semibold text-slate-600">Order<input data-field="order" type="number" name="menus[{{ $location }}][{{ $index }}][order]" required min="-1000" max="1000" value="{{ $item['order'] }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"></label>
                            <label class="text-[11px] font-semibold text-slate-600">Visibility<select data-field="visibility" name="menus[{{ $location }}][{{ $index }}][visibility]" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="show" @selected(!$item['hidden'])>Show</option><option value="hide" @selected($item['hidden'])>Hide</option></select></label>
                            <div class="flex items-center justify-end gap-2"><button type="button" data-move-up class="rounded-lg border border-slate-300 bg-white px-2 py-2 text-xs" aria-label="Move up">↑</button><button type="button" data-move-down class="rounded-lg border border-slate-300 bg-white px-2 py-2 text-xs" aria-label="Move down">↓</button><button type="button" data-remove-item class="rounded-lg border border-rose-200 bg-white px-2 py-2 text-xs font-semibold text-rose-600">Remove</button></div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-[11px] text-slate-500">Drag rows or use the arrows to reorder. Hidden items remain saved and can be shown again later. Maximum 30 items per menu.</p>
            </section>
        @endforeach

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">Changes are logged in the Core Audit Log. Only administrators with Core Settings permission can edit these menus.</p>
            <button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-blue-700">Save Website Navigation</button>
        </div>
    </form>
</div>

<template id="navigation-item-template">
    <div data-menu-item draggable="true" class="grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 md:grid-cols-[minmax(140px,1fr)_minmax(180px,2fr)_90px_110px_auto] md:items-end">
        <label class="text-[11px] font-semibold text-slate-600">Label<input data-field="label" required maxlength="60" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"></label>
        <label class="text-[11px] font-semibold text-slate-600">URL / Path<input data-field="url" required maxlength="2048" placeholder="/courses or https://example.com" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-sm"></label>
        <label class="text-[11px] font-semibold text-slate-600">Order<input data-field="order" type="number" required min="-1000" max="1000" value="100" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"></label>
        <label class="text-[11px] font-semibold text-slate-600">Visibility<select data-field="visibility" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="show">Show</option><option value="hide">Hide</option></select></label>
        <div class="flex items-center justify-end gap-2"><button type="button" data-move-up class="rounded-lg border border-slate-300 bg-white px-2 py-2 text-xs" aria-label="Move up">↑</button><button type="button" data-move-down class="rounded-lg border border-slate-300 bg-white px-2 py-2 text-xs" aria-label="Move down">↓</button><button type="button" data-remove-item class="rounded-lg border border-rose-200 bg-white px-2 py-2 text-xs font-semibold text-rose-600">Remove</button></div>
    </div>
</template>
<script>
(function () {
    const template = document.getElementById('navigation-item-template');
    document.querySelectorAll('[data-menu-list]').forEach((list) => {
        const location = list.dataset.menuList;
        const addButton = document.querySelector('[data-add-item="' + location + '"]');
        const renumber = () => {
            const rows = [...list.querySelectorAll('[data-menu-item]')];
            rows.forEach((row, index) => {
                row.querySelectorAll('[data-field]').forEach((input) => {
                    input.name = 'menus[' + location + '][' + index + '][' + input.dataset.field + ']';
                    if (input.dataset.field === 'order') input.value = (index + 1) * 10;
                });
                const up = row.querySelector('[data-move-up]');
                const down = row.querySelector('[data-move-down]');
                if (up) up.disabled = index === 0;
                if (down) down.disabled = index === rows.length - 1;
            });
        };
        list.addEventListener('click', (event) => {
            const row = event.target.closest('[data-menu-item]');
            if (event.target.closest('[data-move-up]') && row?.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
                renumber();
            } else if (event.target.closest('[data-move-down]') && row?.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row);
                renumber();
            } else if (event.target.closest('[data-remove-item]')) {
                row?.remove();
                renumber();
            }
        });
        let dragged = null;
        list.addEventListener('dragstart', (event) => {
            dragged = event.target.closest('[data-menu-item]');
            if (dragged) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', 'menu-item');
                dragged.classList.add('opacity-50');
            }
        });
        list.addEventListener('dragover', (event) => {
            if (!dragged) return;
            event.preventDefault();
            const target = event.target.closest('[data-menu-item]');
            if (!target || target === dragged) return;
            const rect = target.getBoundingClientRect();
            list.insertBefore(dragged, event.clientY > rect.top + rect.height / 2 ? target.nextElementSibling : target);
        });
        list.addEventListener('drop', () => { dragged?.classList.remove('opacity-50'); dragged = null; renumber(); });
        list.addEventListener('dragend', () => { dragged?.classList.remove('opacity-50'); dragged = null; renumber(); });
        addButton?.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            list.appendChild(fragment);
            renumber();
        });
        renumber();
    });
})();
</script>
@endsection
