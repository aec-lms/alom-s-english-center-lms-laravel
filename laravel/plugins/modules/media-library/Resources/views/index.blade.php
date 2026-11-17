@extends('layouts.admin')
@section('title', 'Central Media Library — AEC LMS')
@section('content')
<div class="max-w-7xl space-y-6">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Central Media Library</h1>
        <p class="text-sm text-slate-500">Upload, search, reuse and manage images and documents across Courses, Posts and E-books. PDFs are stored privately.</p>
    </div>
    @if (session('status')) <div class="rounded-lg bg-emerald-50 text-emerald-800 p-3 text-sm">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="rounded-lg bg-rose-50 text-rose-800 p-3 text-sm">{{ $errors->first() }}</div> @endif
    <form method="POST" action="{{ route('plugins.media.store') }}" enctype="multipart/form-data" class="bg-white border rounded-2xl p-5 grid md:grid-cols-4 gap-3">
        @csrf
        <input type="file" name="file" required accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" class="md:col-span-2 border rounded-lg p-2 text-sm">
        <input name="collection" value="general" placeholder="Collection (courses, posts, ebooks)" class="border rounded-lg p-2 text-sm">
        <input name="alt_text" placeholder="Image alt text / description" class="border rounded-lg p-2 text-sm">
        <button class="md:col-span-4 justify-self-end rounded-lg bg-sky-700 text-white px-5 py-2 font-bold">Upload to Media Library</button>
    </form>
    <form method="GET" class="flex flex-wrap gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Search filename..." class="border rounded-lg px-3 py-2 text-sm">
        <select name="type" class="border rounded-lg px-3 py-2 text-sm"><option value="">All file types</option><option value="image" @selected(request('type') === 'image')>Images</option><option value="application" @selected(request('type') === 'application')>Documents / PDF</option></select>
        <button class="border rounded-lg px-4 py-2 text-sm font-semibold">Search</button>
    </form>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
        @forelse ($assets as $asset)
            <article class="bg-white border rounded-xl overflow-hidden">
                <div class="h-36 bg-slate-50 flex items-center justify-center">
                    @if (str_starts_with($asset->mime_type, 'image/') && $asset->mime_type !== 'image/svg+xml')
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($asset->path) }}" alt="{{ $asset->alt_text ?: $asset->original_name }}" class="w-full h-full object-contain">
                    @else
                        <span class="text-3xl font-black text-slate-400">{{ strtoupper(pathinfo($asset->original_name, PATHINFO_EXTENSION)) }}</span>
                    @endif
                </div>
                <div class="p-3 space-y-2">
                    <div class="font-semibold text-xs truncate" title="{{ $asset->original_name }}">{{ $asset->original_name }}</div>
                    <div class="text-[10px] text-slate-500">{{ $asset->collection }} · {{ number_format($asset->size_bytes / 1024, 1) }} KB</div>
                    <div class="flex gap-2">
                        @if (str_starts_with($asset->mime_type, 'image/'))
                            <button type="button" onclick="navigator.clipboard.writeText('{{ \Illuminate\Support\Facades\Storage::disk('public')->url($asset->path) }}')" class="text-xs text-sky-700 font-bold">Copy URL</button>
                        @else
                            <a href="{{ route('plugins.media.download', $asset) }}" class="text-xs text-sky-700 font-bold">Download</a>
                        @endif
                        <form method="POST" action="{{ route('plugins.media.destroy', $asset) }}" onsubmit="return confirm('Delete this media file?')">@csrf @method('DELETE')<button class="text-xs text-rose-700 font-bold">Delete</button></form>
                    </div>
                </div>
            </article>
        @empty
            <p class="text-sm text-slate-500">No media files found.</p>
        @endforelse
    </div>
    <div>{{ $assets->links() }}</div>
</div>
@endsection