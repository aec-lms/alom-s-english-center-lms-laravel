@extends('layouts.admin')
@section('title', 'Editorial Workspace — Alom\'s English Center')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Editorial Workspace</h1>
            <p class="mt-1 text-sm text-slate-500">Assigned drafts, multi-author collaboration and independent editorial review.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('plugins.cms.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700">CMS Admin</a>
            @if ($canAssign)
                <a href="{{ route('plugins.cms.contributors.index') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white">Manage Contributors</a>
            @endif
        </div>
    </div>

    @if ($canCreate)
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-bold text-slate-900">Create Editorial Draft</h2>
            <form method="POST" action="{{ route('plugins.cms.editorial.posts.store') }}" class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                @csrf
                <input name="title" required maxlength="200" placeholder="Post title" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="slug" maxlength="180" placeholder="Custom URL slug (optional)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <select name="category_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">General category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <input name="excerpt" maxlength="500" placeholder="Short excerpt" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="seo_title" maxlength="180" placeholder="SEO title (optional)" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="seo_description" maxlength="320" placeholder="SEO description (optional)" class="rounded-lg border border-slate-300 px-3 py-2">
                @if ($canAssign)
                    <select name="author_user_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="">Primary author: me</option>
                        @foreach ($availableAuthors as $author)
                            <option value="{{ $author->id }}">{{ $author->name }} — {{ $author->email }}</option>
                        @endforeach
                    </select>
                    <select name="assigned_editor_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="">No editor assigned</option>
                        @foreach ($availableEditors as $editor)
                            <option value="{{ $editor->id }}">{{ $editor->name }}</option>
                        @endforeach
                    </select>
                    <select name="assigned_reviewer_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="">Reviewer pool (unassigned)</option>
                        @foreach ($availableReviewers as $reviewer)
                            <option value="{{ $reviewer->id }}">{{ $reviewer->name }}</option>
                        @endforeach
                    </select>
                    <select name="co_authors[]" multiple size="4" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                        @foreach ($availableAuthors as $author)
                            <option value="{{ $author->id }}">{{ $author->name }} — {{ $author->email }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 sm:col-span-2">Hold Ctrl (Windows) or Command (Mac) to select multiple co-authors.</p>
                @endif
                <textarea name="content" required maxlength="1000000" rows="7" placeholder="Write the article content..." class="rounded-lg border border-slate-300 px-3 py-2 sm:col-span-2"></textarea>
                <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-2">
                    <p class="text-xs text-slate-500">New editorial posts are always saved as unpublished drafts.</p>
                    <button class="rounded-lg bg-sky-700 px-5 py-2 font-bold text-white">Save Draft</button>
                </div>
            </form>
        </section>
    @endif

    <section class="space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold text-slate-900">My Editorial Queue</h2>
            <span class="text-xs text-slate-500">{{ $posts->total() }} post(s)</span>
        </div>
        @forelse ($posts as $post)
            <article class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h3 class="text-base font-bold text-slate-900">{{ $post->title }}</h3>
                        <p class="mt-1 break-all font-mono text-xs text-slate-500">/{{ $post->slug }} · {{ $post->category }}</p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-700">{{ ucfirst(str_replace('_', ' ', $post->editorial_status ?? 'draft')) }}</span>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-600">{{ $post->is_published ? 'Live' : 'Not published' }}</span>
                        </div>
                    </div>
                    <div class="text-xs text-slate-600 sm:text-right">
                        <div><strong>Author:</strong> {{ $post->primaryAuthor?->name ?? $post->author }}</div>
                        @if ($post->contributors->isNotEmpty())
                            <div class="mt-1"><strong>Co-authors:</strong> {{ $post->contributors->pluck('name')->join(', ') }}</div>
                        @endif
                        @if ($post->assignedEditor)
                            <div class="mt-1"><strong>Editor:</strong> {{ $post->assignedEditor->name }}</div>
                        @endif
                        @if ($post->assignedReviewer)
                            <div class="mt-1"><strong>Reviewer:</strong> {{ $post->assignedReviewer->name }}</div>
                        @endif
                    </div>
                </div>

                @if ($post->review_note)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><strong>Reviewer feedback:</strong> {{ $post->review_note }}</div>
                @endif

                <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                    @if ($canSubmit && !$post->is_published && in_array($post->editorial_status ?? 'draft', ['draft', 'changes_requested'], true))
                        <form method="POST" action="{{ route('plugins.cms.editorial.posts.submit-review', $post) }}">@csrf<button class="rounded-lg bg-sky-700 px-3 py-2 text-xs font-bold text-white">Submit for Review</button></form>
                    @endif
                    @if ($canReview && ($post->editorial_status ?? '') === 'in_review')
                        <form method="POST" action="{{ route('plugins.cms.editorial.posts.approve', $post) }}">@csrf<button class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white">Approve</button></form>
                        <form method="POST" action="{{ route('plugins.cms.editorial.posts.request-changes', $post) }}" class="flex flex-wrap gap-2">@csrf<input name="review_note" required minlength="5" maxlength="2000" placeholder="Explain requested changes" class="min-w-56 rounded-lg border border-slate-300 px-3 py-2 text-xs"><button class="rounded-lg border border-amber-300 px-3 py-2 text-xs font-bold text-amber-800">Request Changes</button></form>
                    @endif
                    @if ($canPublish && !$post->is_published && ($post->editorial_status ?? '') === 'approved')
                        <form method="POST" action="{{ route('plugins.cms.editorial.posts.publish', $post) }}">@csrf<button class="rounded-lg bg-indigo-700 px-3 py-2 text-xs font-bold text-white">Publish Approved</button></form>
                    @endif
                </div>

                @if (!$post->is_published && ($canAssign || (auth()->user()->hasPermission('cms.posts.edit_own') && ((int) $post->created_by === (int) auth()->id() || (int) $post->author_user_id === (int) auth()->id() || $post->contributors->contains('id', auth()->id()) || (int) $post->assigned_editor_id === (int) auth()->id()))))
                    <details class="border-t border-slate-100 pt-3">
                        <summary class="cursor-pointer text-xs font-bold text-slate-700">Edit Draft &amp; Assign Contributors</summary>
                        <form method="POST" action="{{ route('plugins.cms.editorial.posts.update', $post) }}" class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            @csrf @method('PUT')
                            <input name="title" required maxlength="200" value="{{ $post->title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="slug" required maxlength="180" value="{{ $post->slug }}" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                            <select name="category_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                                <option value="">General category</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected($post->category_id === $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            <input name="excerpt" maxlength="500" value="{{ $post->excerpt }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="seo_title" maxlength="180" value="{{ $post->seo_title }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="seo_description" maxlength="320" value="{{ $post->seo_description }}" class="rounded-lg border border-slate-300 px-3 py-2">
                            @if ($canAssign)
                                <select name="author_user_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                                    @foreach ($availableAuthors as $author)
                                        <option value="{{ $author->id }}" @selected((int) $post->author_user_id === (int) $author->id)>{{ $author->name }}</option>
                                    @endforeach
                                </select>
                                <select name="assigned_editor_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                                    <option value="">No editor assigned</option>
                                    @foreach ($availableEditors as $editor)
                                        <option value="{{ $editor->id }}" @selected((int) $post->assigned_editor_id === (int) $editor->id)>{{ $editor->name }}</option>
                                    @endforeach
                                </select>
                                <select name="assigned_reviewer_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                                    <option value="">Reviewer pool (unassigned)</option>
                                    @foreach ($availableReviewers as $reviewer)
                                        <option value="{{ $reviewer->id }}" @selected((int) $post->assigned_reviewer_id === (int) $reviewer->id)>{{ $reviewer->name }}</option>
                                    @endforeach
                                </select>
                                <select name="co_authors[]" multiple size="4" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                                    @foreach ($availableAuthors as $author)
                                        <option value="{{ $author->id }}" @selected($post->contributors->contains('id', $author->id))>{{ $author->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <textarea name="content" required maxlength="1000000" rows="6" class="rounded-lg border border-slate-300 px-3 py-2 sm:col-span-2">{{ $post->content }}</textarea>
                            <div class="sm:col-span-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white">Save Draft Changes</button></div>
                        </form>
                    </details>
                @endif
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">No posts are currently assigned to your editorial queue.</div>
        @endforelse
        <div>{{ $posts->links() }}</div>
    </section>
</div>
@endsection
