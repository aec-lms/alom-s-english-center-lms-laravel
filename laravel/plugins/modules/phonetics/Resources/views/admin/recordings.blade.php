@extends('layouts.admin')
@section('title','Phonetics Speaking Review')
@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-extrabold">Speaking & Pronunciation Review</h1><p class="mt-1 text-sm text-slate-500">Listen to private student recordings and return a rubric score with actionable feedback.</p></div>
        <a href="{{ route('plugins.phonetics.admin.learning') }}" class="rounded-lg border px-4 py-2 text-sm font-semibold">Vocabulary & Exams</a>
    </header>
    @if(session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <section class="overflow-hidden rounded-xl border bg-white">
        <div class="border-b p-4"><h2 class="font-bold">Student submissions</h2><p class="text-xs text-slate-500">Recordings are private. Playback is available only to authorized Phonetics staff and the submitting student.</p></div>
        @forelse($recordings as $recording)
        <article class="grid gap-4 border-b p-4 lg:grid-cols-[1fr_1.2fr]">
            <div>
                <p class="font-bold">{{ $recording->word?->word ?? 'Archived vocabulary' }}</p>
                <p class="mt-1 text-xs text-slate-500">Student ID: {{ $recording->user_id }} · {{ $recording->duration_seconds }} sec · {{ $recording->created_at?->format('d M Y, h:i A') }}</p>
                <span class="mt-2 inline-flex rounded-full px-2 py-1 text-xs {{ $recording->status === 'reviewed' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ ucfirst($recording->status) }}</span>
                <audio class="mt-3 w-full" controls preload="none" src="{{ route('plugins.phonetics.admin.learning.recordings.audio',['recording'=>$recording->id]) }}"></audio>
                @if($recording->status === 'reviewed')
                    <p class="mt-2 text-sm font-semibold">Score: {{ $recording->score }}/5</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $recording->feedback }}</p>
                @endif
            </div>
            <form method="POST" action="{{ route('plugins.phonetics.admin.learning.recordings.review',['recording'=>$recording->id]) }}" class="space-y-3 rounded-lg bg-slate-50 p-4">
                @csrf @method('PATCH')
                <label class="block text-sm font-semibold">Pronunciation rubric score
                    <select name="score" required class="mt-1 w-full rounded border bg-white p-2">
                        @foreach([5=>'5 — Clear and accurate',4=>'4 — Minor errors',3=>'3 — Understandable, needs practice',2=>'2 — Frequent pronunciation errors',1=>'1 — Needs guided support'] as $score=>$label)
                            <option value="{{ $score }}" @selected((int)$recording->score === $score)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-semibold">Teacher feedback
                    <textarea name="feedback" required minlength="3" maxlength="2000" rows="3" class="mt-1 w-full rounded border bg-white p-2" placeholder="Mention the specific sound, stress or articulation to improve.">{{ $recording->feedback }}</textarea>
                </label>
                <button class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-bold text-white">Save review</button>
            </form>
        </article>
        @empty
        <p class="p-8 text-center text-sm text-slate-500">No speaking recordings have been submitted yet.</p>
        @endforelse
    </section>
    <div>{{ $recordings->links() }}</div>
</div>
@endsection
