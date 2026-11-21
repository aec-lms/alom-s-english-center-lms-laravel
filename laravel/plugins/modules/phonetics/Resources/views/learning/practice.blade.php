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
        <div class="mt-5 grid gap-4 border-t pt-5 md:grid-cols-2">
            <div>
                <h3 class="font-bold">Recent activity</h3>
                <p class="mt-1 text-xs text-slate-500">{{ $progress['recent_7_days'] }} attempts in 7 days · {{ $progress['recent_30_days'] }} in 30 days</p>
                <div class="mt-3 grid grid-cols-7 gap-2" aria-label="Daily practice attempts over the last seven days">
                    @foreach($dailyActivity as $day)
                    <div class="min-w-0 text-center">
                        <div class="flex h-16 items-end justify-center rounded bg-slate-50 p-1">
                            <span class="block w-full rounded-t bg-blue-600" style="height:{{ $day['bar_percent'] }}%" aria-label="{{ $day['attempts'] }} attempts"></span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ $day['label'] }}</p>
                        <p class="text-xs font-semibold">{{ $day['attempts'] }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
            <div>
                <h3 class="font-bold">Accent accuracy</h3>
                <div class="mt-2 space-y-2 text-sm">
                    <p>British English: <strong>{{ $accentAccuracy['uk'] === null ? 'Not enough practice yet' : $accentAccuracy['uk'].'%' }}</strong></p>
                    <p>American English: <strong>{{ $accentAccuracy['us'] === null ? 'Not enough practice yet' : $accentAccuracy['us'].'%' }}</strong></p>
                </div>
                <h3 class="mt-4 font-bold">Teacher-reviewed speaking</h3>
                <p class="mt-1 text-sm text-slate-600">{{ $progress['speaking_reviewed'] }} reviewed · {{ $progress['speaking_pending'] }} awaiting review</p>
                <p class="text-sm text-slate-600">Average teacher score: <strong>{{ $progress['speaking_average_score'] === null ? 'No reviewed recordings' : $progress['speaking_average_score'].'/5' }}</strong></p>
                <p class="mt-1 text-xs text-slate-500">Teacher scores are human assessments, separate from IPA quiz accuracy.</p>
            </div>
        </div>
        <div class="mt-5 border-t pt-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-bold">Spaced review queue</h3>
                <span class="text-xs text-slate-500">{{ $reviewQueue->count() }} items due</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Review timing is based on your saved IPA quiz accuracy: weaker words return sooner. New assigned words appear immediately.</p>
            @if($reviewQueue->isNotEmpty())
                <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach($reviewQueue as $item)
                    <li class="flex items-center justify-between gap-2 rounded-lg bg-blue-50 p-3">
                        <div><a class="font-semibold text-blue-800 underline" href="#phonetics-word-{{ $item['entry_id'] }}">{{ $item['word'] }}</a><p class="text-xs text-slate-600">{{ $item['reason'] }}</p></div>
                        <span class="shrink-0 text-xs font-semibold">{{ $item['attempts'] === 0 ? 'New' : $item['accuracy'].'%' }}</span>
                    </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-sm text-slate-600">You are up to date. Your next review will appear when it is due.</p>
            @endif
        </div>
        <div class="mt-5 border-t pt-5">
            <h3 class="font-bold">Recommended practice</h3>
            <p class="mt-1 text-xs text-slate-500">Recommendations are based on at least three saved IPA quiz attempts per assigned word.</p>
            @if($weakWords->isNotEmpty())
                <ul class="mt-3 space-y-2">
                    @foreach($weakWords as $item)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-amber-50 p-3">
                        <div><a class="font-semibold text-blue-800 underline" href="#phonetics-word-{{ $item['entry_id'] }}">{{ $item['word'] }}</a><p class="text-xs text-slate-600">{{ $item['category'] }} · {{ $item['attempts'] }} attempts</p></div>
                        <span class="text-sm font-bold">{{ $item['accuracy'] }}% accuracy</span>
                    </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-sm text-slate-600">No weak assigned words identified yet. Complete at least three attempts per word to receive targeted recommendations.</p>
            @endif
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
        <article id="phonetics-word-{{ $entry->id }}" class="rounded-xl border bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3"><h2 class="text-xl font-extrabold">{{ $entry->word }}</h2><span class="rounded-full bg-slate-100 px-3 py-1 text-xs">{{ $entry->category }}</span></div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-lg bg-slate-50 p-3"><b>British English</b><p class="mt-1 text-lg">{{ $entry->uk_ipa }}</p>@if($entry->uk_audio_url)<audio class="mt-2 w-full" controls preload="none" src="{{ $entry->uk_audio_url }}"></audio>@endif@if($setting->show_articulatory_notes)<p class="mt-2 text-xs">{{ $entry->uk_note }}</p>@endif</div>
                <div class="rounded-lg bg-slate-50 p-3"><b>American English</b><p class="mt-1 text-lg">{{ $entry->us_ipa }}</p>@if($entry->us_audio_url)<audio class="mt-2 w-full" controls preload="none" src="{{ $entry->us_audio_url }}"></audio>@endif@if($setting->show_articulatory_notes)<p class="mt-2 text-xs">{{ $entry->us_note }}</p>@endif</div>
            </div>
            @if($entry->example_sentence)<p class="mt-3 text-sm italic">{{ $entry->example_sentence }}</p>@endif
            <div class="mt-4 rounded-xl border border-dashed p-4 phonetics-recorder" data-upload-url="{{ route('plugins.phonetics.learning.recordings.store',['courseSlug'=>request()->route('courseSlug')]) }}" data-entry-id="{{ $entry->id }}">
                <p class="font-semibold">Speaking practice</p>
                <p class="mt-1 text-xs text-slate-500">Maximum 120 seconds per take. Recording duration is browser-reported; the upload is size-limited and stored privately for teacher review.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="record-start rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white">Start recording</button>
                    <button type="button" class="record-stop rounded-lg bg-slate-700 px-4 py-2 text-sm font-bold text-white" disabled>Stop & submit</button>
                </div>
                <audio class="record-preview mt-3 hidden w-full" controls></audio>
                <p class="record-status mt-2 text-sm" role="status" aria-live="polite"></p>
            </div>
        </article>
        @empty
        <div class="rounded-xl border bg-white p-8 text-center text-slate-500 md:col-span-2">No vocabulary has been assigned to this course, lesson or batch yet.</div>
        @endforelse
    </section>
    <section id="phonetics-recording-history" class="rounded-xl border bg-white p-5" data-history-url="{{ route('plugins.phonetics.learning.recordings.history',['courseSlug'=>request()->route('courseSlug')]) }}">
        <h2 class="text-lg font-extrabold">Your speaking feedback</h2>
        <p class="mt-1 text-sm text-slate-500">Teacher reviews and scores for your recent recordings.</p>
        <div class="recording-history-items mt-3 space-y-3"><p class="text-sm text-slate-500">Loading your feedback…</p></div>
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
 const csrf=document.querySelector('meta[name=csrf-token]')?.content||'';
 const history=document.getElementById('phonetics-recording-history');
 const loadRecordingHistory=()=>{
  if(!history)return Promise.resolve();
  return fetch(history.dataset.historyUrl,{headers:{Accept:'application/json'}}).then(response=>response.ok?response.json():Promise.reject()).then(data=>{
   const list=history.querySelector('.recording-history-items');list.replaceChildren();
   if(!data.recordings?.length){list.textContent='No recordings submitted yet.';return;}
   data.recordings.forEach(item=>{
    const card=document.createElement('article');card.className='rounded-lg bg-slate-50 p-3';
    const title=document.createElement('p');title.className='font-semibold';title.textContent=item.word?.word||'Vocabulary recording';
    const meta=document.createElement('p');meta.className='mt-1 text-xs text-slate-500';meta.textContent=(item.status==='reviewed'?'Reviewed · Score '+item.score+'/5':'Awaiting teacher review')+' · '+item.reported_duration_seconds+' sec';
    card.append(title,meta);
    const audio=document.createElement('audio');audio.className='mt-2 w-full';audio.controls=true;audio.preload='none';audio.src=item.audio_url;card.append(audio);
    if(item.feedback){const feedback=document.createElement('p');feedback.className='mt-2 text-sm';feedback.textContent=item.feedback;card.append(feedback);}
    if(item.status==='pending'){
     const remove=document.createElement('button');remove.type='button';remove.className='mt-2 rounded-lg border border-red-300 px-3 py-1.5 text-sm font-semibold text-red-700';remove.textContent='Delete pending recording';
     remove.addEventListener('click',async()=>{
      remove.disabled=true;remove.textContent='Deleting…';
      try{
       const response=await fetch(item.delete_url,{method:'DELETE',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf}});
       if(!response.ok){const result=await response.json().catch(()=>({}));throw new Error(result.message||'Unable to delete this recording.');}
       card.remove();
       if(!list.children.length)list.textContent='No recordings submitted yet.';
      }catch(error){remove.disabled=false;remove.textContent='Delete pending recording';meta.textContent=error.message||'Unable to delete this recording.';}
     });
     card.append(remove);
    }
    list.append(card);
   });
  }).catch(()=>{history.querySelector('.recording-history-items').textContent='Your feedback could not be loaded right now.';});
 };
 if(history)loadRecordingHistory();
 document.querySelectorAll('.phonetics-recorder').forEach((widget)=>{
  const start=widget.querySelector('.record-start'),stop=widget.querySelector('.record-stop'),status=widget.querySelector('.record-status'),preview=widget.querySelector('.record-preview');
  let recorder=null,stream=null,chunks=[],startedAt=0,previewUrl=null,limitTimer=null;
  const finishTracks=()=>{if(limitTimer)clearTimeout(limitTimer);if(stream){stream.getTracks().forEach(track=>track.stop());stream=null;}};
  start.addEventListener('click',async()=>{
   if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){status.textContent='Microphone recording is not supported in this browser. Try a current Chrome, Edge or Firefox browser.';return;}
   start.disabled=true;status.textContent='Waiting for microphone permission…';
   try{
    stream=await navigator.mediaDevices.getUserMedia({audio:true});
    const preferred=['audio/webm;codecs=opus','audio/ogg;codecs=opus','audio/webm'].find(type=>MediaRecorder.isTypeSupported(type));
    recorder=preferred?new MediaRecorder(stream,{mimeType:preferred}):new MediaRecorder(stream);chunks=[];startedAt=Date.now();
    recorder.addEventListener('dataavailable',event=>{if(event.data.size)chunks.push(event.data);});
    recorder.addEventListener('stop',async()=>{
     const duration=Math.max(1,Math.ceil((Date.now()-startedAt)/1000));finishTracks();stop.disabled=true;
     if(duration>120){status.textContent='Maximum recording length is 120 seconds. Please record a shorter answer.';start.disabled=false;return;}
     const blob=new Blob(chunks,{type:recorder.mimeType||'audio/webm'});
     if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl=URL.createObjectURL(blob);preview.src=previewUrl;preview.classList.remove('hidden');
     const extension=blob.type.includes('ogg')?'ogg':blob.type.includes('mp4')?'m4a':'webm';
     const data=new FormData();data.append('_token',csrf);data.append('entry_id',widget.dataset.entryId);data.append('reported_duration_seconds',String(duration));data.append('recording',new File([blob],'phonetics-practice.'+extension,{type:blob.type}));
     status.textContent='Uploading securely…';
     try{
      const response=await fetch(widget.dataset.uploadUrl,{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:data});
      const result=await response.json();if(!response.ok)throw new Error(result.message||'Upload failed.');
      status.textContent='Submitted for teacher review. Recording #'+result.recording_id+'.';
      await loadRecordingHistory();
     }catch(error){status.textContent=error.message||'Unable to upload recording. Please try again.';}
     start.disabled=false;
    },{once:true});
    recorder.start();start.disabled=true;stop.disabled=false;status.textContent='Recording… speak clearly. Maximum 120 seconds.';limitTimer=setTimeout(()=>{if(recorder&&recorder.state!=='inactive'){status.textContent='120-second limit reached. Uploading your recording…';recorder.stop();}},120000);
   }catch(error){finishTracks();start.disabled=false;status.textContent=error.name==='NotAllowedError'?'Microphone permission was denied. Allow microphone access and try again.':'Could not start microphone recording.';}
  });
  stop.addEventListener('click',()=>{if(recorder&&recorder.state!=='inactive'){stop.disabled=true;status.textContent='Finishing recording…';recorder.stop();}});
 });
 const form=document.getElementById('course-phonetics-practice');if(!form)return;
 form.addEventListener('submit',async(event)=>{
  event.preventDefault();const button=form.querySelector('button');const result=document.getElementById('course-phonetics-result');button.disabled=true;result.textContent='Checking…';
  try{const response=await fetch(form.action,{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''},body:new FormData(form)});const data=await response.json();result.textContent=data.message||'Answer submitted.';if(response.ok){form.querySelectorAll('input,button').forEach(el=>el.disabled=true);document.getElementById('course-phonetics-next').classList.remove('hidden');}else button.disabled=false;}
  catch(_){result.textContent='Unable to submit. Please retry.';button.disabled=false;}
 });
})();
</script>
@endsection
