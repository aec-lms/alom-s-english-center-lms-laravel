@extends('layouts.app')
@section('title','Assessment Attempt')
@section('content')
@include('public.partials.navbar')
<main class="mx-auto max-w-4xl space-y-5 px-4 py-8"><header class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-extrabold">Assessment attempt #{{ $attempt->attempt_number }}</h1><p class="text-sm text-slate-500">Answers save automatically. Correct answers are not sent to your browser.</p></div>@if($attempt->deadline_at)<div class="rounded-xl bg-amber-50 px-4 py-2 font-bold text-amber-900" id="timer" data-deadline="{{ $attempt->deadline_at->toIso8601String() }}"></div>@endif</header>
<div id="save-status" class="text-sm text-slate-500" aria-live="polite">Ready</div>
@foreach($questions as $index=>$q)<section class="rounded-2xl border bg-white p-5" data-question="{{ $q['id'] }}"><div class="flex justify-between"><h2 class="font-bold">Question {{ $index+1 }}</h2><span class="text-xs text-slate-500">{{ $q['marks'] }} marks</span></div><p class="my-3 whitespace-pre-wrap">{{ $q['question'] }}</p>
@if(in_array($q['type'],['mcq','true_false'],true))<div class="space-y-2">@foreach($q['options']??[] as $option=>$label)<label class="flex items-start gap-2 rounded-lg border p-3"><input type="radio" name="answer-{{ $q['id'] }}" value="{{ $option }}" @checked($q['selected_option']===$option)><span>{{ $label }}</span></label>@endforeach</div>@else<textarea class="answer-text mt-2 w-full rounded-lg border p-3" rows="4" placeholder="Write your answer...">{{ $q['answer_text'] }}</textarea>@endif</section>@endforeach
<form method="POST" action="{{ route('plugins.course-assessments.student.submit',$attempt) }}" onsubmit="return confirm('Submit your assessment now? You cannot edit it afterward.')">@csrf<button class="rounded-lg bg-emerald-700 px-6 py-3 font-bold text-white">Submit assessment</button></form>
</main>
<script>
const token=document.querySelector('meta[name="csrf-token"]')?.content||'';
const statusEl=document.getElementById('save-status');let saveQueue=Promise.resolve();
async function saveAnswer(section,payload){const id=section.dataset.question;statusEl.textContent='Saving…';saveQueue=saveQueue.then(()=>fetch('{{ url('/my-learning/assessments/attempts/'.$attempt->id.'/questions') }}/'+id,{method:'PUT',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify(payload)})).then(async r=>{if(!r.ok)throw new Error('Save failed');await r.json();statusEl.textContent='All answers saved';}).catch(()=>{statusEl.textContent='Save failed — check connection and try again';});return saveQueue;}
document.querySelectorAll('[data-question]').forEach(section=>{
 section.querySelectorAll('input[type="radio"]').forEach(input=>input.addEventListener('change',()=>saveAnswer(section,{selected_option:Number(input.value)})));
 const area=section.querySelector('.answer-text');if(area){let timer;area.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>saveAnswer(section,{answer_text:area.value}),600);});}
});
const timer=document.getElementById('timer');if(timer){const deadline=new Date(timer.dataset.deadline).getTime();const tick=()=>{const left=Math.max(0,Math.floor((deadline-Date.now())/1000));timer.textContent=Math.floor(left/60)+':'+String(left%60).padStart(2,'0');if(left===0){statusEl.textContent='Time expired. Submit your attempt.';clearInterval(interval);}};tick();const interval=setInterval(tick,1000);}
</script>
@endsection
