@extends('layouts.app')
@section('title', 'Vocabulary Practice — '.$course->title)
@section('content')
<main class="mx-auto max-w-6xl space-y-6 px-4 py-8">
    <header class="rounded-2xl p-6" style="background:{{ $setting->page_bg_color }};color:{{ $setting->default_text_color }}">
        <p class="text-xs font-bold uppercase tracking-wider">Course vocabulary practice</p>
        <h1 class="mt-2 text-2xl font-extrabold">{{ $course->title }}</h1>
        @if($lessonRef)<p class="mt-1 text-sm">Lesson reference: {{ $lessonRef }}</p>@endif
        <p class="mt-2 text-sm opacity-75">Only words assigned to this course, lesson and your batch are shown.</p>
    </header>

    <section class="rounded-2xl border bg-white p-5 shadow-sm" aria-label="Practice progress">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-extrabold">Practice progress</h2>
            <span class="text-sm text-slate-500">Your attempts on these assigned vocabulary items</span>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">Attempts</p><p class="mt-1 text-2xl font-extrabold">{{ $progress['attempts'] }}</p></div>
            <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">Correct answers</p><p class="mt-1 text-2xl font-extrabold">{{ $progress['correct'] }}</p></div>
            <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">Accuracy</p><p class="mt-1 text-2xl font-extrabold">{{ $progress['accuracy'] }}%</p></div>
            <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">Words practiced</p><p class="mt-1 text-2xl font-extrabold">{{ $progress['words_practiced'] }}</p></div>
            <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">British practice</p><p class="mt-1 text-2xl font-extrabold">{{ $progress['uk_attempts'] }}</p></div>
            <div class="rounded-lg bg-slate-50 p-3"><p class="text-xs text-slate-500">American practice</p><p class="mt-1 text-2xl font-extrabold">{{ $progress['us_attempts'] }}</p></div>
        </div>
    </section>

    @if($challenge)
    <section class="rounded-2xl border bg-white p-6 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-blue-700">Quick practice</p>
        <h2 class="mt-2 text-lg font-bold">{{ $challenge['prompt'] }}</h2>
        <form id="course-phonetics-practice" method="POST" action="{{ route('plugins.phonetics.learning.attempt',['courseSlug'=>request()->route('courseSlug')]) }}" class="mt-4 space-y-3">
            @csrf
            @foreach($challenge['choices'] as $choice)
            <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 hover:bg-slate-50"><input type="radio" name="selected_answer" value="{{ $choice['value'] }}" required><span>{{ $choice['label'] }}</span></label>
            @endforeach
            <button class="rounded-lg px-5 py-2 font-bold text-white" style="background:{{ $setting->accent_color }}">Check answer</button>
        </form>
        <p id="course-phonetics-result" class="mt-3 text-sm" aria-live="polite"></p>
        <a id="course-phonetics-next" href="{{ url()->current().($lessonRef ? '?lesson='.urlencode($lessonRef) : '') }}" class="mt-3 hidden text-sm font-semibold text-blue-700 underline">Next practice question</a>
    </section>
    @endif

    <section class="grid gap-4 md:grid-cols-2">
        @forelse($entries as $entry)
        <article class="rounded-xl border bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3"><h2 class="text-xl font-extrabold">{{ $entry->word }}</h2><span class="rounded-full bg-slate-100 px-3 py-1 text-xs">{{ $entry->category }}</span></div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-lg bg-slate-50 p-3"><b>British English</b><p class="mt-1 text-lg">{{ $entry->uk_ipa }}</p>@if($entry->uk_audio_url)<audio class="mt-2 w-full" controls preload="none" src="{{ $entry->uk_audio_url }}"></audio>@endif@if($setting->show_articulatory_notes)<p class="mt-2 text-xs">{{ $entry->uk_note }}</p>@endif</div>
                <div class="rounded-lg bg-slate-50 p-3"><b>American English</b><p class="mt-1 text-lg">{{ $entry->us_ipa }}</p>@if($entry->us_audio_url)<audio class="mt-2 w-full" controls preload="none" src="{{ $entry->us_audio_url }}"></audio>@endif@if($setting->show_articulatory_notes)<p class="mt-2 text-xs">{{ $entry->us_note }}</p>@endif</div>
            </div>
            @if($entry->example_sentence)<p class="mt-3 text-sm italic">{{ $entry->example_sentence }}</p>@endif
        </article>
        @empty
        <div class="rounded-xl border bg-white p-8 text-center text-slate-500 md:col-span-2">No vocabulary has been assigned to this course, lesson or batch yet.</div>
        @endforelse
    </section>
    @if($exams->isNotEmpty())
    <section class="space-y-3">
        <h2 class="text-xl font-extrabold">Available examinations</h2>
        <div class="grid gap-3 md:grid-cols-2">
            @foreach($exams as $exam)
            <article class="rounded-xl border bg-white p-5">
                <h3 class="font-bold">{{ $exam->title }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ $exam->questions()->count() }} questions · {{ $exam->duration_minutes > 0 ? $exam->duration_minutes.' minutes' : 'Untimed' }} · Pass {{ $exam->pass_percentage }}%</p>
                <a class="mt-3 inline-block rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white" href="{{ route('plugins.phonetics.learning.exams.start',['courseSlug'=>request()->route('courseSlug'),'examSlug'=>$exam->slug,'lesson'=>$lessonRef]) }}">Start exam</a>
            </article>
            @endforeach
        </div>
    </section>
    @endif
</main>
<script>
(() => {
 const form=document.getElementById('course-phonetics-practice');if(!form)return;
 form.addEventListener('submit',async(event)=>{
  event.preventDefault();const button=form.querySelector('button');const result=document.getElementById('course-phonetics-result');button.disabled=true;result.textContent='Checking…';
  try{const response=await fetch(form.action,{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''},body:new FormData(form)});const data=await response.json();result.textContent=data.message||'Answer submitted.';if(response.ok){form.querySelectorAll('input,button').forEach(el=>el.disabled=true);document.getElementById('course-phonetics-next').classList.remove('hidden');}else button.disabled=false;}
  catch(_){result.textContent='Unable to submit. Please retry.';button.disabled=false;}
 });
})();
</script>
@endsection
