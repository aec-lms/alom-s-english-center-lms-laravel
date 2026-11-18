@extends('layouts.app')
@section('title',$post->title)
@section('content')
@include('public.partials.navbar')
<main class="mx-auto max-w-5xl space-y-5 px-4 py-8">
  <a href="{{ route('public.courses.show',$course->slug) }}" class="text-sm text-sky-700 underline">← {{ $course->title }}</a>
  <header><p class="text-sm text-slate-500">{{ $item->section_title }}</p><h1 class="text-3xl font-extrabold">{{ $post->title }}</h1>@if($post->short_description)<p class="mt-2 text-slate-600">{{ $post->short_description }}</p>@endif</header>
  @if($isEnrolled && $totalLessonsCount > 0)
    <section class="rounded-2xl border border-slate-200 bg-white p-4" aria-label="{{ __('Course progress') }}">
      <div class="mb-2 flex items-center justify-between gap-3 text-sm">
        <strong class="text-slate-800">{{ __('Course progress') }}</strong>
        <span class="font-bold text-sky-700">{{ $progressPercent }}%</span>
      </div>
      <div role="progressbar" aria-label="{{ __('Course progress') }}" aria-valuemin="0" aria-valuemax="100"
           aria-valuenow="{{ $progressPercent }}"
           aria-valuetext="{{ __(':completed of :total lessons completed', ['completed' => $completedLessonsCount, 'total' => $totalLessonsCount]) }}"
           class="h-2.5 overflow-hidden rounded-full bg-slate-100">
        <div data-progress-fill class="h-full rounded-full bg-gradient-to-r from-sky-600 to-blue-600"
             style="width: {{ $progressPercent }}%;"></div>
      </div>
      <p class="mt-2 text-xs text-slate-500">{{ $completedLessonsCount }} / {{ $totalLessonsCount }} {{ __('lessons completed') }}</p>
    </section>
  @endif
  @if($post->video_embed_url)<div class="aspect-video overflow-hidden rounded-2xl bg-slate-950"><iframe class="h-full w-full" src="{{ $post->video_url }}" title="{{ $post->title }}" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>@endif
  <article class="prose max-w-none rounded-2xl border bg-white p-6">{!! nl2br(e($post->long_description)) !!}</article>
  @if($post->content_file_path)<a href="{{ route('plugins.content-studio.learning.file',[$course->slug,$post->slug]) }}" class="inline-flex rounded-lg border px-4 py-2 font-semibold">Open lesson file: {{ $post->content_file_name }}</a>@endif
  @if($canComplete && !$progress?->completed_at)<form method="POST" action="{{ route('plugins.content-studio.learning.complete',[$course->slug,$post->slug]) }}">@csrf<button class="rounded-lg bg-emerald-700 px-5 py-2.5 font-semibold text-white">Mark lesson complete</button></form>@elseif($progress?->completed_at)<p class="rounded-lg bg-emerald-50 p-3 text-emerald-800">Lesson completed.</p>@endif
  <nav class="flex justify-between gap-3 border-t pt-5">@if($previousItem?->post)<a href="{{ route('plugins.content-studio.learning.show',[$course->slug,$previousItem->post->slug]) }}" class="text-sky-700 underline">← Previous: {{ $previousItem->post->title }}</a>@else<span></span>@endif @if($nextItem?->post)<a href="{{ route('plugins.content-studio.learning.show',[$course->slug,$nextItem->post->slug]) }}" class="text-sky-700 underline">Next: {{ $nextItem->post->title }} →</a>@endif</nav>
</main>
@endsection
