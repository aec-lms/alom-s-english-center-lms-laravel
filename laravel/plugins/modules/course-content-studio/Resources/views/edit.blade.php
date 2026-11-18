@extends('layouts.admin')
@section('title','Edit Course Lesson')
@section('content')
<div class="mx-auto max-w-4xl space-y-5"><a href="{{ route('plugins.content-studio.admin.index') }}" class="text-sm text-sky-700 underline">← Content Studio</a><h1 class="text-2xl font-extrabold">Edit lesson: {{ $record->title }}</h1>
@if($errors->any())<div class="rounded-lg bg-rose-50 p-3 text-rose-700">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('plugins.content-studio.admin.posts.update',$record->id) }}" enctype="multipart/form-data" class="space-y-4 rounded-2xl border bg-white p-5">@csrf @method('PUT')
<label class="block text-sm">Title<input name="title" required maxlength="180" value="{{ old('title',$record->title) }}" class="mt-1 w-full rounded border p-2"></label>
<label class="block text-sm">Slug<input name="slug" maxlength="200" value="{{ old('slug',$record->slug) }}" class="mt-1 w-full rounded border p-2"></label>
<label class="block text-sm">Short description<textarea name="short_description" maxlength="500" rows="2" class="mt-1 w-full rounded border p-2">{{ old('short_description',$record->short_description) }}</textarea></label>
<label class="block text-sm">Lesson content<textarea name="long_description" required maxlength="100000" rows="8" class="mt-1 w-full rounded border p-2">{{ old('long_description',$record->long_description) }}</textarea></label>
<label class="block text-sm">YouTube/Vimeo URL<input name="video_url" type="url" maxlength="500" value="{{ old('video_url',$record->video_url) }}" class="mt-1 w-full rounded border p-2"></label>
<div class="grid gap-3 sm:grid-cols-2"><label class="block text-sm">Access<select name="access_type" class="mt-1 w-full rounded border p-2"><option value="course_enrollment" @selected($record->access_type==='course_enrollment')>Enrolled students</option><option value="free" @selected($record->access_type==='free')>Free</option></select></label><label class="block text-sm">Status<select name="status" class="mt-1 w-full rounded border p-2"><option value="draft" @selected($record->status==='draft')>Draft</option><option value="published" @selected($record->status==='published')>Published</option></select></label></div>
<label class="block text-sm">Tags<input name="tags" maxlength="1000" value="{{ old('tags',implode(', ',$record->tags??[])) }}" class="mt-1 w-full rounded border p-2"></label>
<label class="block text-sm">SEO title<input name="seo_title" maxlength="180" value="{{ old('seo_title',$record->seo_title) }}" class="mt-1 w-full rounded border p-2"></label>
<label class="block text-sm">SEO description<textarea name="seo_description" maxlength="320" rows="2" class="mt-1 w-full rounded border p-2">{{ old('seo_description',$record->seo_description) }}</textarea></label>
@if($record->thumbnail_path)<img src="{{ asset('storage/'.$record->thumbnail_path) }}" alt="{{ $record->title }}" class="max-h-40 rounded"><label class="text-sm"><input type="checkbox" name="remove_thumbnail" value="1"> Remove thumbnail</label>@endif
@if($record->content_file_path)<p class="text-sm text-slate-500">Current file: {{ $record->content_file_name }}</p><label class="text-sm"><input type="checkbox" name="remove_content_file" value="1"> Remove lesson file</label>@endif
<div class="grid gap-3 sm:grid-cols-2"><label class="block text-sm">Replace thumbnail<input type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp,image/gif" class="mt-1 w-full"></label><label class="block text-sm">Replace private lesson file<input type="file" name="content_file" class="mt-1 w-full"></label></div>
<fieldset><legend class="mb-2 text-sm font-semibold">Categories</legend><div class="flex flex-wrap gap-3">@foreach($categories as $category)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="category_ids[]" value="{{ $category->id }}" @checked($record->categories->contains($category->id))>{{ $category->name }}</label>@endforeach</div></fieldset>
<button class="rounded-lg bg-slate-900 px-5 py-2.5 font-semibold text-white">Save changes</button></form></div>
@endsection
