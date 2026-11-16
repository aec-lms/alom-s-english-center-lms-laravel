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
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $pages = Schema::hasTable('cms_pages') ? BuilderPage::query()->latest()->get() : collect();
        return view('page-builder::index', compact('pages'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:160', 'unique:cms_pages,slug'],
            'title' => ['required', 'string', 'max:180'],
            'template' => ['nullable', 'in:standard,landing,fullwidth'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'content_body' => ['required', 'string'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(https?://[^\\s]+|/(?!/)[^\\s]*)$#i'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $bannerPath = $request->hasFile('banner') ? $this->storeImage($request->file('banner')) : null;

        $blocks = [['type' => 'rich_text', 'content' => $validated['content_body']]];
        if (!empty($validated['cta_label'])) {
            $blocks[] = [
                'type' => 'cta',
                'label' => $validated['cta_label'],
                'url' => $validated['cta_url'] ?? '/courses',
            ];
        }

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

        $this->audit->log('page_builder.created', 'builder_page', (string) $page->id, ['slug' => $page->slug]);

        return back()->with('status', "Page '/page/{$page->slug}' published.");
    }

    public function update(Request $request, BuilderPage $page): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:160', 'unique:cms_pages,slug,' . $page->id],
            'title' => ['required', 'string', 'max:180'],
            'template' => ['nullable', 'in:standard,landing,fullwidth'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'content_body' => ['required', 'string'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(https?://[^\\s]+|/(?!/)[^\\s]*)$#i'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $bannerPath = $request->hasFile('banner') ? $this->storeImage($request->file('banner')) : $page->banner_image;

        $blocks = [['type' => 'rich_text', 'content' => $validated['content_body']]];
        if (!empty($validated['cta_label'])) {
            $blocks[] = [
                'type' => 'cta',
                'label' => $validated['cta_label'],
                'url' => $validated['cta_url'] ?? '/courses',
            ];
        }

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

        $this->audit->log('page_builder.updated', 'builder_page', (string) $page->id, ['slug' => $page->slug]);

        return back()->with('status', "Page '/page/{$page->slug}' updated.");
    }

    public function destroy(BuilderPage $page): RedirectResponse
    {
        $slug = $page->slug;
        $id = (string) $page->id;
        $page->delete();
        $this->audit->log('page_builder.deleted', 'builder_page', $id, ['slug' => $slug]);

        return back()->with('status', "Page '/page/{$slug}' deleted.");
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
