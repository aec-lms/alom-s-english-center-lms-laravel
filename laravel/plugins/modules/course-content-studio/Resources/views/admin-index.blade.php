@extends('layouts.admin')
@section('title','Course Content Studio')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
  <header><h1 class="text-2xl font-extrabold">Course Content Studio</h1><p class="text-sm text-slate-500">Create reusable lessons, attach private files, organize categories and assemble course curricula.</p></header>
  @if(session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="rounded-lg bg-rose-50 p-3 text-rose-700">{{ $errors->first() }}</div>@endif
  <div class="grid gap-5 lg:grid-cols-3">
    <section class="rounded-2xl border bg-white p-5 lg:col-span-2">
      <h2 class="mb-4 text-lg font-bold">Create reusable lesson</h2>
      <form method="POST" action="{{ route('plugins.content-studio.admin.posts.store') }}" enctype="multipart/form-data" class="space-y-3">@csrf
        <label class="block text-sm">Title<input name="title" required maxlength="180" class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="block text-sm">Slug (optional)<input name="slug" maxlength="200" class="mt-1 w-full rounded-lg border p-2" placeholder="auto-generated-from-title"></label>
        <label class="block text-sm">Short description<textarea name="short_description" maxlength="500" rows="2" class="mt-1 w-full rounded-lg border p-2"></textarea></label>
        <label class="block text-sm">Lesson content<textarea name="long_description" required maxlength="100000" rows="7" class="mt-1 w-full rounded-lg border p-2"></textarea></label>
        <label class="block text-sm">YouTube/Vimeo URL<input name="video_url" type="url" maxlength="500" class="mt-1 w-full rounded-lg border p-2" placeholder="https://youtu.be/..."></label>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block text-sm">Access<select name="access_type" class="mt-1 w-full rounded-lg border p-2"><option value="course_enrollment">Enrolled students</option><option value="free">Free</option></select></label>
          <label class="block text-sm">Status<select name="status" class="mt-1 w-full rounded-lg border p-2"><option value="draft">Draft</option><option value="published">Published</option></select></label>
        </div>
        <label class="block text-sm">Tags (comma separated)<input name="tags" maxlength="1000" class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="block text-sm">SEO title<input name="seo_title" maxlength="180" class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="block text-sm">SEO description<textarea name="seo_description" maxlength="320" rows="2" class="mt-1 w-full rounded-lg border p-2"></textarea></label>
        <div class="grid gap-3 sm:grid-cols-2"><label class="block text-sm">Thumbnail (max 5 MB)<input type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp,image/gif" class="mt-1 w-full"></label><label class="block text-sm">Private lesson file (PDF/video/audio/image/ZIP, max 100 MB)<input type="file" name="content_file" class="mt-1 w-full"></label></div>
        <fieldset><legend class="mb-2 text-sm font-semibold">Categories</legend><div class="flex flex-wrap gap-3">@foreach($categories as $category)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="category_ids[]" value="{{ $category->id }}">{{ $category->name }}</label>@endforeach</div></fieldset>
        <button class="rounded-lg bg-slate-900 px-5 py-2.5 font-semibold text-white">Save lesson</button>
      </form>
    </section>
    <section class="rounded-2xl border bg-white p-5"><h2 class="mb-3 text-lg font-bold">Create category</h2><form method="POST" action="{{ route('plugins.content-studio.admin.categories.store') }}" class="space-y-3">@csrf<label class="block text-sm">Name<input name="name" required maxlength="120" class="mt-1 w-full rounded-lg border p-2"></label><label class="block text-sm">Description<input name="description" maxlength="320" class="mt-1 w-full rounded-lg border p-2"></label><button class="rounded-lg border px-4 py-2">Add category</button></form>
      <h3 class="mb-2 mt-6 font-semibold">Categories</h3>@forelse($categories as $category)<p class="border-b py-2 text-sm">{{ $category->name }}</p>@empty<p class="text-sm text-slate-500">No categories yet.</p>@endforelse
    </section>
  </div>
  @if($courses->isNotEmpty())<section class="rounded-2xl border bg-white p-5"><h2 class="mb-3 text-lg font-bold">Course curriculum builder</h2><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach($courses as $course)<a class="rounded-xl border p-3 font-medium text-sky-700 underline" href="{{ route('plugins.content-studio.admin.builder',$course->id) }}">{{ $course->title }} · Build curriculum</a>@endforeach</div></section>@endif
  <section class="overflow-x-auto rounded-2xl border bg-white p-5"><h2 class="mb-3 text-lg font-bold">Reusable content library</h2><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-3">Lesson</th><th>Author</th><th>Status</th><th>Access</th><th>Categories</th><th></th></tr></thead><tbody>
  @forelse($posts as $post)<tr class="border-b align-top"><td class="py-3"><strong>{{ $post->title }}</strong><div class="text-xs text-slate-500">{{ $post->slug }}</div></td><td>{{ $post->author?->name }}</td><td>{{ ucfirst($post->status) }}</td><td>{{ str_replace('_',' ',$post->access_type) }}</td><td>{{ $post->categories->pluck('name')->join(', ') }}</td><td class="text-right"><form method="POST" action="{{ route('plugins.content-studio.admin.posts.archive',$post->id) }}" onsubmit="return confirm('Archive this lesson? Curriculum and progress history will remain.')">@csrf @method('DELETE')<button class="text-rose-700 underline">Archive</button></form></td></tr>@empty<tr><td colspan="6" class="py-6 text-center text-slate-500">No lessons created yet.</td></tr>@endforelse
  </tbody></table>{{ $posts->links() }}</section>
</div>
@endsection
