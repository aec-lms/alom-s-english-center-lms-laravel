@extends('layouts.app')
@section('title', $assignment->title)
@section('content')
@include('public.partials.navbar')
<div class="mx-auto max-w-3xl px-4 py-8 space-y-5">
    <a href="{{ route('plugins.assignments.student.index') }}" class="text-sm text-sky-700 underline">← My assignments</a>
    <article class="rounded-2xl border border-slate-200 bg-white p-6">
        <h1 class="text-2xl font-extrabold">{{ $assignment->title }}</h1>
        <p class="mt-2 text-sm text-slate-500">Due: {{ $assignment->due_at?->format('d M Y, h:i A') ?? 'No deadline' }} · Maximum score: {{ $assignment->max_score }}</p>
        <div class="prose prose-slate max-w-none mt-5">{!! nl2br(e($assignment->instructions)) !!}</div>
    </article>
    @if(session('status'))<div class="rounded-lg bg-emerald-50 px-4 py-3 text-emerald-800">{{ session('status') }}</div>@endif
    @if($submission?->status === 'graded')
        <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5"><h2 class="font-bold">Graded: {{ $submission->score }} / {{ $assignment->max_score }}</h2><p class="mt-2 text-sm">{{ $submission->feedback }}</p></section>
    @else
        @if($errors->any())<div class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>@endif
        <form method="POST" enctype="multipart/form-data" action="{{ route('plugins.assignments.student.submit', $assignment->id) }}" class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
            @csrf
            <h2 class="font-bold">Submit your work</h2>
            <textarea name="answer_text" rows="7" maxlength="30000" placeholder="Write your answer here..." class="w-full rounded-lg border border-slate-300 p-3">{{ old('answer_text', $submission?->answer_text) }}</textarea>
            <label class="block text-sm text-slate-600">Attach file (PDF, DOC/DOCX, TXT, image or ZIP; max 20 MB)<input type="file" name="attachment" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip" class="mt-2 block w-full"></label>
            @if($submission?->attachment_path)<p class="text-xs text-slate-500">A previous attachment is saved unless you upload a replacement.</p>@endif
            <button class="rounded-lg bg-sky-600 px-5 py-2.5 font-bold text-white">Submit assignment</button>
        </form>
    @endif
</div>
@endsection
