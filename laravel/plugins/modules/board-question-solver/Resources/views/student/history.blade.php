@extends('layouts.app')
@section('title', __('My solved questions'))
@section('content')
@include('public.partials.navbar')
<main class="mx-auto max-w-5xl space-y-5 px-4 py-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-extrabold text-slate-900">{{ __('My solved questions') }}</h1>
        <a href="{{ route('student.board-solver.index') }}" class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-semibold text-white">{{ __('Solve another question') }}</a>
    </div>
    @forelse ($history as $entry)
        <article class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                <span>{{ $entry->board ?: __('Board not specified') }} · {{ $entry->exam_year ?: '—' }} · {{ $entry->subject }}</span>
                <time>{{ $entry->created_at?->format('d M Y, h:i A') }}</time>
            </div>
            <p class="mt-3 whitespace-pre-wrap text-sm font-semibold text-slate-800">{{ $entry->question }}</p>
            <div class="mt-3 whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ $entry->solution }}</div>
        </article>
    @empty
        <p class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ __('You have not solved any questions yet.') }}</p>
    @endforelse
    <div>{{ $history->links() }}</div>
</main>
@endsection
