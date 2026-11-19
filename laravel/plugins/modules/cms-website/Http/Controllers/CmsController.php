<?php

declare(strict_types=1);

namespace Plugins\Modules\CmsWebsite\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\CmsWebsite\Models\CmsCategory;
use Plugins\Modules\CmsWebsite\Models\CmsPost;

class CmsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $categories = Schema::hasTable('cms_categories') ? CmsCategory::query()->orderBy('name')->get() : collect();
        $posts = Schema::hasTable('cms_posts') ? CmsPost::query()->latest()->get() : collect();
        $trashedPosts = Schema::hasTable('cms_posts') ? CmsPost::onlyTrashed()->latest('deleted_at')->get() : collect();
        $postRevisions = Schema::hasTable('cms_post_revisions') ? DB::table('cms_post_revisions')->orderByDesc('created_at')->get()->groupBy('post_id') : collect();
        $inquiries = Schema::hasTable('contact_inquiries') ? DB::table('contact_inquiries')->orderByDesc('id')->limit(50)->get() : collect();
        $homepage = $this->loadHomepageConfig();

        return view('cms-website::index', compact('categories', 'posts', 'trashedPosts', 'postRevisions', 'inquiries', 'homepage'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'alpha_dash', 'max:150', 'unique:cms_categories,slug'],
            'description' => ['nullable', 'string', 'max:320'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slug = !empty($validated['slug'])
            ? strtolower($validated['slug'])
            : Str::slug($validated['name']) . '-' . Str::lower(Str::random(3));

        $cat = CmsCategory::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? 'sky',
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->log('cms.category_created', 'cms_category', (string) $cat->id, ['slug' => $cat->slug]);

        return back()->with('status', "CMS Category '{$cat->name}' created.");
    }

    public function updateCategory(Request $request, CmsCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:150', 'unique:cms_categories,slug,' . $category->id],
            'description' => ['nullable', 'string', 'max:320'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update([
            'name' => $validated['name'],
            'slug' => strtolower($validated['slug']),
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? $category->color,
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log('cms.category_updated', 'cms_category', (string) $category->id, ['slug' => $category->slug]);

        return back()->with('status', "CMS Category '{$category->name}' updated.");
    }

    public function destroyCategory(CmsCategory $category): RedirectResponse
    {
        $name = $category->name;
        $id = (string) $category->id;
        $category->delete();
        $this->audit->log('cms.category_deleted', 'cms_category', $id, ['name' => $name]);

        return back()->with('status', "CMS Category '{$name}' deleted.");
    }

    public function storePost(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'alpha_dash', 'max:180', 'unique:cms_posts,slug'],
            'category_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:120'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void { if (!$this->isSafeMediaReference($value)) $fail('The thumbnail URL must be a safe HTTPS URL or local path.'); }],
            'pdf_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'pdf_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void { if (!$this->isSafeMediaReference($value)) $fail('The PDF URL must be a safe HTTPS URL or local path.'); }],
            'pdf_thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'pdf_thumbnail_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void { if (!$this->isSafeMediaReference($value)) $fail('The PDF thumbnail URL must be a safe HTTPS URL or local path.'); }],
            'is_published' => ['nullable', 'boolean'],
            'publish_at' => ['nullable', 'date'],
        ]);

        $categoryName = trim((string) ($validated['category'] ?? 'General')) ?: 'General';
        $categoryId = $validated['category_id'] ?? null;
        if ($categoryId && Schema::hasTable('cms_categories')) {
            $catModel = CmsCategory::query()->find($categoryId);
            if ($catModel) {
                $categoryName = $catModel->name;
            }
        }

        $slug = !empty($validated['slug'])
            ? strtolower($validated['slug'])
            : Str::slug($validated['title']) . '-' . Str::lower(Str::random(4));

        $thumbPath = $request->hasFile('thumbnail')
            ? $this->storeUploadedFile($request->file('thumbnail'), 'posts')
            : ($validated['thumbnail_url'] ?? null);

        $pdfPath = $request->hasFile('pdf_file')
            ? $this->storeUploadedFile($request->file('pdf_file'), 'posts/pdfs')
            : ($validated['pdf_url'] ?? null);

        $pdfThumbPath = $request->hasFile('pdf_thumbnail')
            ? $this->storeUploadedFile($request->file('pdf_thumbnail'), 'posts/pdf-thumbs')
            : ($validated['pdf_thumbnail_url'] ?? null);

        $post = CmsPost::query()->create([
            'slug' => $slug,
            'title' => $validated['title'],
            'category_id' => $categoryId,
            'category' => $categoryName,
            'author' => $validated['author'] ?? "Alom's English Center",
            'excerpt' => $validated['excerpt'] ?? Str::limit(strip_tags($validated['content']), 160),
            'content' => $validated['content'],
            'thumbnail_path' => $thumbPath,
            'pdf_path' => $pdfPath,
            'pdf_thumbnail' => $pdfThumbPath,
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'created_by' => $request->user()->getAuthIdentifier(),
            'author_user_id' => $request->user()->getAuthIdentifier(),
            'is_published' => false,
            'publish_at' => $validated['publish_at'] ?? null,
            'editorial_status' => 'draft',
        ]);

        $this->storePostRevision($post, $request);
        $this->audit->log('cms.post_created', 'cms_post', (string) $post->id, ['slug' => $post->slug]);

        return back()->with('status', "Post '{$post->title}' created.");
    }

    public function updatePost(Request $request, CmsPost $post): RedirectResponse
    {
        abort_if($post->is_published, 422, 'Published content is protected. Create a new draft revision and submit it for approval before replacing live content.');
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'alpha_dash', 'max:180', 'unique:cms_posts,slug,' . $post->id],
            'category_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:120'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'thumbnail_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void { if (!$this->isSafeMediaReference($value)) $fail('The thumbnail URL must be a safe HTTPS URL or local path.'); }],
            'pdf_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'pdf_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void { if (!$this->isSafeMediaReference($value)) $fail('The PDF URL must be a safe HTTPS URL or local path.'); }],
            'pdf_thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'pdf_thumbnail_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void { if (!$this->isSafeMediaReference($value)) $fail('The PDF thumbnail URL must be a safe HTTPS URL or local path.'); }],
            'is_published' => ['nullable', 'boolean'],
            'publish_at' => ['nullable', 'date'],
        ]);

        $categoryName = trim((string) ($validated['category'] ?? $post->category ?? 'General')) ?: 'General';
        $categoryId = $validated['category_id'] ?? $post->category_id;
        if ($categoryId && Schema::hasTable('cms_categories')) {
            $catModel = CmsCategory::query()->find($categoryId);
            if ($catModel) {
                $categoryName = $catModel->name;
            }
        }

        $this->storePostRevision($post, $request);
        $oldAssets = [$post->thumbnail_path, $post->pdf_path, $post->pdf_thumbnail];

        $thumbPath = $post->thumbnail_path;
        if ($request->hasFile('thumbnail')) {
            $thumbPath = $this->storeUploadedFile($request->file('thumbnail'), 'posts');
        } elseif (!empty($validated['thumbnail_url'])) {
            $thumbPath = $validated['thumbnail_url'];
        }

        $pdfPath = $post->pdf_path;
        if ($request->hasFile('pdf_file')) {
            $pdfPath = $this->storeUploadedFile($request->file('pdf_file'), 'posts/pdfs');
        } elseif (!empty($validated['pdf_url'])) {
            $pdfPath = $validated['pdf_url'];
        }

        $pdfThumbPath = $post->pdf_thumbnail;
        if ($request->hasFile('pdf_thumbnail')) {
            $pdfThumbPath = $this->storeUploadedFile($request->file('pdf_thumbnail'), 'posts/pdf-thumbs');
        } elseif (!empty($validated['pdf_thumbnail_url'])) {
            $pdfThumbPath = $validated['pdf_thumbnail_url'];
        }

        $post->update([
            'slug' => strtolower($validated['slug']),
            'title' => $validated['title'],
            'category_id' => $categoryId,
            'category' => $categoryName,
            'author' => $validated['author'] ?? $post->author,
            'excerpt' => $validated['excerpt'] ?? $post->excerpt,
            'content' => $validated['content'],
            'thumbnail_path' => $thumbPath,
            'pdf_path' => $pdfPath,
            'pdf_thumbnail' => $pdfThumbPath,
            'seo_title' => $validated['seo_title'] ?? ($post->seo_title ?: $validated['title']),
            'seo_description' => $validated['seo_description'] ?? $post->seo_description,
            'is_published' => (bool) $post->is_published,
            'publish_at' => $validated['publish_at'] ?? null,
            // Any content edit invalidates a previous approval. It must be reviewed again.
            'editorial_status' => 'draft',
            'submitted_at' => null,
            'submitted_by' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'review_note' => null,
        ]);

        foreach ([[$oldAssets[0], $thumbPath], [$oldAssets[1], $pdfPath], [$oldAssets[2], $pdfThumbPath]] as [$oldPath, $newPath]) {
            if ($oldPath && $oldPath !== $newPath) $this->deleteUnusedUpload($oldPath, (int) $post->id);
        }

        $this->audit->log('cms.post_updated', 'cms_post', (string) $post->id, ['slug' => $post->slug]);

        return back()->with('status', "Post '{$post->title}' updated.");
    }


    public function editorialDashboard(Request $request): View
    {
        $user = $request->user();
        $query = CmsPost::query()->with(['primaryAuthor', 'contributors', 'assignedEditor', 'assignedReviewer'])->latest('updated_at');

        if ($user->hasPermission('cms.posts.review')
            && !$user->hasPermission('cms.posts.edit_own')
            && !$user->hasPermission('cms.posts.edit_all')
            && !$user->hasPermission('cms.authors.assign')) {
            $query->where('editorial_status', 'in_review')
                ->where(fn ($posts) => $posts->whereNull('assigned_reviewer_id')->orWhere('assigned_reviewer_id', $user->id));
        } elseif (!$user->hasPermission('cms.posts.edit_all') && !$user->hasPermission('cms.authors.assign')) {
            $query->where(function ($posts) use ($user): void {
                $posts->where('created_by', $user->id)
                    ->orWhere('author_user_id', $user->id)
                    ->orWhere('assigned_editor_id', $user->id)
                    ->orWhereHas('contributors', fn ($contributors) => $contributors->where('users.id', $user->id));
            });
        }

        $posts = $query->paginate(20)->withQueryString();
        $categories = Schema::hasTable('cms_categories')
            ? CmsCategory::query()->where('is_active', true)->orderBy('name')->get()
            : collect();
        $canCreate = $user->hasPermission('cms.posts.create');
        $canEditAll = $user->hasPermission('cms.posts.edit_all');
        $canEditOwn = $user->hasPermission('cms.posts.edit_own');
        $canAssign = $user->hasPermission('cms.authors.assign');
        $canSubmit = $user->hasPermission('cms.posts.submit');
        $canReview = $user->hasPermission('cms.posts.review');
        $canPublish = $user->hasPermission('cms.posts.publish');
        $availableAuthors = $canAssign
            ? User::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereHas('roles', fn ($roles) => $roles->whereIn('slug', ['cms_contributor', 'cms_editor']))->orWhereHas('roles', fn ($roles) => $roles->where('slug', 'admin')))
                ->orderBy('name')->get(['id', 'name', 'email'])
            : collect();
        $availableEditors = $canAssign
            ? User::query()->where('is_active', true)->whereHas('roles', fn ($roles) => $roles->where('slug', 'cms_editor'))->orderBy('name')->get(['id', 'name', 'email'])
            : collect();
        $availableReviewers = $canAssign
            ? User::query()->where('is_active', true)->whereHas('roles', fn ($roles) => $roles->whereIn('slug', ['cms_reviewer', 'cms_editor']))->orderBy('name')->get(['id', 'name', 'email'])
            : collect();

        return view('cms-website::editorial', compact(
            'posts', 'categories', 'canCreate', 'canEditAll', 'canEditOwn', 'canAssign', 'canSubmit', 'canReview', 'canPublish',
            'availableAuthors', 'availableEditors', 'availableReviewers'
        ));
    }

    public function storeEditorialPost(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('cms.posts.create'), 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'alpha_dash', 'max:180', 'unique:cms_posts,slug'],
            'category_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:1000000'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'co_authors' => ['nullable', 'array', 'max:10'],
            'co_authors.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'author_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_editor_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $this->validateEditorialAssignments($user, $validated);

        $authorId = $user->id;
        if ($user->hasPermission('cms.authors.assign') && !empty($validated['author_user_id'])) {
            $authorId = (int) $validated['author_user_id'];
            $this->assertCmsAuthor($authorId);
        }
        $author = User::query()->findOrFail($authorId);
        $category = !empty($validated['category_id']) ? CmsCategory::query()->find($validated['category_id']) : null;

        $post = CmsPost::query()->create([
            'slug' => !empty($validated['slug']) ? strtolower($validated['slug']) : Str::slug($validated['title']) . '-' . Str::lower(Str::random(5)),
            'title' => $validated['title'],
            'category_id' => $category?->id,
            'category' => $category?->name ?? 'General',
            'author' => $author->name,
            'author_user_id' => $authorId,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'assigned_editor_id' => $user->hasPermission('cms.authors.assign') ? ($validated['assigned_editor_id'] ?? null) : null,
            'assigned_reviewer_id' => $user->hasPermission('cms.authors.assign') ? ($validated['assigned_reviewer_id'] ?? null) : null,
            'excerpt' => $validated['excerpt'] ?? Str::limit(strip_tags($validated['content']), 160),
            'content' => $validated['content'],
            'seo_title' => $validated['seo_title'] ?? $validated['title'],
            'seo_description' => $validated['seo_description'] ?? null,
            'is_published' => false,
            'editorial_status' => 'draft',
        ]);

        if ($user->hasPermission('cms.authors.assign') && !empty($validated['co_authors'])) {
            $this->syncCmsCoAuthors($post, $validated['co_authors'], $authorId);
        }
        $this->storePostRevision($post, $request);
        $this->audit->log('cms.editorial_post_created', 'cms_post', (string) $post->id, ['author_user_id' => $authorId]);
        return redirect()->route('plugins.cms.editorial')->with('status', "Draft '{$post->title}' created.");
    }

    public function updateEditorialPost(Request $request, CmsPost $post): RedirectResponse
    {
        $this->assertCanEditPost($post);
        abort_if($post->is_published, 422, 'Published content must be revised through a reviewed editorial draft.');
        $user = $request->user();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'alpha_dash', 'max:180', 'unique:cms_posts,slug,' . $post->id],
            'category_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:1000000'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'co_authors' => ['nullable', 'array', 'max:10'],
            'co_authors.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'author_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_editor_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $this->validateEditorialAssignments($user, $validated, $post);

        $authorId = (int) $post->author_user_id;
        if ($user->hasPermission('cms.authors.assign') && !empty($validated['author_user_id'])) {
            $authorId = (int) $validated['author_user_id'];
            $this->assertCmsAuthor($authorId);
        }
        $author = User::query()->find($authorId);
        $category = !empty($validated['category_id']) ? CmsCategory::query()->find($validated['category_id']) : null;

        DB::transaction(function () use ($request, $post, $validated, $user, $authorId, $author, $category): void {
            $this->storePostRevision($post, $request);
            $post->forceFill([
                'title' => $validated['title'],
                'slug' => strtolower($validated['slug']),
                'category_id' => $category?->id,
                'category' => $category?->name ?? 'General',
                'author_user_id' => $authorId ?: null,
                'author' => $author?->name ?? $post->author,
                'excerpt' => $validated['excerpt'] ?? Str::limit(strip_tags($validated['content']), 160),
                'content' => $validated['content'],
                'seo_title' => $validated['seo_title'] ?? ($post->seo_title ?: $validated['title']),
                'seo_description' => $validated['seo_description'] ?? $post->seo_description,
                'updated_by' => $user->id,
                'editorial_status' => 'draft',
                'submitted_at' => null,
                'submitted_by' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ])->save();

            if ($user->hasPermission('cms.authors.assign')) {
                $post->forceFill([
                    'assigned_editor_id' => $validated['assigned_editor_id'] ?? null,
                    'assigned_reviewer_id' => $validated['assigned_reviewer_id'] ?? null,
                ])->save();
                $this->syncCmsCoAuthors($post, $validated['co_authors'] ?? [], $authorId);
            }
        });

        $this->audit->log('cms.editorial_post_updated', 'cms_post', (string) $post->id, ['updated_by' => $user->id]);
        return redirect()->route('plugins.cms.editorial')->with('status', "Draft '{$post->title}' updated.");
    }


    public function updatePostAssignments(Request $request, CmsPost $post): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('cms.authors.assign'), 403);
        abort_if($post->is_published, 422, 'Published post assignments are locked.');
        $validated = $request->validate([
            'author_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_editor_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'co_authors' => ['nullable', 'array', 'max:10'],
            'co_authors.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);
        $this->validateEditorialAssignments($user, $validated, $post);

        DB::transaction(function () use ($post, $validated, $user): void {
            $locked = CmsPost::query()->lockForUpdate()->findOrFail($post->id);
            abort_if($locked->is_published, 422);
            $authorId = (int) ($validated['author_user_id'] ?? $locked->author_user_id);
            if ($authorId) $this->assertCmsAuthor($authorId);
            $author = $authorId ? User::query()->find($authorId) : null;
            $locked->forceFill([
                'author_user_id' => $authorId ?: null,
                'author' => $author?->name ?? $locked->author,
                'assigned_editor_id' => $validated['assigned_editor_id'] ?? null,
                'assigned_reviewer_id' => $validated['assigned_reviewer_id'] ?? null,
                'updated_by' => $user->id,
            ])->save();
            $this->syncCmsCoAuthors($locked, $validated['co_authors'] ?? [], $authorId ?: null);
            $this->audit->log('cms.post_assignments_updated', 'cms_post', (string) $locked->id, [
                'author_user_id' => $authorId,
                'assigned_editor_id' => $locked->assigned_editor_id,
                'assigned_reviewer_id' => $locked->assigned_reviewer_id,
            ]);
        }, 3);

        return back()->with('status', "Contributor assignments updated for '{$post->title}'.");
    }

    public function contributorsIndex(): View
    {
        $contributors = User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($roles) => $roles->where('slug', 'admin'))
            ->with(['roles' => fn ($roles) => $roles->whereIn('slug', ['cms_contributor', 'cms_editor', 'cms_reviewer', 'cms_author_manager'])])
            ->orderBy('name')->paginate(30);
        return view('cms-website::contributors', compact('contributors'));
    }

    public function updateContributorAccess(Request $request, User $user): RedirectResponse
    {
        abort_if($user->hasRole('admin'), 403, 'Administrator access is managed separately.');
        abort_unless($user->is_active, 422, 'Inactive accounts cannot receive CMS access.');
        $validated = $request->validate([
            'roles' => ['nullable', 'array', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', 'in:cms_contributor,cms_editor,cms_reviewer,cms_author_manager'],
        ]);
        $newRoles = $validated['roles'] ?? [];
        if (!in_array('cms_contributor', $newRoles, true) && !in_array('cms_editor', $newRoles, true)) {
            $hasAuthoredPosts = CmsPost::query()->where(fn ($q) => $q->where('author_user_id', $user->id)->orWhere('created_by', $user->id))->exists()
                || (Schema::hasTable('cms_post_authors') && DB::table('cms_post_authors')->where('user_id', $user->id)->exists());
            abort_if($hasAuthoredPosts, 422, 'Reassign this user's authored or co-authored posts before removing Contributor/Editor access.');
        }
        if (!in_array('cms_editor', $newRoles, true)) {
            abort_if(CmsPost::query()->where('assigned_editor_id', $user->id)->exists(), 422, 'Reassign this user's editor assignments before removing Editor access.');
        }
        if (!in_array('cms_reviewer', $newRoles, true) && !in_array('cms_editor', $newRoles, true)) {
            abort_if(CmsPost::query()->where('assigned_reviewer_id', $user->id)->where('editorial_status', 'in_review')->exists(), 422, 'Reassign this user's pending reviews before removing Reviewer access.');
        }

        $roleIds = DB::table('roles')->whereIn('slug', $newRoles)->pluck('id')->all();
        $cmsRoleIds = DB::table('roles')->whereIn('slug', ['cms_contributor', 'cms_editor', 'cms_reviewer', 'cms_author_manager'])->pluck('id')->all();
        DB::transaction(function () use ($user, $roleIds, $cmsRoleIds): void {
            $user->roles()->detach($cmsRoleIds);
            if ($roleIds !== []) $user->roles()->syncWithoutDetaching($roleIds);
        });

        $this->audit->log('cms.contributor_access_updated', 'user', (string) $user->id, ['cms_roles' => $validated['roles'] ?? []]);
        return back()->with('status', "CMS access updated for {$user->name}.");
    }

    private function validateEditorialAssignments(User $actor, array $validated, ?CmsPost $post = null): void
    {
        if (!$actor->hasPermission('cms.authors.assign')) {
            abort_if(!empty($validated['co_authors']) || !empty($validated['author_user_id']) || !empty($validated['assigned_editor_id']) || !empty($validated['assigned_reviewer_id']), 403);
            return;
        }

        foreach (($validated['co_authors'] ?? []) as $coAuthorId) $this->assertCmsAuthor((int) $coAuthorId);
        if (!empty($validated['author_user_id'])) $this->assertCmsAuthor((int) $validated['author_user_id']);
        if (!empty($validated['assigned_editor_id'])) {
            abort_unless(User::query()->whereKey($validated['assigned_editor_id'])->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'cms_editor'))->exists(), 422, 'The selected editor is not an active CMS Editor.');
        }
        if (!empty($validated['assigned_reviewer_id'])) {
            abort_unless(User::query()->whereKey($validated['assigned_reviewer_id'])->where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('slug', ['cms_reviewer', 'cms_editor']))->exists(), 422, 'The selected reviewer is not an active CMS Reviewer.');
        }
        if (!empty($validated['assigned_reviewer_id'])) {
            $reviewerId = (int) $validated['assigned_reviewer_id'];
            $primaryAuthorId = (int) ($validated['author_user_id'] ?? $post?->author_user_id ?? $actor->id);
            $creatorId = (int) ($post?->created_by ?? $actor->id);
            $coAuthorIds = array_map('intval', $validated['co_authors'] ?? []);
            abort_if($reviewerId === $primaryAuthorId || $reviewerId === $creatorId || in_array($reviewerId, $coAuthorIds, true), 422, 'The reviewer must be independent from the author, creator and co-authors.');
        }
    }

    private function assertCmsAuthor(int $userId): void
    {
        abort_unless(User::query()->whereKey($userId)->where('is_active', true)
            ->where(fn ($q) => $q->whereHas('roles', fn ($roles) => $roles->whereIn('slug', ['cms_contributor', 'cms_editor']))->orWhereHas('roles', fn ($roles) => $roles->where('slug', 'admin')))
            ->exists(), 422, 'The selected author is not an active CMS contributor or editor.');
    }

    private function syncCmsCoAuthors(CmsPost $post, array $coAuthorIds, ?int $primaryAuthorId): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $coAuthorIds), fn ($id) => $id > 0 && $id !== (int) $primaryAuthorId)));
        foreach ($ids as $id) $this->assertCmsAuthor($id);
        $post->contributors()->sync(collect($ids)->values()->mapWithKeys(fn ($id, $index) => [$id => ['contributor_type' => 'co_author', 'sort_order' => $index + 1]])->all());
    }

    private function assertCanEditPost(CmsPost $post): void
    {
        $user = auth()->user();
        abort_unless($user, 403);
        if ($user->hasPermission('cms.posts.edit_all')) return;
        $ownsPost = (int) $post->author_user_id === (int) $user->id
            || (int) $post->created_by === (int) $user->id
            || (int) $post->assigned_editor_id === (int) $user->id
            || $post->contributors()->where('users.id', $user->id)->exists();
        abort_unless($user->hasPermission('cms.posts.edit_own') && $ownsPost, 403, 'You may only edit posts you own or have been assigned.');
    }

    private function assertCanReviewPost(CmsPost $post): void
    {
        $user = auth()->user();
        abort_unless($user && $user->hasPermission('cms.posts.review'), 403);
        abort_if((int) $post->submitted_by === (int) $user->id
            || (int) $post->created_by === (int) $user->id
            || (int) $post->author_user_id === (int) $user->id
            || $post->contributors()->where('users.id', $user->id)->exists(), 403, 'Authors cannot review their own posts.');
        if ($post->assigned_reviewer_id) {
            abort_unless((int) $post->assigned_reviewer_id === (int) $user->id, 403, 'This post is assigned to another reviewer.');
        }
    }

    public function submitPostForReview(CmsPost $post): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('cms.posts.submit'), 403);
        $this->assertCanEditPost($post);
        DB::transaction(function () use ($post): void {
            $locked = CmsPost::query()->lockForUpdate()->findOrFail($post->id);
            abort_if($locked->is_published, 422);
            abort_unless(in_array($locked->editorial_status ?? 'draft', ['draft', 'changes_requested'], true), 422);
            $locked->forceFill(['editorial_status' => 'in_review', 'submitted_at' => now(), 'submitted_by' => auth()->id(), 'reviewed_at' => null, 'reviewed_by' => null, 'review_note' => null])->save();
            $this->audit->log('cms.post_submitted_for_review', 'cms_post', (string) $locked->id, []);
        }, 3);
        return back()->with('status', 'Post submitted for editorial review.');
    }

    public function approvePost(CmsPost $post): RedirectResponse
    {
        $this->assertCanReviewPost($post);
        DB::transaction(function () use ($post): void {
            $locked = CmsPost::query()->lockForUpdate()->findOrFail($post->id);
            abort_unless(($locked->editorial_status ?? 'draft') === 'in_review' && !$locked->is_published, 422);
            abort_if((int) $locked->submitted_by === (int) auth()->id() || (int) $locked->created_by === (int) auth()->id() || (int) $locked->author_user_id === (int) auth()->id(), 403, 'The submitter or author cannot approve their own post.');
            $locked->forceFill(['editorial_status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => auth()->id(), 'review_note' => null])->save();
            $this->audit->log('cms.post_approved', 'cms_post', (string) $locked->id, []);
        }, 3);
        return back()->with('status', 'Post approved; publish it when ready.');
    }

    public function requestPostChanges(Request $request, CmsPost $post): RedirectResponse
    {
        $this->assertCanReviewPost($post);
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:2000']]);
        DB::transaction(function () use ($post, $data): void {
            $locked = CmsPost::query()->lockForUpdate()->findOrFail($post->id);
            abort_unless(($locked->editorial_status ?? 'draft') === 'in_review' && !$locked->is_published, 422);
            abort_if((int) $locked->submitted_by === (int) auth()->id() || (int) $locked->created_by === (int) auth()->id() || (int) $locked->author_user_id === (int) auth()->id(), 403, 'The submitter or author cannot review their own post.');
            $locked->forceFill(['editorial_status' => 'changes_requested', 'reviewed_at' => now(), 'reviewed_by' => auth()->id(), 'review_note' => $data['review_note']])->save();
            $this->audit->log('cms.post_changes_requested', 'cms_post', (string) $locked->id, ['note_length' => strlen($data['review_note'])]);
        }, 3);
        return back()->with('status', 'Editorial changes requested.');
    }

    public function publishApprovedPost(CmsPost $post): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('cms.posts.publish'), 403);
        DB::transaction(function () use ($post): void {
            $locked = CmsPost::query()->lockForUpdate()->findOrFail($post->id);
            abort_unless(($locked->editorial_status ?? 'draft') === 'approved' && !$locked->is_published, 422);
            $locked->forceFill(['is_published' => true, 'editorial_status' => 'published', 'publish_at' => null])->save();
            $this->audit->log('cms.post_published_after_approval', 'cms_post', (string) $locked->id, []);
        }, 3);
        return back()->with('status', 'Approved post published.');
    }

    public function destroyPost(CmsPost $post): RedirectResponse
    {
        // Soft deletion must retain media so a restored post remains complete.
        $title = $post->title;
        $id = (string) $post->id;
        $post->delete();
        $this->audit->log('cms.post_trashed', 'cms_post', $id, ['title' => $title]);

        return back()->with('status', "Post '{$title}' moved to the Recycle Bin.");
    }

    public function restoreRevision(Request $request, CmsPost $post, int $revisionId): RedirectResponse
    {
        abort_unless(Schema::hasTable('cms_post_revisions'), 404);
        $revision = DB::table('cms_post_revisions')->where('id', $revisionId)->where('post_id', $post->id)->first();
        abort_unless($revision, 404);
        abort_if($post->is_published, 422, 'A live post cannot be overwritten by revision restore. Create a reviewed draft revision first.');
        // Preserve the content being replaced before applying the historical snapshot.
        $this->storePostRevision($post, $request);
        $currentAssets = [$post->thumbnail_path, $post->pdf_path, $post->pdf_thumbnail];
        $post->update([
            'title' => $revision->title, 'slug' => $revision->slug, 'category' => $revision->category,
            'category_id' => $revision->category_id, 'author' => $revision->author, 'excerpt' => $revision->excerpt,
            'content' => $revision->content, 'thumbnail_path' => $revision->thumbnail_path, 'pdf_path' => $revision->pdf_path,
            'pdf_thumbnail' => $revision->pdf_thumbnail, 'seo_title' => $revision->seo_title,
            'seo_description' => $revision->seo_description, 'is_published' => false,
            'publish_at' => $revision->publish_at ?? null, 'editorial_status' => 'draft',
            'submitted_at' => null, 'submitted_by' => null, 'reviewed_at' => null,
            'reviewed_by' => null, 'review_note' => null,
        ]);
        foreach ($currentAssets as $assetPath) {
            if ($assetPath && !in_array($assetPath, [$revision->thumbnail_path, $revision->pdf_path, $revision->pdf_thumbnail], true)) {
                $this->deleteUnusedUpload($assetPath, (int) $post->id);
            }
        }
        $this->audit->log('cms.post_revision_restored', 'cms_post', (string) $post->id, ['revision_id' => $revisionId]);
        return back()->with('status', "Revision restored for '{$post->title}'.");
    }

    private function storePostRevision(CmsPost $post, Request $request): void
    {
        if (!Schema::hasTable('cms_post_revisions')) return;
        DB::table('cms_post_revisions')->insert([
            'post_id' => $post->id, 'user_id' => $request->user()?->getAuthIdentifier(),
            'title' => $post->title, 'slug' => $post->slug, 'category' => $post->category ?? 'General',
            'category_id' => $post->category_id, 'author' => $post->author, 'excerpt' => $post->excerpt,
            'content' => $post->content, 'thumbnail_path' => $post->thumbnail_path, 'pdf_path' => $post->pdf_path,
            'pdf_thumbnail' => $post->pdf_thumbnail, 'seo_title' => $post->seo_title,
            'seo_description' => $post->seo_description, 'is_published' => (bool) $post->is_published,
            'publish_at' => $post->publish_at,
            'created_at' => now(),
        ]);
    }

    public function restorePost(int $postId): RedirectResponse
    {
        $post = CmsPost::withTrashed()->findOrFail($postId);
        abort_unless($post->trashed(), 404);

        $post->restore();
        $this->audit->log('cms.post_restored', 'cms_post', (string) $post->id, ['slug' => $post->slug]);

        return back()->with('status', "Post '{$post->title}' restored.");
    }

    public function forceDeletePost(int $postId): RedirectResponse
    {
        $post = CmsPost::withTrashed()->findOrFail($postId);
        abort_unless($post->trashed(), 404);

        $title = $post->title;
        $id = (string) $post->id;
        $assets = [$post->thumbnail_path, $post->pdf_path, $post->pdf_thumbnail];
        if (Schema::hasTable('cms_post_revisions')) {
            $revisionAssets = DB::table('cms_post_revisions')->where('post_id', $post->id)->get(['thumbnail_path', 'pdf_path', 'pdf_thumbnail']);
            foreach ($revisionAssets as $revisionAsset) {
                array_push($assets, $revisionAsset->thumbnail_path, $revisionAsset->pdf_path, $revisionAsset->pdf_thumbnail);
            }
            DB::table('cms_post_revisions')->where('post_id', $post->id)->delete();
        }

        // Remove the row and its revision history, then clean only truly unreferenced files.
        $post->forceDelete();
        foreach ($assets as $assetPath) {
            $this->deleteUnusedUpload($assetPath);
        }

        $this->audit->log('cms.post_permanently_deleted', 'cms_post', $id, ['title' => $title]);

        return back()->with('status', "Post '{$title}' permanently deleted.");
    }

    public function saveHomepage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'batches_badge' => ['nullable', 'string', 'max:80'],
            'batches_title' => ['required', 'string', 'max:180'],
            'batches_subtitle' => ['required', 'string', 'max:180'],
            'batch_1_title' => ['nullable', 'string', 'max:140'],
            'batch_1_desc' => ['nullable', 'string', 'max:180'],
            'batch_1_url' => ['nullable', 'string', 'max:255'],
            'batch_2_title' => ['nullable', 'string', 'max:140'],
            'batch_2_desc' => ['nullable', 'string', 'max:180'],
            'batch_2_url' => ['nullable', 'string', 'max:255'],
            'stats_badge' => ['nullable', 'string', 'max:80'],
            'stats_title' => ['required', 'string', 'max:180'],
            'stats_subtitle' => ['required', 'string', 'max:180'],
            'stats_mode' => ['nullable', 'in:auto,manual'],
            'stats_students_override' => ['nullable', 'integer', 'min:0'],
            'stats_courses_override' => ['nullable', 'integer', 'min:0'],
            'stats_teachers_override' => ['nullable', 'integer', 'min:0'],
            'stats_lessons_override' => ['nullable', 'integer', 'min:0'],
            'paths_title' => ['required', 'string', 'max:180'],
            'paths_subtitle' => ['required', 'string', 'max:320'],
            'carousel_title' => ['nullable', 'string', 'max:180'],
            'carousel_subtitle' => ['nullable', 'string', 'max:320'],
            'carousel_cta_text' => ['nullable', 'string', 'max:80'],
            'reviews_title' => ['required', 'string', 'max:180'],
            'reviews_subtitle' => ['required', 'string', 'max:320'],
            'app_banner_eyebrow' => ['nullable', 'string', 'max:120'],
            'app_banner_title' => ['nullable', 'string', 'max:180'],
            'app_banner_subtitle' => ['nullable', 'string', 'max:360'],
            'app_ios_url' => ['nullable', 'string', 'max:255'],
            'app_android_url' => ['nullable', 'string', 'max:255'],
            'footer_description' => ['nullable', 'string', 'max:500'],
            'footer_license_no' => ['nullable', 'string', 'max:120'],
            'footer_address' => ['nullable', 'string', 'max:255'],
            'footer_phone' => ['nullable', 'string', 'max:60'],
            'footer_email' => ['nullable', 'email', 'max:150'],
            'footer_youtube_url' => ['nullable', 'string', 'max:255'],
            'footer_facebook_url' => ['nullable', 'string', 'max:255'],
            'paths' => ['nullable', 'array'],
        ]);

        $defaults = $this->defaultHomepageConfig();
        $pathsInput = $request->input('paths', []);
        $learningPaths = $defaults['learning_paths'];
        if (is_array($pathsInput) && !empty($pathsInput)) {
            $learningPaths = [];
            foreach ($defaults['learning_paths'] as $idx => $defPath) {
                $row = $pathsInput[$idx] ?? [];
                $learningPaths[] = [
                    'title' => trim((string) ($row['title'] ?? $defPath['title'])) ?: $defPath['title'],
                    'desc' => trim((string) ($row['desc'] ?? $defPath['desc'])) ?: $defPath['desc'],
                    'url' => trim((string) ($row['url'] ?? $defPath['url'])) ?: $defPath['url'],
                    'bg' => $defPath['bg'],
                    'border' => $defPath['border'],
                    'accent' => $defPath['accent'],
                    'abbr' => trim((string) ($row['abbr'] ?? $defPath['abbr'])) ?: $defPath['abbr'],
                    'enabled' => isset($row['enabled']) ? (bool) $row['enabled'] : true,
                ];
            }
        }

        $payload = [
            'batches_badge' => $validated['batches_badge'] ?? $defaults['batches_badge'],
            'batches_title' => $validated['batches_title'],
            'batches_subtitle' => $validated['batches_subtitle'],
            'batch_1_title' => $validated['batch_1_title'] ?? $defaults['batch_1_title'],
            'batch_1_desc' => $validated['batch_1_desc'] ?? $defaults['batch_1_desc'],
            'batch_1_url' => $validated['batch_1_url'] ?? $defaults['batch_1_url'],
            'batch_2_title' => $validated['batch_2_title'] ?? $defaults['batch_2_title'],
            'batch_2_desc' => $validated['batch_2_desc'] ?? $defaults['batch_2_desc'],
            'batch_2_url' => $validated['batch_2_url'] ?? $defaults['batch_2_url'],
            'stats_badge' => $validated['stats_badge'] ?? $defaults['stats_badge'],
            'stats_title' => $validated['stats_title'],
            'stats_subtitle' => $validated['stats_subtitle'],
            'stats_mode' => $validated['stats_mode'] ?? 'auto',
            'stats_students_override' => (int) ($validated['stats_students_override'] ?? 0),
            'stats_courses_override' => (int) ($validated['stats_courses_override'] ?? 0),
            'stats_teachers_override' => (int) ($validated['stats_teachers_override'] ?? 0),
            'stats_lessons_override' => (int) ($validated['stats_lessons_override'] ?? 0),
            'paths_title' => $validated['paths_title'],
            'paths_subtitle' => $validated['paths_subtitle'],
            'learning_paths' => $learningPaths,
            'carousel_title' => $validated['carousel_title'] ?? $defaults['carousel_title'],
            'carousel_subtitle' => $validated['carousel_subtitle'] ?? $defaults['carousel_subtitle'],
            'carousel_cta_text' => $validated['carousel_cta_text'] ?? $defaults['carousel_cta_text'],
            'reviews_title' => $validated['reviews_title'],
            'reviews_subtitle' => $validated['reviews_subtitle'],
            'app_banner_eyebrow' => $validated['app_banner_eyebrow'] ?? $defaults['app_banner_eyebrow'],
            'app_banner_title' => $validated['app_banner_title'] ?? $defaults['app_banner_title'],
            'app_banner_subtitle' => $validated['app_banner_subtitle'] ?? $defaults['app_banner_subtitle'],
            'app_ios_url' => $validated['app_ios_url'] ?? $defaults['app_ios_url'],
            'app_android_url' => $validated['app_android_url'] ?? $defaults['app_android_url'],
            'footer_description' => $validated['footer_description'] ?? $defaults['footer_description'],
            'footer_license_no' => $validated['footer_license_no'] ?? $defaults['footer_license_no'],
            'footer_address' => $validated['footer_address'] ?? $defaults['footer_address'],
            'footer_phone' => $validated['footer_phone'] ?? $defaults['footer_phone'],
            'footer_email' => $validated['footer_email'] ?? $defaults['footer_email'],
            'footer_youtube_url' => $validated['footer_youtube_url'] ?? $defaults['footer_youtube_url'],
            'footer_facebook_url' => $validated['footer_facebook_url'] ?? $defaults['footer_facebook_url'],
            'footer_payment_strip' => $request->boolean('footer_payment_strip'),
            'show_slider' => $request->boolean('show_slider'),
            'show_batches' => $request->boolean('show_batches'),
            'show_stats' => $request->boolean('show_stats'),
            'show_learning_paths' => $request->boolean('show_learning_paths'),
            'show_digital_carousel' => $request->boolean('show_digital_carousel'),
            'show_reviews' => $request->boolean('show_reviews'),
            'show_app_banner' => $request->boolean('show_app_banner'),
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => 'homepage_customizer'],
            [
                'value' => json_encode($payload, JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'updated_by' => $request->user()?->id,
            ]
        );

        $this->audit->log('cms.homepage_customized', 'system_setting', 'homepage_customizer', []);

        return back()->with('status', 'Full A-to-Z homepage, footer, carousel & mobile app customization saved.');
    }

    public function updateInquiryStatus(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', 'in:new,in_progress,resolved']]);
        DB::table('contact_inquiries')->where('id', $id)->update([
            'status' => $validated['status'],
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Inquiry status updated.');
    }

    private function storeUploadedFile(UploadedFile $file, string $folder): string
    {
        $dir = public_path('uploads/' . $folder);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $extension = strtolower((string) $file->guessExtension());
        abort_unless(in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true), 422, 'Unsupported CMS upload file type.');

        // Public uploads must use a server-detected extension, never the client filename.
        $filename = Str::uuid()->toString() . '.' . $extension;
        $file->move($dir, $filename);

        return '/uploads/' . $folder . '/' . $filename;
    }

    private function deleteUnusedUpload(?string $path, ?int $ignorePostId = null): void
    {
        if (!$path || !str_starts_with($path, '/uploads/')) return;
        $references = [
            'cms_posts' => ['thumbnail_path', 'pdf_thumbnail', 'pdf_path'],
            'cms_post_revisions' => ['thumbnail_path', 'pdf_thumbnail', 'pdf_path'],
            'courses' => ['thumbnail', 'pdf_thumbnail', 'pdf_path'],
            'storefront_items' => ['thumbnail_path', 'pdf_thumbnail_path', 'download_url'],
        ];
        foreach ($references as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) continue;
                $query = DB::table($table)->where($column, $path);
                if ($table === 'cms_posts' && $ignorePostId) $query->where('id', '!=', $ignorePostId);
                if ($query->exists()) return;
            }
        }
        $absolute = public_path(ltrim($path, '/'));
        $root = realpath(public_path('uploads'));
        $file = realpath($absolute);
        if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
    }

    private function defaultHomepageConfig(): array
    {
        return [
            'batches_badge' => 'Online Course',
            'batches_title' => 'Online Batches are ongoing!',
            'batches_subtitle' => 'Book your seat now!',
            'batch_1_title' => 'IELTS Academic & GT',
            'batch_1_desc' => 'Band 7.5+ Target Preparation',
            'batch_1_url' => '/courses',
            'batch_2_title' => 'Spoken & Grammar Foundation',
            'batch_2_desc' => 'Basic to Pro Live Batches',
            'batch_2_url' => '/courses',
            'stats_badge' => 'Online Course',
            'stats_title' => 'See the stats!',
            'stats_subtitle' => 'The trust we are building!',
            'stats_mode' => 'auto',
            'stats_students_override' => 0,
            'stats_courses_override' => 0,
            'stats_teachers_override' => 0,
            'stats_lessons_override' => 0,
            'paths_title' => 'Choose Your Learning Path',
            'paths_subtitle' => 'Discover diverse courses across multiple disciplines designed to help you excel in your academic and admission journey.',
            'learning_paths' => [
                ['title' => 'Academic Courses', 'desc' => 'Comprehensive academic preparation for all levels', 'bg' => '#D6E9FF', 'border' => '#BBD8FF', 'accent' => '#1D63FF', 'abbr' => 'AC', 'url' => '/courses', 'enabled' => true],
                ['title' => 'Admission Courses', 'desc' => 'Specialized courses for university & IELTS admissions', 'bg' => '#CEFCE0', 'border' => '#B2F5CD', 'accent' => '#00A647', 'abbr' => 'AD', 'url' => '/courses', 'enabled' => true],
                ['title' => 'Grammar & Logic', 'desc' => 'Advanced grammar concepts and problem solving', 'bg' => '#F0DEFF', 'border' => '#E1C2FF', 'accent' => '#9333EA', 'abbr' => 'GR', 'url' => '/ebooks', 'enabled' => true],
                ['title' => 'Spoken & Phonetics', 'desc' => 'Pronunciation clarity, fluency & speaking drills', 'bg' => '#FFE6C7', 'border' => '#FFD09B', 'accent' => '#F94E00', 'abbr' => 'SP', 'url' => '/learn', 'enabled' => true],
                ['title' => 'Technology & Skills', 'desc' => 'Modern writing, presentation & career skills', 'bg' => '#DDE6FF', 'border' => '#C4D2FF', 'accent' => '#4F46E5', 'abbr' => 'SK', 'url' => '/exams', 'enabled' => true],
                ['title' => 'Community', 'desc' => 'Join our supportive peer learning community', 'bg' => '#FFE0EE', 'border' => '#FFC4DE', 'accent' => '#EC0075', 'abbr' => 'CM', 'url' => '/instructors', 'enabled' => true],
            ],
            'carousel_title' => 'Digital Products & Study Guides',
            'carousel_subtitle' => "Explore ShortNote 2.0 E-Books, printed handbooks, and IELTS practice kits crafted by Alom's English Center.",
            'carousel_cta_text' => 'Explore Catalog',
            'reviews_title' => 'What Our Students Say',
            'reviews_subtitle' => "Feedbacks from students who have transformed their learning journey with Alom's English Center.",
            'app_banner_eyebrow' => 'iOS & Android Mobile Learning App',
            'app_banner_title' => "Take Alom's English Center Anywhere on Apple iOS & Android",
            'app_banner_subtitle' => 'Stream HD lectures, read downloaded PDF E-Books offline, join live Zoom classes, and track mock exam scores directly from your iPhone, iPad, or Android device.',
            'app_ios_url' => 'https://apps.apple.com/app/aloms-english-center',
            'app_android_url' => 'https://play.google.com/store/apps/details?id=com.alomsenglishcenter.app',
            'footer_description' => "Alom's English Center is a structured online and campus learning platform providing IELTS, Spoken English, and Grammar programs delivered by certified educators nationwide.",
            'footer_license_no' => 'TRAD/DNCC/044200/2026',
            'footer_address' => 'Plot 14, Sector 6, Mirpur 10 Roundabout, Dhaka-1216',
            'footer_phone' => '+880 1712 345678',
            'footer_email' => 'info@alomsenglishcenter.com',
            'footer_youtube_url' => 'https://www.youtube.com/@alomsenglishcenter',
            'footer_facebook_url' => 'https://www.facebook.com/alomsenglishcenter',
            'footer_payment_strip' => true,
            'show_slider' => true,
            'show_batches' => true,
            'show_stats' => true,
            'show_learning_paths' => true,
            'show_digital_carousel' => true,
            'show_reviews' => true,
            'show_app_banner' => true,
        ];
    }

    private function loadHomepageConfig(): array
    {
        $defaults = $this->defaultHomepageConfig();

        if (!Schema::hasTable('system_settings')) {
            return $defaults;
        }

        $raw = SystemSetting::query()->where('key', 'homepage_customizer')->value('value');
        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
    }
    private function isSafeMediaReference(mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return true;
        }

        $value = trim((string) $value);
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')
            && !str_contains($value, '..') && !str_contains($value, '\\')) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }


}
