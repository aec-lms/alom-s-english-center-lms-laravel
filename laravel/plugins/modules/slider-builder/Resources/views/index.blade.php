@extends('layouts.admin')
@section('title', 'Advanced Slider Builder — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-3"><div><h1 class="text-2xl font-extrabold text-slate-900">Advanced Slider Builder</h1><p class="mt-1 text-sm text-slate-500">Responsive hero, campaign banners, scheduled announcements and course promotions.</p></div><span class="rounded-full bg-sky-50 px-3 py-1 text-xs font-bold text-sky-700">Slider Builder 2.1</span></header>
    @if ($errors->any())<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"><p class="font-bold">Please correct the following:</p><ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-base font-extrabold text-slate-900">Create a slide</h2>
        <form method="POST" action="{{ route('plugins.slider_builder.store') }}" enctype="multipart/form-data" id="slider-create-form" class="mt-4 grid gap-3 md:grid-cols-2">@csrf
            <label class="text-xs font-semibold text-slate-600">Placement<select name="key_name" required class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="hero">Homepage Hero</option><option value="banner_slider">Promotional Banner</option><option value="course_promotion">Course Promotion</option><option value="announcement">Announcement</option></select></label>
            <label class="text-xs font-semibold text-slate-600">Display order<input name="sort_order" type="number" min="0" max="65535" value="0" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600 md:col-span-2">Headline *<input name="title" required maxlength="180" placeholder="Your headline" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600 md:col-span-2">Subtitle<textarea name="subtitle" maxlength="500" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea></label>
            <label class="text-xs font-semibold text-slate-600">Desktop image (JPG/PNG/WebP, max 6 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-lg border border-slate-300 p-2 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Mobile image (optional)<input type="file" name="mobile_image" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-lg border border-slate-300 p-2 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Existing desktop image path<input name="image_path" placeholder="/assets/images/slide.webp" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Existing mobile image path<input name="mobile_image_path" placeholder="/assets/images/slide-mobile.webp" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Button label<input name="button_label" maxlength="60" placeholder="Explore courses" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Button destination<input name="cta_url" maxlength="255" placeholder="/courses" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Text alignment<select name="text_alignment" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="center">Center</option><option value="left">Left</option><option value="right">Right</option></select></label>
            <label class="text-xs font-semibold text-slate-600">Transition<select name="transition" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="fade">Fade</option><option value="slide">Slide</option></select></label>
            <label class="text-xs font-semibold text-slate-600">Overlay opacity (0–90)<input name="overlay_opacity" type="number" min="0" max="90" value="45" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Autoplay duration (ms)<input name="duration_ms" type="number" min="2000" max="15000" value="5000" step="500" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Publish from<input name="starts_at" type="datetime-local" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-xs font-semibold text-slate-600">Unpublish at<input name="ends_at" type="datetime-local" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <div class="md:col-span-2 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3"><div class="flex flex-wrap gap-5 text-sm"><label><input type="checkbox" name="is_active" value="1" checked> Active</label><label><input type="checkbox" name="open_new_tab" value="1"> Open CTA in new tab</label></div><button class="rounded-lg bg-sky-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-sky-700">Create slide</button></div>
        </form>
        <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-slate-950 text-white">
            <div class="border-b border-white/10 px-4 py-2 text-xs font-bold uppercase tracking-wide text-slate-300">Live slide preview</div>
            <div id="slider-live-preview" class="relative flex min-h-44 items-center justify-center overflow-hidden bg-cover bg-center p-6 text-center" style="background-image:linear-gradient(rgba(2,10,20,.45),rgba(2,10,20,.45))">
                <div class="relative z-10 max-w-2xl"><h3 id="slider-preview-title" class="text-2xl font-extrabold">Your headline</h3><p id="slider-preview-subtitle" class="mt-2 text-sm text-slate-200">Your slide subtitle appears here.</p><span id="slider-preview-button" class="mt-4 inline-flex rounded-full bg-sky-500 px-4 py-2 text-xs font-bold">Explore now</span></div>
            </div>
        </div>
    </section>
    <section class="space-y-3"><div class="flex items-center justify-between"><h2 class="text-lg font-extrabold text-slate-900">Slides ({{ $sliders->count() }})</h2><span class="text-xs text-slate-500">Grouped by placement · ordered by priority</span></div>
        @forelse ($sliders as $slide)
        <article draggable="true" data-slide-id="{{ $slide->id }}" data-slide-group="{{ $slide->key_name }}" class="slider-sort-item cursor-grab rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex flex-wrap items-center gap-3">@if ($slide->image_path)<img src="{{ $slide->image_path }}" alt="" class="h-16 w-28 rounded-lg border object-cover">@endif<div class="min-w-0 flex-1"><h3 class="font-bold text-slate-900"><span class="mr-2 text-slate-400" aria-hidden="true">⠿</span>{{ $slide->title }}</h3><p class="text-xs text-slate-500">{{ str_replace('_', ' ', $slide->key_name) }} · Order {{ $slide->sort_order }} · {{ $slide->is_active ? 'Active' : 'Paused' }}</p><p class="mt-1 text-xs text-slate-500">{{ $slide->starts_at?->format('d M Y H:i') ?? 'Immediately' }} → {{ $slide->ends_at?->format('d M Y H:i') ?? 'No expiry' }}</p></div>
            <details class="w-full border-t border-slate-100 pt-3"><summary class="cursor-pointer text-sm font-bold text-sky-700">Edit slide settings</summary><form method="POST" action="{{ route('plugins.slider_builder.update', $slide) }}" enctype="multipart/form-data" class="mt-3 grid gap-3 md:grid-cols-2">@csrf @method('PUT')
                <label class="text-xs font-semibold">Placement<select name="key_name" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@foreach (['hero'=>'Homepage Hero','banner_slider'=>'Promotional Banner','course_promotion'=>'Course Promotion','announcement'=>'Announcement'] as $key=>$label)<option value="{{ $key }}" @selected($slide->key_name === $key)>{{ $label }}</option>@endforeach</select></label>
                <label class="text-xs font-semibold">Order<input name="sort_order" type="number" min="0" max="65535" value="{{ $slide->sort_order }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold md:col-span-2">Headline<input name="title" required maxlength="180" value="{{ $slide->title }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold md:col-span-2">Subtitle<textarea name="subtitle" maxlength="500" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ $slide->subtitle }}</textarea></label>
                <label class="text-xs font-semibold">Replace desktop image<input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-lg border p-2 text-sm"></label>
                <label class="text-xs font-semibold">Replace mobile image<input type="file" name="mobile_image" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-lg border p-2 text-sm"></label>
                <label class="text-xs font-semibold">Desktop image path<input name="image_path" value="{{ $slide->image_path }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold">Mobile image path<input name="mobile_image_path" value="{{ $slide->mobile_image_path }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold">Button label<input name="button_label" maxlength="60" value="{{ $slide->button_label }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold">CTA URL<input name="cta_url" value="{{ $slide->cta_url }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold">Alignment<select name="text_alignment" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@foreach (['left','center','right'] as $v)<option value="{{ $v }}" @selected($slide->text_alignment === $v)>{{ ucfirst($v) }}</option>@endforeach</select></label>
                <label class="text-xs font-semibold">Transition<select name="transition" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@foreach (['fade','slide'] as $v)<option value="{{ $v }}" @selected($slide->transition === $v)>{{ ucfirst($v) }}</option>@endforeach</select></label>
                <label class="text-xs font-semibold">Overlay opacity<input name="overlay_opacity" type="number" min="0" max="90" value="{{ $slide->overlay_opacity }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold">Duration (ms)<input name="duration_ms" type="number" min="2000" max="15000" value="{{ $slide->duration_ms }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold">Publish from<input name="starts_at" type="datetime-local" value="{{ $slide->starts_at?->format('Y-m-d\\TH:i') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold">Unpublish at<input name="ends_at" type="datetime-local" value="{{ $slide->ends_at?->format('Y-m-d\\TH:i') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2 flex flex-wrap items-center gap-5 text-sm"><label><input type="checkbox" name="is_active" value="1" @checked($slide->is_active)> Active</label><label><input type="checkbox" name="open_new_tab" value="1" @checked($slide->open_new_tab)> Open CTA in new tab</label></div>
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white">Save changes</button>
            </form></details>
            <form method="POST" action="{{ route('plugins.slider_builder.destroy', $slide) }}" onsubmit="return confirm('Move this slide to Recycle Bin?')">@csrf @method('DELETE')<button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700">Move to Recycle Bin</button></form>
        </div></article>
        @empty<div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">No slides created yet.</div>@endforelse
    </section>
</div>
<script>
(() => {
    const form = document.getElementById('slider-create-form');
    const preview = document.getElementById('slider-live-preview');
    if (form && preview) {
        const title = document.getElementById('slider-preview-title');
        const subtitle = document.getElementById('slider-preview-subtitle');
        const button = document.getElementById('slider-preview-button');
        const sync = () => {
            title.textContent = form.elements.title.value || 'Your headline';
            subtitle.textContent = form.elements.subtitle.value || 'Your slide subtitle appears here.';
            button.textContent = form.elements.button_label.value || 'Explore now';
            preview.style.textAlign = form.elements.text_alignment.value;
            const opacityValue = form.elements.overlay_opacity.value;
            const opacity = Math.max(0, Math.min(90, Number(opacityValue === '' ? 45 : opacityValue))) / 100;
            preview.style.backgroundImage = 'linear-gradient(rgba(2,10,20,' + opacity + '),rgba(2,10,20,' + opacity + '))' + (preview.dataset.image ? ',url("' + preview.dataset.image + '")' : '');
        };
        form.addEventListener('input', sync);
        form.elements.image?.addEventListener('change', () => {
            const file = form.elements.image.files?.[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = () => { preview.dataset.image = String(reader.result); sync(); };
            reader.readAsDataURL(file);
        });
        sync();
    }

    const items = Array.from(document.querySelectorAll('.slider-sort-item'));
    let dragged = null;
    items.forEach((item) => {
        item.addEventListener('dragstart', (event) => {
            dragged = item;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.dataset.slideId || '');
            item.classList.add('opacity-40');
        });
        item.addEventListener('dragend', () => { item.classList.remove('opacity-40'); dragged = null; });
        item.addEventListener('dragover', (event) => {
            if (dragged && dragged !== item && dragged.dataset.slideGroup === item.dataset.slideGroup) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            }
        });
        item.addEventListener('drop', async (event) => {
            event.preventDefault();
            if (!dragged || dragged === item || dragged.dataset.slideGroup !== item.dataset.slideGroup) return;
            item.parentNode.insertBefore(dragged, item);
            const group = item.dataset.slideGroup;
            const ordered = Array.from(document.querySelectorAll('.slider-sort-item')).filter((node) => node.dataset.slideGroup === group);
            const token = document.querySelector('meta[name="csrf-token"]')?.content || form?.querySelector('input[name="_token"]')?.value;
            try {
                const response = await fetch(@json(route('plugins.slider_builder.reorder')), {
                    method: 'PATCH',
                    headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token || ''},
                    body: JSON.stringify({key_name:group,items:ordered.map((node) => ({id:Number(node.dataset.slideId)}))})
                });
                if (!response.ok) throw new Error('Unable to save slide order.');
                const result = await response.json();
                if (!result.success) throw new Error(result.message || 'Unable to save slide order.');
            } catch (error) {
                window.alert(error.message || 'Unable to save slide order. Reloading the current order.');
                window.location.reload();
            }
        });
    });
})();
</script>
@endsection