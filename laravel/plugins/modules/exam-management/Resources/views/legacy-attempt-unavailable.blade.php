@extends('layouts.app')
@section('title', 'Exam attempt needs support')
@section('content')
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="max-w-2xl mx-auto px-4 py-12">
        <section class="rounded-3xl border border-amber-200 bg-white p-6 shadow-sm sm:p-8" role="alert" aria-labelledby="legacy-attempt-title">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-700" aria-hidden="true">
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M10.3 3.9 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3l-7.5-13.1a2 2 0 0 0-3.4 0Z"/>
                </svg>
            </div>
            <p class="mt-5 text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Exam attempt protected</p>
            <h1 id="legacy-attempt-title" class="mt-2 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">We need to verify this attempt</h1>
            <p class="mt-4 text-base leading-7 text-slate-600">{{ $message }}</p>
            <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <h2 class="text-sm font-bold text-slate-900">What should you do?</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">Please contact your instructor or school administrator and share the exam title below. Do not start another attempt unless your school confirms it is safe to do so.</p>
                <p class="mt-3 text-sm font-semibold text-slate-800">Exam: {{ $exam->title }}</p>
            </div>
            <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('portal.dashboard') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-sky-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-600 focus:ring-offset-2">Return to dashboard</a>
                <a href="{{ route('plugins.exams.history', $exam) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-600 focus:ring-offset-2">View attempt history</a>
            </div>
        </section>
    </main>
</div>
@endsection
