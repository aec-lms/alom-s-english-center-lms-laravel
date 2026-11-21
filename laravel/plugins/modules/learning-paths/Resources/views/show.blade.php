@extends('layouts.app')

@section('title', $path->title . ' — Learning Path')

@section('content')
<div class="mx-auto max-w-4xl space-y-6 px-4 py-8">
    <a href="{{ route('learning-paths.catalog') }}" class="text-sm font-semibold text-indigo-700">← All learning paths</a>
    <header class="rounded-2xl border border-slate-200 bg-white p-6"><p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Learning Path</p><h1 class="mt-2 text-3xl font-extrabold">{{ $path->title }}</h1><p class="mt-3 text-slate-600">{{ $path->description }}</p><p class="mt-3 text-sm text-slate-500">{{ $courses->count() }} courses · Complete each course to unlock the next.</p></header>
    @if (session('status'))<div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if (auth()->check() && auth()->user()->hasRole('student') && !$pathEnrollment)
        <form method="POST" action="{{ route('learning-paths.enroll', $path->slug) }}" class="rounded-xl border border-indigo-200 bg-indigo-50 p-4">
            @csrf
            @if ($firstEnrollment)
                <p class="text-sm text-indigo-900">You are enrolled in the first course. Join this path to track your ordered progress.</p>
                <button class="mt-3 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Join learning path</button>
            @else
                <p class="text-sm text-indigo-900">Enroll in the first course before joining this path. Course purchases and payments remain managed by the normal course checkout.</p>
            @endif
        </form>
    @elseif (!auth()->check())
        <p class="rounded-xl bg-slate-50 p-4 text-sm">Sign in with your student account to join this learning path.</p>
    @endif
    <section class="space-y-3">
        @foreach ($courses as $course)
            @php($step = $progress->firstWhere('course_id', $course->course_id))
            <article class="flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $step && $step->completed ? 'bg-emerald-100 text-emerald-700' : 'bg-indigo-50 text-indigo-700' }} font-bold">{{ $course->position }}</div>
                <div class="min-w-0 flex-1"><h2 class="font-bold text-slate-900">{{ $course->course_title }}</h2><p class="mt-1 text-sm text-slate-500">{{ $step && $step->completed ? 'Completed' : ($step && $step->unlocked ? 'Current step' : 'Locked until previous course is completed') }}</p>
                    @if ($step && $step->unlocked && $step->enrolled && $course->course_slug && $course->course_status === 'published')
                        <a class="mt-2 inline-flex text-sm font-semibold text-indigo-700" href="{{ route('public.courses.show', $course->course_slug) }}">Open course →</a>
                    @elseif ($step && $step->unlocked && !$step->enrolled)
                        <span class="mt-2 inline-block text-xs text-amber-700">Enroll in this course separately to begin</span>
                    @endif
                </div>
                @if ($step && $step->completed)<span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Done</span>@elseif($step && $step->unlocked)<span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">Unlocked</span>@else<span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">Locked</span>@endif
            </article>
        @endforeach
    </section>
    @if ($pathEnrollment)<a href="{{ route('learning-paths.mine') }}" class="inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">My learning paths</a>@endif
</div>
@endsection
