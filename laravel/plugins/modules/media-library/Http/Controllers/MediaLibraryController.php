<?php

declare(strict_types=1);

namespace Plugins\Modules\MediaLibrary\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Plugins\Modules\MediaLibrary\Models\MediaAsset;

class MediaLibraryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $assets = MediaAsset::query()
            ->when($request->filled('q'), fn ($q) => $q->where('original_name', 'like', '%' . $request->string('q') . '%'))
            ->when($request->filled('type'), fn ($q) => $q->where('mime_type', 'like', $request->string('type') . '/%'))
            ->latest()->paginate(36)->withQueryString();

        return view('media-library::index', compact('assets'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip'],
            'collection' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9 _-]+$/'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        // Only raster images are public. Office files, PDFs, archives and text files stay private.
        $isImage = str_starts_with((string) $file->getMimeType(), 'image/');
        $disk = $isImage ? 'public' : 'local';
        $folder = $isImage ? 'media-library' : 'media-library/private';
        $path = $file->store($folder, $disk);

        $asset = MediaAsset::query()->create([
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'collection' => trim((string) ($validated['collection'] ?? 'general')) ?: 'general',
            'alt_text' => $validated['alt_text'] ?? null,
        ]);

        $this->audit->log('media.asset_uploaded', 'media_asset', (string) $asset->id, [
            'mime_type' => $asset->mime_type, 'size_bytes' => $asset->size_bytes,
        ]);

        return back()->with('status', 'Media uploaded to the central library.');
    }

    public function download(MediaAsset $asset)
    {
        abort_unless(Storage::disk($asset->disk)->exists($asset->path), 404);

        return Storage::disk($asset->disk)->download($asset->path, $asset->original_name);
    }

    public function destroy(MediaAsset $asset): RedirectResponse
    {
        $reference = $asset->disk === 'public'
            ? Storage::disk('public')->url($asset->path)
            : 'private:' . $asset->path;

        $columns = [
            'cms_posts' => ['thumbnail_path', 'pdf_thumbnail', 'pdf_path'],
            'courses' => ['thumbnail', 'pdf_thumbnail', 'pdf_path'],
            'storefront_items' => ['thumbnail_path', 'pdf_thumbnail_path', 'download_url'],
        ];

        foreach ($columns as $table => $fields) {
            if (!Schema::hasTable($table)) continue;
            foreach ($fields as $field) {
                if (Schema::hasColumn($table, $field) && DB::table($table)->where($field, $reference)->exists()) {
                    return back()->withErrors(['media' => 'This file is currently used by published content. Replace or unlink it before deleting.']);
                }
            }
        }

        Storage::disk($asset->disk)->delete($asset->path);
        $id = (string) $asset->id;
        $asset->delete();
        $this->audit->log('media.asset_deleted', 'media_asset', $id, []);

        return back()->with('status', 'Media asset deleted.');
    }
}