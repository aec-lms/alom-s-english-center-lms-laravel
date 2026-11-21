@extends('layouts.app')

@section('title', 'My Learning Paths')

@section('content')
<div class="mx-auto max-w-5xl space-y-6 px-4 py-8">
    <header><h1 class="text-3xl font-extrabold">My Learning Paths</h1><p class="mt-2 text-sm text-slate-600">Your course journeys and completion progress.</p></header>
    @forelse ($enrollments as $enrollment)
        <article class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-bold">{{ $enrollment->title }}</h2><p class="text-sm text-slate-500">Started {{ $enrollment->started_at }}</p></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase">{{ $enrollment->status }}</span></div>
            @php($done = $enrollment->progress->where('completed', true)->count())
            @php($total = $enrollment->progress->count())
            <div class="mt-4"><div class="mb-1 flex justify-between text-xs text-slate-500"><span>Progress</span><span>{{ $done }} / {{ $total }} courses</span></div><div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-600" style="width: {{ $total ? round($done / $total * 100) : 0 }}%"></div></div></div>
            <ol class="mt-4 space-y-2">@foreach ($enrollment->progress as $step)<li class="flex items-center justify-between gap-3 text-sm"><span>{{ $step->position }}. {{ $step->course_title }}</span><span class="{{ $step->completed ? 'text-emerald-700' : ($step->unlocked ? 'text-indigo-700' : 'text-slate-400') }}">{{ $step->completed ? 'Completed' : ($step->unlocked ? 'Available' : 'Locked') }}</span></li>@endforeach</ol>
            <a href="{{ route('learning-paths.show', $enrollment->slug) }}" class="mt-4 inline-flex text-sm font-semibold text-indigo-700">Open path →</a>
        </article>
    @empty
        <p class="rounded-xl border border-dashed p-8 text-sm text-slate-500">You have not joined a learning path yet. Browse the <a class="font-semibold text-indigo-700" href="{{ route('learning-paths.catalog') }}">learning path catalog</a>.</p>
    @endforelse
</div>
@endsection
