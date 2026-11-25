<?php

declare(strict_types=1);

namespace Plugins\Modules\PageBuilder\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Plugins\Modules\PageBuilder\Models\BuilderPage;
use Plugins\Modules\PageBuilder\Models\BuilderPageRevision;
use Plugins\Modules\PageBuilder\Services\ReusableBlockLibrary;

class PageBuilderController extends Controller
{
    private const BLOCK_TYPES = ['heading', 'text', 'image', 'button', 'spacer', 'course_grid', 'instructor_grid'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(ReusableBlockLibrary $reusableBlocks): View
    {
        $pages = Schema::hasTable('cms_pages')
            ? BuilderPage::query()->with([
                'revisions' => fn ($query) => $query->orderByDesc('version')->limit(10),
                'revisions.creator',
            ])->latest()->get()
            : collect();

        $reusableBlocks = $reusableBlocks->all();
        return view('page-builder::index', compact('pages', 'reusableBlocks'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePage($request);
        $bannerPath = $request->hasFile('banner') ? $this->storeImage($request->file('banner')) : null;
        $blocks = $this->normalizeBlocks($validated['blocks']);

        $page = DB::transaction(function () use ($validated, $bannerPath, $blocks, $request): BuilderPage {
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

            $this->recordRevision($page, $request->user()?->id);

            return $page;
        }, 3);

        $this->audit->log('page_builder.created', 'builder_page', (string) $page->id, [
            'slug' => $page->slug,
            'block_count' => count($blocks),
            'revision' => 1,
        ]);

        return back()->with('status', "Page '/page/{$page->slug}' saved.");
    }

    public function update(Request $request, BuilderPage $page): RedirectResponse
    {
        $validated = $this->validatePage($request, $page);
        $blocks = $this->normalizeBlocks($validated['blocks']);

        $page = DB::transaction(function () use ($request, $page, $validated, $blocks): BuilderPage {
            $lockedPage = BuilderPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $bannerPath = $request->hasFile('banner')
                ? $this->storeImage($request->file('banner'))
                : $lockedPage->banner_image;

            $lockedPage->update([
                'slug' => strtolower($validated['slug']),
                'title' => $validated['title'],
                'template' => $validated['template'] ?? 'standard',
                'banner_image' => $bannerPath,
                'blocks_json' => $blocks,
                'seo_title' => $validated['seo_title'] ?? $validated['title'],
                'seo_description' => $validated['seo_description'] ?? null,
                'is_published' => $request->boolean('is_published'),
            ]);

            $this->recordRevision($lockedPage, $request->user()?->id);

            return $lockedPage;
        }, 3);

        $this->audit->log('page_builder.updated', 'builder_page', (string) $page->id, [
            'slug' => $page->slug,
            'block_count' => count($blocks),
        ]);

        return back()->with('status', "Page '/page/{$page->slug}' updated.");
    }

    public function restoreRevision(Request $request, BuilderPage $page, BuilderPageRevision $revision): RedirectResponse
    {
        $restoredVersion = DB::transaction(function () use ($request, $page, $revision): int {
            $lockedPage = BuilderPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $targetRevision = BuilderPageRevision::query()
                ->where('builder_page_id', $lockedPage->id)
                ->whereKey($revision->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = $this->validatedRevisionSnapshot($targetRevision->snapshot, $lockedPage);
            $lockedPage->update($attributes);
            $newRevision = $this->recordRevision($lockedPage, $request->user()?->id);

            $this->audit->log('page_builder.revision_restored', 'builder_page', (string) $lockedPage->id, [
                'restored_from_revision' => $targetRevision->version,
                'new_revision' => $newRevision->version,
                'slug' => $lockedPage->slug,
            ]);

            return $newRevision->version;
        }, 3);

        return back()->with('status', "Page revision restored as version {$restoredVersion}.");
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

        $validated = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:160', $uniqueSlug],
            'title' => ['required', 'string', 'max:180'],
            'template' => ['nullable', 'in:standard,landing,fullwidth'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'blocks' => ['required_without:content_body', 'nullable', 'array', 'min:1', 'max:30'],
            'blocks.*.type' => ['required', 'in:' . implode(',', self::BLOCK_TYPES)],
            'blocks.*.content' => ['nullable', 'string', 'max:12000'],
            'blocks.*.url' => ['nullable', 'string', 'max:2048', 'regex:#^(https?://[^\s]+|/(?!/)[^\s]*)$#i'],
            'blocks.*.alt' => ['nullable', 'string', 'max:180'],
            'blocks.*.align' => ['nullable', 'in:left,center,right'],
            'blocks.*.height' => ['nullable', 'integer', 'min:16', 'max:240'],
            'content_body' => ['required_without:blocks', 'nullable', 'string', 'max:12000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(https?://[^\s]+|/(?!/)[^\s]*)$#i'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        // Preserve the original text + CTA form contract for existing integrations and tests.
        if (empty($validated['blocks'])) {
            $legacyBlocks = [[
                'type' => 'text',
                'content' => (string) ($validated['content_body'] ?? ''),
            ]];

            if (trim((string) ($validated['cta_label'] ?? '')) !== '') {
                $legacyBlocks[] = [
                    'type' => 'button',
                    'content' => $validated['cta_label'],
                    'url' => $validated['cta_url'] ?? '/courses',
                ];
            }

            $validated['blocks'] = $legacyBlocks;
        }

        return $validated;
    }

    private function normalizeBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            if (!in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }

            $content = trim((string) ($block['content'] ?? $block['label'] ?? ''));
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
            } elseif (in_array($type, ['course_grid', 'instructor_grid'], true)) {
                $normalized[] = ['type' => $type];
            }
        }

        if ($normalized === []) {
            abort(422, 'Add at least one valid content block before saving this page.');
        }

        return $normalized;
    }

    private function validatedRevisionSnapshot(array $snapshot, BuilderPage $page): array
    {
        $slug = strtolower(trim((string) ($snapshot['slug'] ?? '')));
        $title = trim((string) ($snapshot['title'] ?? ''));
        $template = (string) ($snapshot['template'] ?? 'standard');
        $blocks = $snapshot['blocks_json'] ?? null;
        $isPublished = filter_var($snapshot['is_published'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D', $slug)
            || $title === ''
            || mb_strlen($title) > 180
            || !in_array($template, ['standard', 'landing', 'fullwidth'], true)
            || !is_array($blocks)
            || count($blocks) > 30
            || $isPublished === null) {
            throw ValidationException::withMessages(['revision' => 'This page revision contains invalid or unsupported content and cannot be restored.']);
        }

        if (BuilderPage::withTrashed()->where('slug', $slug)->where('id', '<>', $page->id)->exists()) {
            throw ValidationException::withMessages(['revision' => 'This revision URL is already used by another page. Change that page URL before restoring this revision.']);
        }

        $bannerImage = $snapshot['banner_image'] ?? null;
        if ($bannerImage !== null) {
            $safeBanner = is_string($bannerImage)
                && preg_match('#^/uploads/pages/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)$#iD', $bannerImage) === 1
                && is_file(public_path(ltrim($bannerImage, '/')));
            if (!$safeBanner) {
                throw ValidationException::withMessages(['revision' => 'The cover image from this revision is missing or unsafe.']);
            }
        }

        try {
            $normalizedBlocks = $this->normalizeBlocks($blocks);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['revision' => 'This page revision contains invalid content blocks.']);
        }

        return [
            'slug' => $slug,
            'title' => $title,
            'template' => $template,
            'banner_image' => $bannerImage,
            'blocks_json' => $normalizedBlocks,
            'seo_title' => mb_substr(trim((string) ($snapshot['seo_title'] ?? $title)), 0, 180),
            'seo_description' => mb_substr(trim((string) ($snapshot['seo_description'] ?? '')), 0, 320),
            'is_published' => $isPublished,
        ];
    }

    private function recordRevision(BuilderPage $page, ?int $userId): BuilderPageRevision
    {
        $lastVersion = (int) (BuilderPageRevision::query()
            ->where('builder_page_id', $page->id)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->value('version') ?? 0);

        return BuilderPageRevision::query()->create([
            'builder_page_id' => $page->id,
            'version' => $lastVersion + 1,
            'snapshot' => $this->snapshot($page->fresh()),
            'created_by' => $userId,
        ]);
    }

    private function snapshot(BuilderPage $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'template' => $page->template,
            'banner_image' => $page->banner_image,
            'blocks_json' => $page->blocks_json,
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'is_published' => (bool) $page->is_published,
        ];
    }

    private function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '..') && !str_contains($url, '\\')) {
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
