@extends('layouts.app')
@section('title', $liveClass->title . ' — Live Class Access')
@section('content')
<div class="max-w-3xl mx-auto px-4 py-10">
    <section
        class="bg-white border border-slate-200 rounded-2xl p-6 space-y-5"
        id="live-class-presence"
        data-heartbeat-url="{{ $heartbeatUrl }}"
        data-leave-url="{{ $leaveUrl }}"
        data-csrf="{{ csrf_token() }}"
    >
        <div>
            <p class="text-xs font-bold uppercase tracking-wide text-sky-700">Aloms English Center · Live Class</p>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-2">{{ $liveClass->title }}</h1>
            <p class="text-sm text-slate-600 mt-2">{{ $liveClass->course?->title }} · {{ $liveClass->instructor_name }} · {{ strtoupper($liveClass->platform) }}</p>
        </div>
        <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm">
            <p class="font-bold text-slate-900">Your LMS seat is reserved.</p>
            <p class="text-slate-600 mt-1"><span id="active-count">{{ $activeCount }}</span> / {{ $liveClass->capacity }} active LMS participants</p>
            <p class="text-xs text-slate-500 mt-2">Keep this page open while attending the meeting so your seat presence stays current.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ $liveClass->meeting_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-xl bg-sky-600 px-5 py-3 text-white font-bold hover:bg-sky-700">Open {{ strtoupper($liveClass->platform) }} Meeting</a>
            <button type="button" id="leave-live-class" class="rounded-xl border border-slate-300 px-5 py-3 font-bold text-slate-700 hover:bg-slate-50">Leave Live Class</button>
        </div>
        <section id="live-class-qa" class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5 space-y-4"
            data-list-url="{{ route('plugins.learning-interactions.live.questions.index', $liveClass->id) }}"
            data-store-url="{{ route('plugins.learning-interactions.live.questions.store', $liveClass->id) }}"
            data-answer-template="{{ url('/learning-interactions/live-classes/'.$liveClass->id.'/questions/__QUESTION__/answers') }}"
            data-vote-template="{{ url('/learning-interactions/live-classes/'.$liveClass->id.'/questions/__QUESTION__/vote') }}"
            data-moderate-template="{{ url('/learning-interactions/live-classes/'.$liveClass->id.'/questions/__QUESTION__') }}">
            <div class="flex items-start justify-between gap-3"><div><h2 class="font-extrabold text-slate-900">Live Class Q&amp;A</h2><p class="mt-1 text-xs text-slate-500">Ask questions, follow the instructor’s answers and vote for useful questions.</p></div><span class="rounded-full bg-white border border-slate-200 px-3 py-1 text-[11px] font-bold text-slate-600" data-qa-count>Loading…</span></div>
            <form id="live-qa-form" class="grid gap-2">
                <textarea name="body" rows="2" maxlength="2000" required placeholder="What would you like to ask the instructor?" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-100"></textarea>
                <div class="flex items-center justify-between gap-3"><p class="text-xs text-slate-500" id="live-qa-status" role="status"></p><button class="rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-indigo-700" type="submit">Send question</button></div>
            </form>
            <div id="live-qa-list" class="grid gap-3" aria-live="polite"><p class="text-xs text-slate-500">Loading class questions…</p></div>
        </section>
        <p id="presence-status" role="status" class="text-xs text-slate-500">Seat presence is active. This page sends a small heartbeat every 45 seconds.</p>
        <p class="text-xs text-amber-700">LMS capacity controls the number of active student join sessions. Actual video-room capacity is controlled separately by your Zoom, Google Meet or Teams plan.</p>
    </section>
</div>
<script>
(() => {
    const panel = document.getElementById('live-class-presence');
    if (!panel) return;
    const status = document.getElementById('presence-status');
    const token = panel.dataset.csrf;
    const headers = {'X-CSRF-TOKEN': token, 'Accept': 'application/json'};
    const heartbeat = async () => {
        try {
            const response = await fetch(panel.dataset.heartbeatUrl, {method: 'POST', headers, credentials: 'same-origin'});
            if (!response.ok) throw new Error('presence refresh failed');
            const data = await response.json();
            const count = document.getElementById('active-count');
            if (count && Number.isInteger(data.active_count)) count.textContent = String(data.active_count);
            status.textContent = 'Seat presence is active.';
        } catch (error) {
            status.textContent = 'Presence refresh failed. Reopen this page before rejoining if the meeting continues.';
        }
    };
    const timer = window.setInterval(heartbeat, 45000);
    document.getElementById('leave-live-class')?.addEventListener('click', async () => {
        window.clearInterval(timer);
        try {
            await fetch(panel.dataset.leaveUrl, {method: 'POST', headers, credentials: 'same-origin'});
        } finally {
            status.textContent = 'You have left the LMS live class.';
            document.getElementById('leave-live-class').disabled = true;
        }
    });
})();
</script>

<script>
(() => {
    const panel=document.getElementById('live-class-qa'); if(!panel)return;
    const list=document.getElementById('live-qa-list'), count=document.querySelector('[data-qa-count]'), status=document.getElementById('live-qa-status');
    const token=panel.closest('[data-csrf]')?.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
    const api=async(url,method='GET',body=null)=>{const options={method,credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':token}};if(body){options.headers['Content-Type']='application/json';options.body=JSON.stringify(body);}const response=await fetch(url,options);const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.message||'Unable to complete this action.');return data;};
    const render=(items,canAnswer=false)=>{list.replaceChildren();count.textContent=items.length+' posts';if(!items.length){const empty=document.createElement('p');empty.className='text-xs text-slate-500';empty.textContent='No questions yet. Start the discussion.';list.append(empty);return;}
        items.forEach(item=>{const card=document.createElement('article');card.className='rounded-xl border border-slate-200 bg-white p-4';const head=document.createElement('div');head.className='flex flex-wrap items-center justify-between gap-2';const author=document.createElement('strong');author.className='text-xs font-bold text-slate-900';author.textContent=item.author_name||'Participant';const time=document.createElement('time');time.className='text-[10px] text-slate-400';time.textContent=new Date(item.created_at).toLocaleTimeString();head.append(author,time);card.append(head);if(item.is_pinned){const pin=document.createElement('p');pin.className='mt-2 text-[10px] font-bold text-amber-700';pin.textContent='★ Instructor pinned';card.append(pin);}const body=document.createElement('p');body.className='mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700';body.textContent=item.body;card.append(body);
            const actions=document.createElement('div');actions.className='mt-3 flex flex-wrap items-center gap-3';const vote=document.createElement('button');vote.type='button';vote.className='rounded-lg bg-slate-50 px-3 py-1.5 text-[11px] font-bold text-slate-700';vote.textContent='▲ Helpful · '+Number(item.votes_count||0);vote.addEventListener('click',async()=>{try{const result=await api(panel.dataset.voteTemplate.replace('__QUESTION__',item.id),'POST');vote.textContent='▲ Helpful · '+result.votes;}catch(e){status.textContent=e.message;}});actions.append(vote);
            if(canAnswer && !item.parent_id){const answer=document.createElement('button');answer.type='button';answer.className='text-[11px] font-bold text-indigo-700';answer.textContent='Instructor answer';answer.addEventListener('click',async()=>{const text=window.prompt('Write your answer');if(!text||!text.trim())return;try{await api(panel.dataset.answerTemplate.replace('__QUESTION__',item.id),'POST',{body:text.trim()});await load();status.textContent='Answer published.';}catch(e){status.textContent=e.message;}});actions.append(answer);}
            if(item.parent_id){card.style.marginLeft='1rem';}
            card.append(actions);list.append(card);
        });
    };
    const load=async()=>{try{const data=await api(panel.dataset.listUrl);panel.dataset.canAnswer=data.can_answer?'true':'false';render(data.questions||[],!!data.can_answer);panel.querySelectorAll('[data-instructor-controls]').forEach(el=>el.remove());if(data.can_answer){list.querySelectorAll('article').forEach((card,index)=>{const item=(data.questions||[])[index];if(!item||item.parent_id)return;const controls=document.createElement('div');controls.dataset.instructorControls='1';controls.className='mt-2 flex gap-3';const pin=document.createElement('button');pin.type='button';pin.className='text-[11px] font-bold text-amber-700';pin.textContent=item.is_pinned?'Unpin':'Pin';pin.addEventListener('click',async()=>{try{await api(panel.dataset.moderateTemplate.replace('__QUESTION__',item.id),'PATCH',{is_pinned:!item.is_pinned});await load();}catch(e){status.textContent=e.message;}});const hide=document.createElement('button');hide.type='button';hide.className='text-[11px] font-bold text-rose-600';hide.textContent='Hide';hide.addEventListener('click',async()=>{try{await api(panel.dataset.moderateTemplate.replace('__QUESTION__',item.id),'PATCH',{status:'hidden'});await load();}catch(e){status.textContent=e.message;}});controls.append(pin,hide);card.append(controls);});}}catch(e){list.textContent=e.message;count.textContent='Unavailable';}};
    document.getElementById('live-qa-form').addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,textarea=form.querySelector('textarea'),button=form.querySelector('button');if(!textarea.value.trim())return;button.disabled=true;status.textContent='Sending…';try{await api(panel.dataset.storeUrl,'POST',{body:textarea.value.trim()});textarea.value='';status.textContent='Your question has been sent.';await load();}catch(e){status.textContent=e.message;}finally{button.disabled=false;}});
    load();window.setInterval(load,20000);
})();
</script>
@endsection
