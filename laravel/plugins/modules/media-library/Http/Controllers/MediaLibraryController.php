<?php

declare(strict_types=1);

namespace Plugins\Modules\MediaLibrary\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Core\Security\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Plugins\Modules\MediaLibrary\Models\MediaAsset;

class MediaLibraryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly BranchAccess $branches) {}

    public function index(Request $request): View
    {
        $trash = $request->query('view') === 'trash';
        $query = $trash ? MediaAsset::onlyTrashed() : MediaAsset::query();
        $this->branches->scope($query, $request->user());
        $assets = $query
            ->when($request->filled('q'), fn ($q) => $q->where('original_name', 'like', '%' . $request->string('q') . '%'))
            ->when($request->filled('type'), fn ($q) => $q->where('mime_type', 'like', $request->string('type') . '/%'))
            ->when($request->filled('collection'), fn ($q) => $q->where('collection', $request->string('collection')))
            ->latest()->paginate(36)->withQueryString();
        $collectionQuery = MediaAsset::withTrashed();
        $this->branches->scope($collectionQuery, $request->user());
        $collections = $collectionQuery->select('collection')->distinct()->orderBy('collection')->pluck('collection');

        return view('media-library::index', compact('assets', 'collections', 'trash'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip'],
            'collection' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9 _-]+$/'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $isImage = str_starts_with((string) $file->getMimeType(), 'image/');
        $disk = $isImage ? 'public' : 'local';
        $folder = $isImage ? 'media-library' : 'media-library/private';
        $path = $file->store($folder, $disk);

        $asset = MediaAsset::query()->create([
            'uploaded_by' => $request->user()?->id,
            'branch_id' => $this->branches->assignedBranchId($request->user()),
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

    public function update(Request $request, MediaAsset $asset): RedirectResponse
    {
        $this->branches->assertRecord($request->user(), $asset);
        $validated = $request->validate([
            'collection' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9 _-]+$/'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);
        $asset->update($validated);
        $this->audit->log('media.asset_metadata_updated', 'media_asset', (string) $asset->id, [
            'collection' => $asset->collection, 'alt_text_updated' => true,
        ]);

        return back()->with('status', 'Media details updated.');
    }

    public function download(Request $request, MediaAsset $asset)
    {
        $this->branches->assertRecord($request->user(), $asset);
        abort_unless(Storage::disk($asset->disk)->exists($asset->path), 404);
        return Storage::disk($asset->disk)->download($asset->path, $asset->original_name);
    }

    public function destroy(Request $request, MediaAsset $asset): RedirectResponse
    {
        $this->branches->assertRecord($request->user(), $asset);
        if ($this->isReferenced($asset)) {
            return back()->withErrors(['media' => 'This file is currently used by content. Unlink it before moving it to the recycle bin.']);
        }
        $asset->delete();
        $this->audit->log('media.asset_trashed', 'media_asset', (string) $asset->id, []);
        return back()->with('status', 'Media asset moved to the recycle bin.');
    }

    public function restore(Request $request, string $asset): RedirectResponse
    {
        $record = MediaAsset::withTrashed()->findOrFail($asset);
        $this->branches->assertRecord($request->user(), $record);
        abort_unless($record->trashed(), 404);
        $record->restore();
        $this->audit->log('media.asset_restored', 'media_asset', (string) $record->id, []);
        return back()->with('status', 'Media asset restored.');
    }

    public function permanentlyDelete(Request $request, string $asset): RedirectResponse
    {
        $record = MediaAsset::withTrashed()->findOrFail($asset);
        $this->branches->assertRecord($request->user(), $record);
        abort_unless($record->trashed(), 404);
        if ($this->isReferenced($record)) {
            return back()->withErrors(['media' => 'This file is still referenced by content and cannot be permanently deleted.']);
        }
        Storage::disk($record->disk)->delete($record->path);
        $id = (string) $record->id;
        $record->forceDelete();
        $this->audit->log('media.asset_permanently_deleted', 'media_asset', $id, []);
        return back()->with('status', 'Media file permanently deleted.');
    }

    private function isReferenced(MediaAsset $asset): bool
    {
        $values = $asset->disk === 'public'
            ? [$asset->path, Storage::disk('public')->url($asset->path)]
            : [$asset->path, 'private:' . $asset->path];
        $columns = [
            'cms_posts' => ['thumbnail_path', 'pdf_thumbnail', 'pdf_path'],
            'courses' => ['thumbnail', 'pdf_thumbnail', 'pdf_path'],
            'storefront_items' => ['thumbnail_path', 'pdf_thumbnail_path', 'download_url'],
        ];
        foreach ($columns as $table => $fields) {
            if (!Schema::hasTable($table)) continue;
            foreach ($fields as $field) {
                if (Schema::hasColumn($table, $field) && DB::table($table)->whereIn($field, $values)->exists()) return true;
            }
        }
        return false;
    }
}
