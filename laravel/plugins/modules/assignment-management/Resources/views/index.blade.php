@extends('layouts.admin')
@section('title', 'Assignments — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-6">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Assignments</h1>
        <p class="text-sm text-slate-500 mt-1">Publish tasks, track submissions and grade student work.</p>
    </div>
    @if(session('status'))<div class="rounded-lg bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">{{ session('status') }}</div>@endif
    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <h2 class="font-bold text-slate-900">Create assignment</h2>
        <form method="POST" action="{{ route('plugins.assignments.store') }}" class="grid gap-3 sm:grid-cols-2">
            @csrf
            <input name="title" required maxlength="180" placeholder="Assignment title" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="course_id" type="number" min="1" placeholder="Optional Course ID" class="rounded-lg border border-slate-300 px-3 py-2">
            <textarea name="instructions" required rows="4" maxlength="20000" placeholder="Instructions" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2"></textarea>
            <label class="text-sm text-slate-600">Deadline <input name="due_at" type="datetime-local" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2"></label>
            <label class="text-sm text-slate-600">Maximum score <input name="max_score" type="number" min="0.01" step="0.01" value="100" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2"></label>
            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="allow_late" value="1"> Accept late submissions</label>
            <select name="status" class="rounded-lg border border-slate-300 px-3 py-2"><option value="draft">Draft</option><option value="published">Publish</option></select>
            <button class="sm:col-span-2 rounded-lg bg-sky-600 px-4 py-2.5 font-bold text-white">Save assignment</button>
        </form>
        @if($errors->any())<ul class="text-sm text-rose-700 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="font-bold mb-4">Assignment list</h2>
        <div class="space-y-3">
            @forelse($assignments as $assignment)
                <article class="rounded-xl border border-slate-200 p-4">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div><h3 class="font-bold">{{ $assignment->title }}</h3><p class="text-xs text-slate-500 mt-1">{{ $assignment->status }} · {{ $assignment->submissions_count }} submissions · Due {{ $assignment->due_at?->format('d M Y, h:i A') ?? 'No deadline' }}</p></div>
                        <span class="text-sm font-semibold">Max {{ number_format((float)$assignment->max_score, 2) }}</span>
                    </div>
                    <p class="text-sm text-slate-600 mt-2">{{ \Illuminate\Support\Str::limit(strip_tags($assignment->instructions), 180) }}</p>
                    @foreach($assignment->submissions as $submission)
                        <div class="mt-3 border-t pt-3 text-sm">
                            <div class="flex flex-wrap justify-between gap-2"><strong>{{ $submission->student?->name ?? 'Student' }}</strong><span>{{ $submission->status }} · {{ $submission->submitted_at?->format('d M Y H:i') }}</span></div>
                            <p class="text-slate-600 mt-1">{{ \Illuminate\Support\Str::limit($submission->answer_text, 180) }}</p>
                            @if($submission->attachment_path)<a class="text-sky-700 underline" href="{{ route('plugins.assignments.attachment', $submission->id) }}">Download attachment</a>@endif
                            <form method="POST" action="{{ route('plugins.assignments.grade', $submission->id) }}" class="mt-2 flex flex-wrap gap-2">@csrf<input name="score" type="number" min="0" max="{{ $assignment->max_score }}" step="0.01" required placeholder="Score" class="w-28 rounded border px-2 py-1"><input name="feedback" maxlength="5000" placeholder="Feedback" class="min-w-48 flex-1 rounded border px-2 py-1"><button class="rounded bg-slate-900 px-3 py-1 text-white">Save grade</button></form>
                        </div>
                    @endforeach
                </article>
            @empty
                <p class="text-sm text-slate-500">No assignments created yet.</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $assignments->links() }}</div>
    </section>
</div>
@endsection
