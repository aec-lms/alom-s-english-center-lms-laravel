@extends('layouts.admin')
@section('title', 'Central Media Library — AEC LMS')
@section('content')
<div class="max-w-7xl space-y-6">
    <header>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">Central Media Library</h1>
                <p class="mt-1 text-sm text-slate-500">Organize, search, reuse and manage website images and documents. Private files stay protected; deleted assets can be restored.</p>
            </div>
            <nav class="flex gap-2 text-sm font-bold">
                <a href="{{ route('plugins.media.index') }}" class="rounded-lg border px-3 py-2 {{ !$trash ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-600' }}">Library</a>
                <a href="{{ route('plugins.media.index', ['view' => 'trash']) }}" class="rounded-lg border px-3 py-2 {{ $trash ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-600' }}">Recycle Bin</a>
            </nav>
        </div>
    </header>
    @if (session('status')) <div role="status" class="rounded-lg bg-emerald-50 text-emerald-800 p-3 text-sm">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div role="alert" class="rounded-lg bg-rose-50 text-rose-800 p-3 text-sm">{{ $errors->first() }}</div> @endif

    @unless($trash)
        <form method="POST" action="{{ route('plugins.media.store') }}" enctype="multipart/form-data" class="bg-white border rounded-2xl p-5 grid md:grid-cols-4 gap-3">
            @csrf
            <label class="md:col-span-2 text-xs font-semibold text-slate-600">Choose file
                <input type="file" name="file" required accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" class="mt-1 w-full border rounded-lg p-2 text-sm">
            </label>
            <label class="text-xs font-semibold text-slate-600">Collection / Folder
                <input name="collection" value="{{ old('collection', 'general') }}" placeholder="courses, posts, ebooks" class="mt-1 w-full border rounded-lg p-2 text-sm">
            </label>
            <label class="text-xs font-semibold text-slate-600">Image alt text
                <input name="alt_text" placeholder="Describe the image" class="mt-1 w-full border rounded-lg p-2 text-sm">
            </label>
            <p class="md:col-span-4 text-[11px] text-slate-500">Maximum file size: 50 MB. Raster images are public; documents and archives are stored privately.</p>
            <button class="md:col-span-4 justify-self-end rounded-lg bg-sky-700 text-white px-5 py-2 font-bold">Upload to Media Library</button>
        </form>
    @endunless

    <form method="GET" action="{{ route('plugins.media.index') }}" class="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-3">
        @if($trash)<input type="hidden" name="view" value="trash">@endif
        <input name="q" value="{{ request('q') }}" placeholder="Search filename..." class="min-w-[180px] flex-1 border rounded-lg px-3 py-2 text-sm">
        <select name="type" class="border rounded-lg px-3 py-2 text-sm">
            <option value="">All file types</option>
            <option value="image" @selected(request('type') === 'image')>Images</option>
            <option value="application" @selected(request('type') === 'application')>Documents / PDF</option>
            <option value="text" @selected(request('type') === 'text')>Text / CSV</option>
        </select>
        <select name="collection" class="border rounded-lg px-3 py-2 text-sm">
            <option value="">All collections</option>
            @foreach($collections as $collection)<option value="{{ $collection }}" @selected(request('collection') === $collection)>{{ $collection }}</option>@endforeach
        </select>
        <button class="rounded-lg bg-slate-900 text-white px-4 py-2 text-sm font-semibold">Filter</button>
        <a href="{{ route('plugins.media.index', $trash ? ['view' => 'trash'] : []) }}" class="rounded-lg border px-4 py-2 text-sm font-semibold text-slate-600">Clear</a>
    </form>

    <div class="flex items-center justify-between text-xs text-slate-500">
        <span>{{ $trash ? 'Deleted media assets' : 'Library assets' }}</span>
        <span>{{ $assets->total() }} item(s)</span>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
        @forelse ($assets as $asset)
            <article class="bg-white border rounded-xl overflow-hidden">
                <div class="h-36 bg-slate-50 flex items-center justify-center">
                    @if (str_starts_with($asset->mime_type, 'image/') && $asset->disk === 'public')
                        <img src="{{ IlluminateSupportFacadesStorage::disk('public')->url($asset->path) }}" alt="{{ $asset->alt_text ?: $asset->original_name }}" loading="lazy" class="w-full h-full object-contain">
                    @else
                        <span class="text-3xl font-black text-slate-400">{{ strtoupper(pathinfo($asset->original_name, PATHINFO_EXTENSION)) }}</span>
                    @endif
                </div>
                <div class="p-3 space-y-2">
                    <div class="font-semibold text-xs truncate" title="{{ $asset->original_name }}">{{ $asset->original_name }}</div>
                    <div class="text-[10px] text-slate-500">{{ $asset->collection }} · {{ number_format($asset->size_bytes / 1024, 1) }} KB</div>
                    <div class="text-[10px] text-slate-400">{{ $asset->mime_type }}</div>
                    @if($trash)
                        <div class="flex flex-wrap gap-2 border-t pt-2">
                            <form method="POST" action="{{ route('plugins.media.restore', $asset->id) }}">@csrf<button class="text-xs text-emerald-700 font-bold">Restore</button></form>
                            <form method="POST" action="{{ route('plugins.media.permanent', $asset->id) }}" onsubmit="return confirm('Permanently delete this file? This cannot be undone.')">@csrf @method('DELETE')<button class="text-xs text-rose-700 font-bold">Delete forever</button></form>
                        </div>
                    @else
                        @if($asset->disk === 'public')
                            <button type="button" data-copy-url="{{ IlluminateSupportFacadesStorage::disk('public')->url($asset->path) }}" class="copy-media-url text-xs text-sky-700 font-bold">Copy URL</button>
                        @else
                            <a href="{{ route('plugins.media.download', $asset) }}" class="text-xs text-sky-700 font-bold">Download private file</a>
                        @endif
                        <form method="POST" action="{{ route('plugins.media.update', $asset) }}" class="space-y-1 border-t pt-2">
                            @csrf @method('PATCH')
                            <label class="block text-[10px] font-semibold text-slate-500">Collection<input name="collection" value="{{ $asset->collection }}" maxlength="80" required class="mt-1 w-full rounded border px-2 py-1 text-xs"></label>
                            <label class="block text-[10px] font-semibold text-slate-500">Alt text<input name="alt_text" value="{{ $asset->alt_text }}" maxlength="255" class="mt-1 w-full rounded border px-2 py-1 text-xs"></label>
                            <button class="text-xs text-blue-700 font-bold">Save details</button>
                        </form>
                        <form method="POST" action="{{ route('plugins.media.destroy', $asset) }}" onsubmit="return confirm('Move this media file to the recycle bin?')" class="border-t pt-2">@csrf @method('DELETE')<button class="text-xs text-rose-700 font-bold">Move to recycle bin</button></form>
                    @endif
                </div>
            </article>
        @empty
            <p class="text-sm text-slate-500">{{ $trash ? 'Recycle Bin is empty.' : 'No media files found.' }}</p>
        @endforelse
    </div>
    <div>{{ $assets->links() }}</div>
</div>
<script>
document.querySelectorAll('.copy-media-url').forEach(button => {
    button.addEventListener('click', async () => {
        const value = button.dataset.copyUrl || '';
        try {
            await navigator.clipboard.writeText(value);
            button.textContent = 'Copied!';
        } catch {
            window.prompt('Copy this media URL:', value);
        }
    });
});
</script>
@endsection
