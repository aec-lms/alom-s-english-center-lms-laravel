<?php

declare(strict_types=1);

namespace Plugins\Modules\PageBuilder\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\PageBuilder\Models\BuilderPage;

class PageBuilderController extends Controller
{
    private const BLOCK_TYPES = ['heading', 'text', 'image', 'button', 'spacer'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $pages = Schema::hasTable('cms_pages') ? BuilderPage::query()->latest()->get() : collect();

        return view('page-builder::index', compact('pages'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePage($request);
        $bannerPath = $request->hasFile('banner') ? $this->storeImage($request->file('banner')) : null;
        $blocks = $this->normalizeBlocks($validated['blocks']);

        $page = BuilderPage::query()->create([
            'slug' => strtolower($validated['slug']),
            'title' => $validated['title'],
            'template' => $validated['template'] ?? 'standard',
            'banner_image' => $bannerPath,
            'blocks_json' => $blocks,
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'is_published' => $request->boolean('is_published', true),
        ]);

        $this->audit->log('page_builder.created', 'builder_page', (string) $page->id, [
            'slug' => $page->slug,
            'block_count' => count($blocks),
        ]);

        return back()->with('status', "Page '/page/{$page->slug}' saved.");
    }

    public function update(Request $request, BuilderPage $page): RedirectResponse
    {
        $validated = $this->validatePage($request, $page);
        $bannerPath = $request->hasFile('banner') ? $this->storeImage($request->file('banner')) : $page->banner_image;
        $blocks = $this->normalizeBlocks($validated['blocks']);

        $page->update([
            'slug' => strtolower($validated['slug']),
            'title' => $validated['title'],
            'template' => $validated['template'] ?? 'standard',
            'banner_image' => $bannerPath,
            'blocks_json' => $blocks,
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'is_published' => $request->boolean('is_published'),
        ]);

        $this->audit->log('page_builder.updated', 'builder_page', (string) $page->id, [
            'slug' => $page->slug,
            'block_count' => count($blocks),
        ]);

        return back()->with('status', "Page '/page/{$page->slug}' updated.");
    }

    public function destroy(BuilderPage $page): RedirectResponse
    {
        $slug = $page->slug;
        $id = (string) $page->id;
        $page->delete();
        $this->audit->log('page_builder.trashed', 'builder_page', $id, ['slug' => $slug]);

        return back()->with('status', "Page '/page/{$slug}' moved to the Admin Recycle Bin.");
    }

    private function validatePage(Request $request, ?BuilderPage $page = null): array
    {
        $uniqueSlug = 'unique:cms_pages,slug' . ($page ? ',' . $page->id : '');

        return $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:160', $uniqueSlug],
            'title' => ['required', 'string', 'max:180'],
            'template' => ['nullable', 'in:standard,landing,fullwidth'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'blocks' => ['required', 'array', 'min:1', 'max:30'],
            'blocks.*.type' => ['required', 'in:' . implode(',', self::BLOCK_TYPES)],
            'blocks.*.content' => ['nullable', 'string', 'max:12000'],
            'blocks.*.url' => ['nullable', 'string', 'max:2048', 'regex:#^(https?://[^\\s]+|/(?!/)[^\\s]*)$#i'],
            'blocks.*.alt' => ['nullable', 'string', 'max:180'],
            'blocks.*.align' => ['nullable', 'in:left,center,right'],
            'blocks.*.height' => ['nullable', 'integer', 'min:16', 'max:240'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'is_published' => ['nullable', 'boolean'],
        ]);
    }

    private function normalizeBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            if (!in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }

            $content = trim((string) ($block['content'] ?? ''));
            $url = trim((string) ($block['url'] ?? ''));
            $safeUrl = $this->isSafeUrl($url) ? $url : '';

            if (in_array($type, ['heading', 'text'], true) && $content !== '') {
                $normalized[] = ['type' => $type, 'content' => $content, 'align' => $block['align'] ?? 'left'];
            } elseif ($type === 'image' && $safeUrl !== '') {
                $normalized[] = [
                    'type' => 'image',
                    'url' => $safeUrl,
                    'alt' => trim((string) ($block['alt'] ?? '')),
                ];
            } elseif ($type === 'button' && $content !== '' && $safeUrl !== '') {
                $normalized[] = [
                    'type' => 'button',
                    'label' => $content,
                    'url' => $safeUrl,
                    'align' => $block['align'] ?? 'left',
                ];
            } elseif ($type === 'spacer') {
                $normalized[] = ['type' => 'spacer', 'height' => max(16, min(240, (int) ($block['height'] ?? 48)))];
            }
        }

        if ($normalized === []) {
            abort(422, 'Add at least one valid content block before saving this page.');
        }

        return $normalized;
    }

    private function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '..')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function storeImage(UploadedFile $file): string
    {
        $dir = public_path('uploads/pages');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = Str::uuid()->toString() . '.' . $file->extension();
        $file->move($dir, $filename);

        return '/uploads/pages/' . $filename;
    }
}
