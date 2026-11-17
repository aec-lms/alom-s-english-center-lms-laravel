<?php

declare(strict_types=1);

namespace Plugins\Modules\StorefrontCatalog\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\StorefrontCatalog\Models\StorefrontItem;

class StorefrontController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $items = Schema::hasTable('storefront_items')
            ? StorefrontItem::query()->orderBy('type')->latest('id')->get()
            : collect();

        return view('storefront-catalog::index', compact('items'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:product,ebook'],
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:100'],
            'badge' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'pages' => ['nullable', 'integer', 'min:1'],
            'stock_count' => ['nullable', 'integer', 'min:0'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'pdf_thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'pdf_thumbnail_url' => ['nullable', 'string', 'max:255'],
            'pdf_file' => ['nullable', 'file', 'mimes:pdf', 'max:25600'],
            'download_url' => ['nullable', 'string', 'max:255'],
            'is_free' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_carousel_featured' => ['nullable', 'boolean'],
        ]);

        $thumb = $request->hasFile('thumbnail')
            ? $this->storeAsset($request->file('thumbnail'), 'storefront')
            : ($validated['thumbnail_url'] ?? null);

        $pdfThumb = $request->hasFile('pdf_thumbnail')
            ? $this->storeAsset($request->file('pdf_thumbnail'), 'storefront/pdf-thumbs')
            : ($validated['pdf_thumbnail_url'] ?? null);

        $isFree = $request->boolean('is_free') || (float) $validated['price'] <= 0;
        if ($validated['type'] === 'ebook' && !$isFree && !empty($validated['download_url']) && !$request->hasFile('pdf_file')) {
            return back()->withErrors(['download_url' => 'Paid e-books must use a privately uploaded PDF, not a public URL.'])->withInput();
        }
        $downloadUrl = $request->hasFile('pdf_file')
            ? $this->storePrivatePdf($request->file('pdf_file'))
            : ($validated['download_url'] ?? null);
        if ($validated['type'] === 'ebook' && !$isFree && !str_starts_with((string) $downloadUrl, 'private:')) {
            return back()->withErrors(['download_url' => 'Paid e-books require a privately uploaded PDF.'])->withInput();
        }

        $isFree = $request->boolean('is_free') || (float) $validated['price'] <= 0;

        $item = StorefrontItem::query()->create([
            'type' => $validated['type'],
            'slug' => Str::slug($validated['title']) . '-' . Str::lower(Str::random(4)),
            'title' => $validated['title'],
            'category' => $validated['category'],
            'badge' => ($validated['badge'] ?? null) ?: ($validated['type'] === 'ebook' ? ($isFree ? 'Free' : 'Premium') : 'Featured'),
            'price' => $isFree ? 0 : (float) $validated['price'],
            'original_price' => (float) ($validated['original_price'] ?? $validated['price']),
            'is_free' => $isFree,
            'pages' => (int) ($validated['pages'] ?? 64),
            'stock_count' => (int) ($validated['stock_count'] ?? 50),
            'summary' => $validated['summary'] ?? null,
            'thumbnail_path' => $thumb,
            'pdf_thumbnail_path' => $pdfThumb,
            'download_url' => $downloadUrl,
            'is_active' => $request->boolean('is_active', true),
            'is_carousel_featured' => $request->boolean('is_carousel_featured', true),
        ]);

        $this->audit->log('storefront.created', 'storefront_item', (string) $item->id, ['type' => $item->type, 'title' => $item->title]);

        return back()->with('status', ucfirst($item->type) . " '{$item->title}' created.");
    }

    public function update(Request $request, StorefrontItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:product,ebook'],
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:100'],
            'badge' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'pages' => ['nullable', 'integer', 'min:1'],
            'stock_count' => ['nullable', 'integer', 'min:0'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255'],
            'pdf_thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'pdf_thumbnail_url' => ['nullable', 'string', 'max:255'],
            'pdf_file' => ['nullable', 'file', 'mimes:pdf', 'max:25600'],
            'download_url' => ['nullable', 'string', 'max:255'],
            'is_free' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_carousel_featured' => ['nullable', 'boolean'],
        ]);

        $previousThumbnail = $item->thumbnail_path;
        $previousPdfThumbnail = $item->pdf_thumbnail_path;
        $thumb = $item->thumbnail_path;
        if ($request->hasFile('thumbnail')) {
            $thumb = $this->storeAsset($request->file('thumbnail'), 'storefront');
        } elseif (!empty($validated['thumbnail_url'])) {
            $thumb = $validated['thumbnail_url'];
        }

        $pdfThumb = $item->pdf_thumbnail_path;
        if ($request->hasFile('pdf_thumbnail')) {
            $pdfThumb = $this->storeAsset($request->file('pdf_thumbnail'), 'storefront/pdf-thumbs');
        } elseif (!empty($validated['pdf_thumbnail_url'])) {
            $pdfThumb = $validated['pdf_thumbnail_url'];
        }

        $downloadUrl = $item->download_url;
        $previousDownloadUrl = $item->download_url;
        $isFree = $request->boolean('is_free') || (float) $validated['price'] <= 0;
        if ($validated['type'] === 'ebook' && !$isFree && !empty($validated['download_url']) && !$request->hasFile('pdf_file')) {
            return back()->withErrors(['download_url' => 'Paid e-books must use a privately uploaded PDF, not a public URL.'])->withInput();
        }
        if ($request->hasFile('pdf_file')) {
            $downloadUrl = $this->storePrivatePdf($request->file('pdf_file'));
        } elseif (!empty($validated['download_url'])) {
            $downloadUrl = $validated['download_url'];
        }
        if ($validated['type'] === 'ebook' && !$isFree && !str_starts_with((string) $downloadUrl, 'private:')) {
            return back()->withErrors(['download_url' => 'Paid e-books require a privately uploaded PDF.'])->withInput();
        }

        $isFree = $request->boolean('is_free') || (float) $validated['price'] <= 0;

        $item->update([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'category' => $validated['category'],
            'badge' => $validated['badge'] ?? $item->badge,
            'price' => $isFree ? 0 : (float) $validated['price'],
            'original_price' => (float) ($validated['original_price'] ?? $validated['price']),
            'is_free' => $isFree,
            'pages' => (int) ($validated['pages'] ?? $item->pages),
            'stock_count' => (int) ($validated['stock_count'] ?? $item->stock_count),
            'summary' => $validated['summary'] ?? $item->summary,
            'thumbnail_path' => $thumb,
            'pdf_thumbnail_path' => $pdfThumb,
            'download_url' => $downloadUrl,
            'is_active' => $request->boolean('is_active'),
            'is_carousel_featured' => $request->boolean('is_carousel_featured'),
        ]);

        foreach ([[$previousThumbnail, $thumb], [$previousPdfThumbnail, $pdfThumb]] as [$oldPath, $newPath]) {
            if ($oldPath && $oldPath !== $newPath) $this->deleteUnusedPublicUpload($oldPath, (int) $item->id);
        }
        if ($previousDownloadUrl && $previousDownloadUrl !== $downloadUrl && str_starts_with($previousDownloadUrl, 'private:')) {
            Storage::disk('local')->delete(substr($previousDownloadUrl, 8));
        }

        $this->audit->log('storefront.updated', 'storefront_item', (string) $item->id, ['title' => $item->title]);

        return back()->with('status', "'{$item->title}' and its PDF/thumbnails updated.");
    }

    public function destroy(StorefrontItem $item): RedirectResponse
    {
        $this->deleteUnusedPublicUpload($item->thumbnail_path, (int) $item->id);
        $this->deleteUnusedPublicUpload($item->pdf_thumbnail_path, (int) $item->id);
        if ($item->download_url && str_starts_with((string) $item->download_url, 'private:')) {
            Storage::disk('local')->delete(substr((string) $item->download_url, 8));
        }
        $title = $item->title;
        $id = (string) $item->id;
        $item->delete();

        $this->audit->log('storefront.deleted', 'storefront_item', $id, ['title' => $title]);

        return back()->with('status', "'{$title}' removed.");
    }

    public function downloadEbook(Request $request, string $slug)
    {
        $item = StorefrontItem::query()
            ->where('slug', $slug)
            ->where('type', 'ebook')
            ->where('is_active', true)
            ->firstOrFail();

        $downloadUrl = (string) $item->download_url;
        abort_if($downloadUrl === '', 404, 'The e-book file is unavailable.');
        $isFree = (bool) $item->is_free || (float) $item->price <= 0;

        if (!$isFree) {
            abort_unless(
                Schema::hasTable('storefront_item_access')
                    && DB::table('storefront_item_access')
                        ->where('storefront_item_id', $item->id)
                        ->where('user_id', $request->user()->id)
                        ->exists(),
                403,
                'Verified payment access is required to download this e-book.'
            );
        }

        if (str_starts_with($downloadUrl, 'private:')) {
            $path = substr($downloadUrl, 8);
            abort_unless($path !== '' && Storage::disk('local')->exists($path), 404, 'The protected e-book file is unavailable.');
            return Storage::disk('local')->download($path, Str::slug($item->title) . '.pdf');
        }

        if ($isFree && filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($downloadUrl, PHP_URL_SCHEME));
            abort_unless(in_array($scheme, ['https', 'http'], true), 403, 'Unsupported e-book URL scheme.');
            return redirect()->away($downloadUrl);
        }

        if ($isFree && str_starts_with($downloadUrl, '/uploads/')) {
            $root = realpath(public_path('uploads'));
            $file = realpath(public_path(ltrim($downloadUrl, '/')));
            abort_unless($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file), 404);
            return response()->download($file, Str::slug($item->title) . '.pdf');
        }

        abort(403, 'This e-book does not have an allowed downloadable file configured.');
    }

    private function deleteUnusedPublicUpload(?string $path, ?int $ignoreStorefrontItemId = null): void
    {
        if (!$path || !str_starts_with($path, '/uploads/')) return;
        $references = [
            'cms_posts' => ['thumbnail_path', 'pdf_thumbnail', 'pdf_path'],
            'courses' => ['thumbnail', 'pdf_thumbnail', 'pdf_path'],
            'storefront_items' => ['thumbnail_path', 'pdf_thumbnail_path', 'download_url'],
        ];
        foreach ($references as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) continue;
                $query = DB::table($table)->where($column, $path);
                if ($table === 'storefront_items' && $ignoreStorefrontItemId) $query->where('id', '!=', $ignoreStorefrontItemId);
                if ($query->exists()) return;
            }
        }
        $absolute = public_path(ltrim($path, '/'));
        $root = realpath(public_path('uploads'));
        $file = realpath($absolute);
        if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
    }

    private function storePrivatePdf(UploadedFile $file): string
    {
        $path = Storage::disk('local')->putFile('digital-products', $file);
        if (!$path) throw new \RuntimeException('Unable to store the protected PDF file.');
        return 'private:' . $path;
    }

    private function storeAsset(UploadedFile $file, string $folder): string
    {
        $dir = public_path('uploads/' . $folder);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return '/uploads/' . $folder . '/' . $filename;
    }
}
