@extends('layouts.app')
@section('title', $exam->title)
@section('content')
<main class="mx-auto max-w-4xl space-y-5 px-4 py-8">
    <header class="rounded-2xl bg-slate-900 p-6 text-white">
        <p class="text-xs font-bold uppercase tracking-wider text-sky-300">Vocabulary examination</p>
        <h1 class="mt-2 text-2xl font-extrabold">{{ $exam->title }}</h1>
        <p class="mt-2 text-sm text-white/75">{{ $course->title }} · {{ $exam->duration_minutes > 0 ? $exam->duration_minutes.' minutes' : 'No time limit' }} · Pass mark {{ $exam->pass_percentage }}%</p>
        @if($exam->description)<p class="mt-3 text-sm">{{ $exam->description }}</p>@endif
    </header>

    @if($result)
        <section class="rounded-2xl border bg-white p-6 text-center">
            @if(!empty($result['hidden']))
                <h2 class="text-xl font-bold">Your examination has been submitted</h2><p class="mt-2 text-slate-500">The administrator has hidden immediate results.</p>
            @else
                <h2 class="text-xl font-bold">{{ $result['passed'] ? 'Congratulations — you passed!' : 'Exam submitted' }}</h2>
                <p class="mt-3 text-4xl font-extrabold">{{ number_format($result['percentage'],1) }}%</p>
                <p class="mt-1 text-slate-500">Score: {{ $result['score'] }} / {{ $result['total'] }}</p>
                <p class="mt-2 font-semibold {{ $result['passed'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $result['passed'] ? 'PASS' : 'NOT PASSED' }}</p>
            @endif
            <a href="{{ route('plugins.phonetics.learning.index',['courseSlug'=>request()->route('courseSlug')]) }}" class="mt-5 inline-block rounded-lg bg-blue-600 px-5 py-2 font-bold text-white">Back to vocabulary practice</a>
        </section>
    @else
        <form method="POST" action="{{ route('plugins.phonetics.learning.exams.submit',['courseSlug'=>request()->route('courseSlug'),'examSlug'=>$exam->slug,'attempt'=>$attempt->id,'lesson'=>$lessonRef]) }}" class="space-y-4">
            @csrf
            @foreach($questions as $index=>$question)
            <section class="rounded-xl border bg-white p-5">
                <div class="flex items-center justify-between gap-3"><span class="text-xs font-bold uppercase text-blue-700">Question {{ $index+1 }}</span><span class="text-xs text-slate-500">{{ $question['marks'] }} mark(s)</span></div>
                <h2 class="mt-2 font-bold">{{ $question['prompt'] }}</h2>
                <div class="mt-3 space-y-2">
                    @foreach($question['options'] as $option)
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 hover:bg-slate-50"><input type="radio" name="answers[{{ $question['id'] }}]" value="{{ $option['value'] }}"><span>{{ $option['label'] }}</span></label>
                    @endforeach
                </div>
            </section>
            @endforeach
            <button class="w-full rounded-xl bg-emerald-600 px-5 py-3 font-bold text-white">Submit examination</button>
            @if($exam->duration_minutes > 0)<p class="text-center text-xs text-slate-500">The server enforces the time limit. Submitting after expiry is not accepted.</p>@endif
        </form>
    @endif
</main>
@endsection
