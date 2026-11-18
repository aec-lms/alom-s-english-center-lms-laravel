@extends('layouts.app')
@section('title','British & American Phonetics')
@section('content')
<div class="mx-auto max-w-6xl space-y-5 py-8">
 <header class="rounded-2xl bg-slate-100 p-6"><h1 class="text-2xl font-bold">{{ $setting->course_title }}</h1><p class="mt-2">Completed drills: {{ $stats['correct'] }}/{{ $stats['attempted'] }}</p></header>
 @if($challenge)
 <section class="rounded-2xl border border-blue-200 bg-white p-6 shadow-sm">
  <div class="text-xs font-bold uppercase tracking-wide text-blue-700">Practice challenge · {{ $challenge['quiz_mode']==='identify-ipa'?'Identify IPA':'Identify accent' }}</div>
  <h2 class="mt-2 text-lg font-bold">{{ $challenge['prompt'] }}</h2>
  <form id="phonetics-quiz" class="mt-4 space-y-3" method="POST" action="{{ route('plugins.phonetics.practice.attempts.store',['courseSlug'=>$setting->course_slug]) }}">
   @csrf
   @foreach($challenge['choices'] as $i=>$choice)
    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 hover:bg-slate-50"><input type="radio" name="selected_answer" value="{{ $choice['value'] }}" required><span>{{ $choice['label'] }}</span></label>
   @endforeach
   <button class="rounded-lg bg-blue-600 px-5 py-2 font-semibold text-white" type="submit">Check answer</button>
  </form>
  <p id="phonetics-quiz-result" class="mt-3 text-sm" aria-live="polite"></p>
  <a id="phonetics-next-challenge" class="mt-3 hidden text-sm font-semibold text-blue-700 underline" href="{{ url()->current() }}">Next challenge</a>
 </section>
 @else
 <p class="rounded-xl bg-white p-5 text-slate-500">No active entries are available yet.</p>
 @endif
 <div class="grid gap-4 md:grid-cols-2">
 @forelse($entries as $e)
  <article class="rounded-xl border bg-white p-5"><div class="flex justify-between"><h2 class="text-xl font-bold">{{ $e->word }}</h2><span class="text-xs uppercase">{{ $e->category }}</span></div>
   <div class="mt-4 grid grid-cols-2 gap-3"><div class="rounded bg-slate-50 p-3"><b>British</b><p class="text-lg">{{ $e->uk_ipa }}</p>@if($setting->show_articulatory_notes)<small>{{ $e->uk_note }}</small>@endif</div><div class="rounded bg-slate-50 p-3"><b>American</b><p class="text-lg">{{ $e->us_ipa }}</p>@if($setting->show_articulatory_notes)<small>{{ $e->us_note }}</small>@endif</div></div>
   @if($e->example_sentence)<p class="mt-3 text-sm italic">{{ $e->example_sentence }}</p>@endif
  </article>
 @empty
  <p>No active entries.</p>
 @endforelse
 </div>
</div>
<script>
(function(){
 const form=document.getElementById('phonetics-quiz');if(!form)return;
 form.addEventListener('submit',async function(event){
  event.preventDefault();const button=form.querySelector('button[type=submit]');const result=document.getElementById('phonetics-quiz-result');button.disabled=true;result.textContent='Checking answer…';
  try{
   const response=await fetch(form.action,{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''},body:new FormData(form)});
   const payload=await response.json();result.textContent=payload.message||payload.message|| (response.ok?'Answer saved.':'Challenge expired. Reload the page.');
   if(response.ok){form.querySelectorAll('input,button').forEach(control=>control.disabled=true);document.getElementById('phonetics-next-challenge').classList.remove('hidden');}
   else button.disabled=false;
  }catch(error){result.textContent='Could not submit your answer. Please try again.';button.disabled=false;}
 });
})();
</script>
@endsection