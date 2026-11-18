@extends('layouts.app')
@section('title', 'My Assignments')
@section('content')
@include('public.partials.navbar')
<div class="mx-auto max-w-5xl px-4 py-8 space-y-5">
    <div><h1 class="text-3xl font-extrabold text-slate-900">My Assignments</h1><p class="text-slate-500 mt-1">View tasks, submit your work and check teacher feedback.</p></div>
    @if(session('status'))<div class="rounded-lg bg-emerald-50 px-4 py-3 text-emerald-800">{{ session('status') }}</div>@endif
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($assignments as $assignment)
            @php($submission = $assignment->submissions->first())
            <a href="{{ route('plugins.assignments.student.show', $assignment->id) }}" class="block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-sky-300">
                <h2 class="font-bold text-lg">{{ $assignment->title }}</h2>
                <p class="mt-2 text-sm text-slate-500">{{ \Illuminate\Support\Str::limit(strip_tags($assignment->instructions), 150) }}</p>
                <div class="mt-4 flex flex-wrap justify-between gap-2 text-xs"><span>Due: {{ $assignment->due_at?->format('d M Y, h:i A') ?? 'No deadline' }}</span><span class="font-bold">{{ $submission?->status ?? 'Not submitted' }}</span></div>
                @if($submission?->status === 'graded')<p class="mt-2 font-bold text-emerald-700">Score: {{ $submission->score }} / {{ $assignment->max_score }}</p>@endif
            </a>
        @empty
            <p class="text-slate-500">No assignments available.</p>
        @endforelse
    </div>
    {{ $assignments->links() }}
</div>
@endsection
