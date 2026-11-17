@extends('layouts.admin')
@section('title', 'Products, Digital Carousel & E-Books — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Products, Digital Product Carousel &amp; E-Books Catalog</h1>
            <p class="text-xs text-slate-500 mt-0.5">Upload PDF files &amp; PDF thumbnails, set prices, feature items in the homepage Digital Product Carousel, or manage printed study kits.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('public.products') }}" target="_blank" class="px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-700">Preview Products &rarr;</a>
            <a href="{{ route('public.ebooks') }}" target="_blank" class="px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-700">Preview E-Books &rarr;</a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Add Product or Digital E-Book (with PDF &amp; PDF Thumbnail)</h2>
        <form method="POST" action="{{ route('plugins.storefront.store') }}" enctype="multipart/form-data" class="grid sm:grid-cols-3 gap-3 text-xs">
            @csrf
            <select name="type" required class="rounded-lg border border-slate-300 px-3 py-2 bg-white font-semibold">
                <option value="ebook">Digital E-Book / PDF Guide</option>
                <option value="product">Printed Product / Digital Kit</option>
            </select>
            <input name="title" required placeholder="Title *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
            <input name="category" required value="IELTS Writing" placeholder="Category (e.g. IELTS Writing, Printed Books) *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="badge" placeholder="Badge (e.g. Bestseller, ShortNote 2.0)" class="rounded-lg border border-slate-300 px-3 py-2">
            <div class="grid grid-cols-2 gap-2">
                <input name="price" type="number" step="0.01" min="0" value="0" required placeholder="Price ৳" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="original_price" type="number" step="0.01" min="0" value="0" placeholder="Old Price ৳" class="rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block font-semibold text-slate-600 mb-1">Upload Cover Thumbnail</label>
                <input type="file" name="thumbnail" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
            </div>
            <div>
                <label class="block font-semibold text-slate-600 mb-1">Upload PDF Thumbnail Preview</label>
                <input type="file" name="pdf_thumbnail" accept="image/*" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
            </div>
            <div>
                <label class="block font-semibold text-slate-600 mb-1">Upload PDF Document (.pdf)</label>
                <input type="file" name="pdf_file" accept="application/pdf,.pdf" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
            </div>
            <input name="thumbnail_url" placeholder="Or Cover Thumbnail URL" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="download_url" placeholder="Or External PDF Download URL" class="rounded-lg border border-slate-300 px-3 py-2">
            <div class="grid grid-cols-2 gap-2">
                <input name="pages" type="number" min="1" value="64" placeholder="Pages" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="stock_count" type="number" min="0" value="50" placeholder="Stock" class="rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <input name="summary" placeholder="Short description / summary" class="sm:col-span-3 rounded-lg border border-slate-300 px-3 py-2">
            <div class="sm:col-span-3 flex flex-wrap items-center justify-between gap-3 pt-1">
                <div class="flex flex-wrap items-center gap-4">
                    <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_free" value="1"> Free Edition (৳0)</label>
                    <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_active" value="1" checked> Active on Storefront</label>
                    <label class="inline-flex items-center gap-2 font-semibold text-[#0284C7]"><input type="checkbox" name="is_carousel_featured" value="1" checked> Feature in Digital Product Carousel</label>
                </div>
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Publish Item</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Catalog Items ({{ $items->count() }})</h2>
        <div class="space-y-3">
            @forelse ($items as $item)
                <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            @if ($item->pdf_thumbnail_path ?: $item->thumbnail_path)
                                <img src="{{ $item->pdf_thumbnail_path ?: $item->thumbnail_path }}" alt="{{ $item->title }}" class="w-16 h-16 rounded-lg object-cover border">
                            @else
                                <div class="w-16 h-16 rounded-lg bg-slate-100 flex items-center justify-center font-bold text-slate-400 uppercase">{{ $item->type }}</div>
                            @endif
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="px-2 py-0.5 rounded bg-blue-50 text-[#0284C7] font-bold uppercase text-[10px]">{{ $item->type }}</span>
                                    <span class="font-bold text-sm text-slate-900">{{ $item->title }}</span>
                                    @if ($item->is_carousel_featured ?? true)
                                        <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-700 font-bold text-[10px]">Carousel Featured</span>
                                    @endif
                                </div>
                                <div class="text-slate-500 mt-0.5">
                                    {{ $item->category }} &middot; {{ $item->is_free ? 'Free' : '৳' . number_format($item->price) }} &middot; {{ $item->is_active ? 'Published' : 'Hidden' }}
                                    @if ($item->download_url) &middot; <span class="text-emerald-700 font-bold">PDF Ready</span> @endif
                                </div>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('plugins.storefront.destroy', $item) }}" onsubmit="return confirm('Delete this item?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold hover:bg-rose-50">Delete</button>
                        </form>
                    </div>

                    <details class="pt-2 border-t border-slate-100">
                        <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit Item, PDF &amp; Thumbnails</summary>
                        <form method="POST" action="{{ route('plugins.storefront.update', $item) }}" enctype="multipart/form-data" class="mt-3 grid sm:grid-cols-3 gap-3">
                            @csrf
                            @method('PUT')
                            <select name="type" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <option value="ebook" @selected($item->type === 'ebook')>E-Book</option>
                                <option value="product" @selected($item->type === 'product')>Product / Kit</option>
                            </select>
                            <input name="title" required value="{{ $item->title }}" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
                            <input name="category" required value="{{ $item->category }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="badge" value="{{ $item->badge }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <div class="grid grid-cols-2 gap-2">
                                <input name="price" type="number" step="0.01" min="0" value="{{ $item->price }}" class="rounded-lg border border-slate-300 px-3 py-2">
                                <input name="original_price" type="number" step="0.01" min="0" value="{{ $item->original_price }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            </div>
                            <input type="file" name="thumbnail" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                            <input type="file" name="pdf_thumbnail" accept="image/*" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                            <input type="file" name="pdf_file" accept="application/pdf,.pdf" class="rounded-lg border border-slate-300 px-2 py-1.5 bg-slate-50">
                            <input name="thumbnail_url" value="{{ $item->thumbnail_path }}" placeholder="Thumbnail URL" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="download_url" value="{{ $item->download_url }}" placeholder="PDF / Asset URL" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="summary" value="{{ $item->summary }}" placeholder="Summary" class="rounded-lg border border-slate-300 px-3 py-2">
                            <div class="sm:col-span-3 flex flex-wrap items-center justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-4">
                                    <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_free" value="1" @checked($item->is_free)> Free</label>
                                    <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_active" value="1" @checked($item->is_active)> Active</label>
                                    <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" name="is_carousel_featured" value="1" @checked($item->is_carousel_featured ?? true)> Carousel Featured</label>
                                </div>
                                <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Save Changes</button>
                            </div>
                        </form>
                    </details>
                </div>
            @empty
                <p class="text-xs text-slate-400">No custom products or e-books added yet. Add items above to customize the public Storefront &amp; Digital Product Carousel.</p>
            @endforelse
        </div>
    </div>
</div>
@includeIf('media-library::partials.editor-tools')

@endsection
