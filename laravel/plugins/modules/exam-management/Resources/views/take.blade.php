@extends('layouts.app')
@section('title', $exam->title . ' — Exam')
@section('content')
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
        <header class="bg-white border border-slate-200 rounded-2xl p-5">
            <a href="{{ route('portal.dashboard') }}" class="text-sm font-semibold text-sky-700">← Dashboard</a>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-3">{{ $exam->title }}</h1>
            <p class="text-sm text-slate-500 mt-2">{{ $exam->duration_minutes }} minutes · {{ $questions->count() }} questions · Pass marks: {{ $exam->pass_marks }}</p>
            <p class="text-xs text-slate-500 mt-2">Choose one answer for each question. Your score is calculated securely on the server after submission.</p>
        </header>

        @if($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 p-4 text-sm">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('plugins.exams.attempt', $exam) }}" class="space-y-4" onsubmit="return confirm('Submit your answers now? You cannot change them after submission.')">
            @csrf
            @foreach($questions as $index => $question)
                <section class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-bold text-slate-900">{{ $index + 1 }}. {{ $question->prompt }}</h2>
                        <span class="text-xs text-slate-500 whitespace-nowrap">{{ number_format((float) $question->marks, 2) }} marks</span>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2">
                        @foreach((array) $question->options as $optionIndex => $option)
                            <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 hover:border-sky-400 cursor-pointer">
                                <input type="radio" name="answers[{{ $question->id }}]" value="{{ $optionIndex }}" class="mt-1" required>
                                <span class="text-sm text-slate-700">{{ $option }}</span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @endforeach
            <div class="flex justify-end">
                <button type="submit" class="rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold px-6 py-3">Submit Exam</button>
            </div>
        </form>
    </main>
</div>
@endsection
