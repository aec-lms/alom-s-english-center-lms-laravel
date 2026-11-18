@extends('layouts.app')
@section('title', $exam->title . ' — Attempt History')
@section('content')
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
        <header class="bg-white border border-slate-200 rounded-2xl p-5">
            <a href="{{ route('portal.dashboard') }}" class="text-sm font-semibold text-sky-700">← Dashboard</a>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-3">{{ $exam->title }}</h1>
            <p class="text-sm text-slate-500 mt-1">Your personal attempt history. Other students' results and answer keys are private.</p>
            @if($exam->is_published)
                <a href="{{ route('plugins.exams.take', $exam) }}" class="inline-flex mt-4 rounded-lg bg-sky-600 px-4 py-2 text-sm font-bold text-white">Continue / Retake Exam</a>
            @endif
        </header>

        <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <h2 class="font-bold text-slate-900">Attempts ({{ $attempts->total() }})</h2>
            @forelse($attempts as $attempt)
                <article class="rounded-xl border border-slate-200 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <p class="font-semibold text-slate-900">Attempt #{{ $attempt->id }}</p>
                        <p class="text-xs text-slate-500 mt-1">Started: {{ $attempt->started_at?->format('d M Y, h:i A') ?? '—' }}</p>
                        <p class="text-xs text-slate-500">Submitted: {{ $attempt->submitted_at?->format('d M Y, h:i A') ?? 'In progress' }}</p>
                    </div>
                    <div class="sm:text-right">
                        @if($attempt->submitted_at)
                            <p class="text-lg font-extrabold text-slate-900">{{ number_format((float) $attempt->score_obtained, 2) }} / {{ number_format((float) $attempt->total_marks, 2) }}</p>
                            <span class="text-xs font-bold {{ $attempt->passed ? 'text-emerald-700' : 'text-rose-700' }}">{{ $attempt->passed ? 'Passed' : 'Not passed' }}</span>
                        @else
                            <span class="text-xs font-bold text-amber-700">In progress</span>
                        @endif
                    </div>
                </article>
            @empty
                <p class="text-sm text-slate-500">You have not started this exam yet.</p>
            @endforelse
            {{ $attempts->links() }}
        </section>
    </main>
</div>
@endsection
