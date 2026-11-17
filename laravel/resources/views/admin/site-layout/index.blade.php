@extends('layouts.admin')
@section('title','Header & Footer Builder — AEC LMS')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <header><p class="text-xs font-bold uppercase tracking-[.18em] text-blue-600">Website Studio</p><h1 class="mt-1 text-2xl font-extrabold text-slate-900">Header &amp; Footer Builder</h1><p class="mt-1 max-w-2xl text-sm text-slate-500">Reorder website sections, show or hide components, choose layout styles and control the footer grid without editing code.</p></header>
    @if(session('status'))<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('admin.site-layout.update') }}" class="space-y-6">
        @csrf
        <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-extrabold text-slate-900">Website Header</h2><p class="text-xs text-slate-500">Drag the rows to change their display order.</p></div><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">Header Builder</span></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-xs font-semibold text-slate-600">Header style<select name="header[style]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="standard" @selected($header['style']==='standard')>Standard</option><option value="centered" @selected($header['style']==='centered')>Centered</option><option value="compact" @selected($header['style']==='compact')>Compact</option></select></label>
                <label class="text-xs font-semibold text-slate-600">Sticky header<select name="header[sticky]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="1" @selected($header['sticky'])>Enabled</option><option value="0" @selected(!$header['sticky'])>Disabled</option></select></label>
            </div>
            <div data-layout-list="header" class="space-y-2">
                @foreach($header['sections'] as $index=>$section)
                    <div data-layout-item draggable="true" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="cursor-grab select-none text-lg text-slate-400" aria-hidden="true">☷</span>
                        <strong class="min-w-32 flex-1 text-sm text-slate-800">{{ ['brand'=>'Brand / Logo','navigation'=>'Main Navigation','actions'=>'Login / Dashboard / Mobile Menu'][$section['key']] }}</strong>
                        <input type="hidden" data-field="key" name="header[sections][{{ $index }}][key]" value="{{ $section['key'] }}">
                        <input type="hidden" data-field="order" name="header[sections][{{ $index }}][order]" value="{{ $section['order'] }}">
                        <label class="text-xs font-semibold text-slate-600">Visibility<select data-field="visible" name="header[sections][{{ $index }}][visible]" class="ml-2 rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="1" @selected($section['visible'])>Show</option><option value="0" @selected(!$section['visible'])>Hide</option></select></label>
                    </div>
                @endforeach
            </div>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-extrabold text-slate-900">Website Footer</h2><p class="text-xs text-slate-500">Arrange footer columns and choose which sections are visible.</p></div><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">Footer Builder</span></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-xs font-semibold text-slate-600">Footer columns<select name="footer[columns]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@foreach([2,3,4] as $n)<option value="{{ $n }}" @selected($footer['columns']===$n)>{{ $n }} columns</option>@endforeach</select></label>
                <label class="text-xs font-semibold text-slate-600">Payment methods strip<select name="footer[show_payment_strip]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="1" @selected($footer['show_payment_strip'])>Show</option><option value="0" @selected(!$footer['show_payment_strip'])>Hide</option></select></label>
            </div>
            <div data-layout-list="footer" class="space-y-2">
                @foreach($footer['sections'] as $index=>$section)
                    <div data-layout-item draggable="true" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="cursor-grab select-none text-lg text-slate-400" aria-hidden="true">☷</span>
                        <strong class="min-w-32 flex-1 text-sm text-slate-800">{{ ['brand'=>'Brand & Social Links','links'=>'Footer Menu Links','company'=>'Company Details','contact'=>'Contact Information'][$section['key']] }}</strong>
                        <input type="hidden" data-field="key" name="footer[sections][{{ $index }}][key]" value="{{ $section['key'] }}">
                        <input type="hidden" data-field="order" name="footer[sections][{{ $index }}][order]" value="{{ $section['order'] }}">
                        <label class="text-xs font-semibold text-slate-600">Visibility<select data-field="visible" name="footer[sections][{{ $index }}][visible]" class="ml-2 rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="1" @selected($section['visible'])>Show</option><option value="0" @selected(!$section['visible'])>Hide</option></select></label>
                    </div>
                @endforeach
            </div>
        </section>
        <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-slate-500">Changes are recorded in the Core Audit Log. At least one section must remain visible in each area.</p><button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">Save Header &amp; Footer Layout</button></div>
    </form>
</div>
<script>
(()=>{document.querySelectorAll('[data-layout-list]').forEach(list=>{const area=list.dataset.layoutList;const renumber=()=>{[...list.querySelectorAll('[data-layout-item]')].forEach((row,i)=>row.querySelectorAll('[data-field]').forEach(input=>{input.name=area+'[sections]['+i+']['+input.dataset.field+']';if(input.dataset.field==='order')input.value=(i+1)*10}))};let dragged=null;list.addEventListener('dragstart',e=>{dragged=e.target.closest('[data-layout-item]');if(dragged){e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain','layout');dragged.classList.add('opacity-50')}});list.addEventListener('dragover',e=>{if(!dragged)return;e.preventDefault();const target=e.target.closest('[data-layout-item]');if(!target||target===dragged)return;const rect=target.getBoundingClientRect();list.insertBefore(dragged,e.clientY>rect.top+rect.height/2?target.nextElementSibling:target)});list.addEventListener('drop',()=>{dragged?.classList.remove('opacity-50');dragged=null;renumber()});list.addEventListener('dragend',()=>{dragged?.classList.remove('opacity-50');dragged=null;renumber()});renumber()})})();
</script>
@endsection
