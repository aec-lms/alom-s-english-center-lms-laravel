@extends('layouts.app')
@section('title', $course->title . ' — Protected Learning Player')
@section('content')
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="max-w-6xl mx-auto px-4 py-8 space-y-6">
        <div>
            <a href="{{ route('plugins.enrollments.my') }}" class="text-sm font-semibold text-sky-700">← My Enrollments</a>
            <h1 class="mt-3 text-2xl font-extrabold text-slate-900">{{ $course->title }}</h1>
            <p class="text-sm text-slate-500 mt-1">Protected course lessons · Access is checked against your active enrollment.</p>
        </div>

        <section id="protected-player" class="hidden bg-slate-950 rounded-2xl overflow-hidden shadow-lg">
            <div class="relative bg-black aspect-video flex items-center justify-center">
                <video id="protected-video" class="hidden w-full h-full" controls controlsList="nodownload noplaybackrate noremoteplayback" disablePictureInPicture disableRemotePlayback playsinline oncontextmenu="return false"></video>
                <div id="protected-video-ended" class="hidden absolute inset-0 z-10 bg-slate-950/95 text-white flex-col items-center justify-center gap-3 p-6 text-center">
                    <span class="text-3xl" aria-hidden="true">✓</span>
                    <h2 class="text-lg font-extrabold">Lesson completed</h2>
                    <p class="text-sm text-white/70">Continue with the next lesson in this course when you are ready.</p>
                    <button type="button" id="protected-next-lesson" class="hidden rounded-xl bg-sky-600 px-5 py-3 text-sm font-bold text-white hover:bg-sky-500">Continue to next lesson</button>
                    <button type="button" id="protected-close-ended" class="rounded-xl border border-white/30 px-4 py-2 text-xs font-semibold text-white">Stay on this lesson</button>
                </div>
                <audio id="protected-audio" class="hidden w-full" controls controlsList="nodownload" oncontextmenu="return false"></audio>
                <iframe id="protected-pdf" class="hidden w-full h-[75vh] bg-white" title="Protected lesson PDF" referrerpolicy="same-origin"></iframe>
                <div id="protected-watermark" class="pointer-events-none absolute inset-0 overflow-hidden flex items-center justify-center">
                    <span id="protected-watermark-text" class="text-white/60 text-xs sm:text-sm font-semibold px-3 py-2 rounded bg-black/20 select-none"></span>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-white">
                <span id="protected-player-title" class="font-bold text-sm"></span>
                <a id="protected-download" class="hidden text-xs font-bold underline" rel="nofollow">Download permitted file</a>
                <span id="protected-no-download" class="text-xs text-white/60">Downloads disabled</span>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 divide-y divide-slate-100">
            @forelse ($lessons as $lesson)
                <div class="p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-sky-700">LESSON {{ $lesson->sort_order }}</p>
                        <h2 class="font-bold text-slate-900">{{ $lesson->title }}</h2>
                        @if (empty($lesson->media_path))
                            <p class="text-xs text-slate-500 mt-1">Media has not been uploaded yet.</p>
                        @elseif (!$lesson->protection_enabled)
                            <p class="text-xs text-amber-700 mt-1">Protected access is currently disabled by the administrator.</p>
                        @endif
                    </div>
                    @if (!empty($lesson->media_path) && $lesson->protection_enabled)
                        <button type="button"
                            class="protected-lesson-open shrink-0 px-4 py-2.5 rounded-lg bg-sky-600 hover:bg-sky-700 text-white text-sm font-bold"
                            data-access-url="{{ route('plugins.lesson-media.access', $lesson->id) }}"
                            data-mime="{{ $lesson->media_mime }}"
                            data-title="{{ $lesson->title }}"
                            data-lesson-id="{{ $lesson->id }}" data-current-user="{{ auth()->id() }}"
                            data-course-title="{{ $course->title }}">
                            Open Lesson
                        </button>
                    @else
                        <span class="text-xs text-slate-400">Unavailable</span>
                    @endif
                </div>
                <section class="mx-4 sm:mx-5 mb-5 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 sm:p-5" data-lesson-discussion
                    data-lesson-id="{{ $lesson->id }}"
                    data-comments-url="{{ route('plugins.learning-interactions.lesson.comments.index', $lesson->id) }}"
                    data-store-url="{{ route('plugins.learning-interactions.lesson.comments.store', $lesson->id) }}"
                    data-update-template="{{ url('/learning-interactions/lessons/'.$lesson->id.'/comments/__COMMENT__') }}">
                    <div class="flex items-center justify-between gap-3">
                        <div><h3 class="font-extrabold text-slate-900 text-sm">Lesson Discussion</h3><p class="text-xs text-slate-500 mt-1">Ask, discuss and learn together.</p></div>
                        <span class="rounded-full bg-white border border-slate-200 px-3 py-1 text-[11px] font-bold text-slate-600" data-comment-count>Loading…</span>
                    </div>
                    <form class="mt-4 grid gap-2" data-comment-form>
                        <textarea name="body" rows="2" maxlength="4000" required placeholder="Share a question or helpful insight…" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-100"></textarea>
                        <div class="flex items-center justify-between gap-3"><p class="text-[11px] text-slate-500" data-comment-status role="status"></p><button class="rounded-xl bg-sky-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-sky-700" type="submit">Post comment</button></div>
                    </form>
                    <div class="mt-4 grid gap-3" data-comment-list aria-live="polite"><p class="text-xs text-slate-500">Loading lesson discussion…</p></div>
                </section>
            @empty
                <div class="p-8 text-center text-sm text-slate-500">No lessons have been added to this course yet.</div>
            @endforelse
        </section>
        <p class="text-xs text-slate-400">Your access links expire quickly and are tied to your signed-in account. Do not share your login or lesson links.</p>
    </main>
</div>

<style>
@keyframes aec-watermark-drift {
    0% { transform: translate(-18%, -10%); }
    50% { transform: translate(18%, 12%); }
    100% { transform: translate(-8%, 18%); }
}
#protected-watermark-text { animation: aec-watermark-drift 19s ease-in-out infinite alternate; }
</style>
<script>
(() => {
    const buttons = document.querySelectorAll('.protected-lesson-open');
    const panel = document.getElementById('protected-player');
    const video = document.getElementById('protected-video');
    const audio = document.getElementById('protected-audio');
    const pdf = document.getElementById('protected-pdf');
    const watermark = document.getElementById('protected-watermark-text');
    const title = document.getElementById('protected-player-title');
    const download = document.getElementById('protected-download');
    const noDownload = document.getElementById('protected-no-download');
    const endedPanel = document.getElementById('protected-video-ended');
    const nextLessonButton = document.getElementById('protected-next-lesson');
    let activeLessonButton = null;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const recordLessonCompletion = async (button, player) => {
        if (!window.fetch || !csrfToken) return;
        try {
            await fetch('/student-tracking/events', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    event_type: 'lesson_completed',
                    subject_type: 'lesson',
                    subject_id: button.dataset.lessonId,
                    duration_seconds: Math.max(0, Math.min(86400, Math.round(player.currentTime || 0))),
                    metadata: {
                        lesson_title: button.dataset.title || 'Lesson',
                        course_title: button.dataset.courseTitle || '',
                        source: 'learning-player-ended'
                    }
                })
            });
        } catch (_) {
            // Learning playback must not fail if optional tracking is disabled or unavailable.
        }
    };
    video.addEventListener('ended', () => {
        if (activeLessonButton) recordLessonCompletion(activeLessonButton, video);
        endedPanel.classList.remove('hidden');
        endedPanel.classList.add('flex');
        const ordered = Array.from(buttons);
        const next = ordered[ordered.indexOf(activeLessonButton) + 1];
        if (next) {
            nextLessonButton.classList.remove('hidden');
            nextLessonButton.onclick = () => { endedPanel.classList.add('hidden'); endedPanel.classList.remove('flex'); next.click(); };
        } else {
            nextLessonButton.classList.add('hidden');
            nextLessonButton.onclick = null;
        }
    });
    document.getElementById('protected-close-ended')?.addEventListener('click', () => {
        endedPanel.classList.add('hidden');
        endedPanel.classList.remove('flex');
    });
    audio.addEventListener('ended', () => {
        const active = document.querySelector('.protected-lesson-open[data-lesson-id="' + title.dataset.lessonId + '"]');
        if (active) recordLessonCompletion(active, audio);
    });

    const stopPlayers = () => {
        video.pause(); video.removeAttribute('src'); video.load(); video.classList.add('hidden');
        audio.pause(); audio.removeAttribute('src'); audio.load(); audio.classList.add('hidden');
        pdf.removeAttribute('src'); pdf.classList.add('hidden');
    };

    buttons.forEach((button) => button.addEventListener('click', async () => {
        button.disabled = true;
        button.textContent = 'Checking access...';
        try {
            const response = await fetch(button.dataset.accessUrl, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });
            if (!response.ok) throw new Error(response.status === 403 ? 'Your course access is not active.' : 'Unable to open this lesson.');
            const access = await response.json();
            stopPlayers();
            endedPanel.classList.add('hidden'); endedPanel.classList.remove('flex');
            activeLessonButton = button;
            panel.classList.remove('hidden');
            title.textContent = button.dataset.title || 'Protected lesson';
            title.dataset.lessonId = button.dataset.lessonId || '';
            watermark.textContent = access.watermark || 'Alom’s English Center';
            download.classList.add('hidden');
            noDownload.classList.remove('hidden');
            if (access.download_allowed && access.download_url) {
                download.href = access.download_url;
                download.classList.remove('hidden');
                noDownload.classList.add('hidden');
            }

            const mime = button.dataset.mime || '';
            if (mime === 'application/pdf') {
                pdf.src = access.url + '#toolbar=0&navpanes=0';
                pdf.classList.remove('hidden');
            } else if (mime.startsWith('audio/')) {
                audio.src = access.url;
                audio.classList.remove('hidden');
                await audio.play().catch(() => {});
            } else {
                video.src = access.url;
                video.classList.remove('hidden');
                await video.play().catch(() => {});
            }
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            window.alert(error.message || 'Unable to open protected lesson.');
        } finally {
            button.disabled = false;
            button.textContent = 'Open Lesson';
        }
    }));

    document.addEventListener('contextmenu', (event) => {
        if (event.target.closest('#protected-player')) event.preventDefault();
    });
})();
</script>

<script>
(() => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const request = async (url, method = 'GET', payload = null) => {
        const options = {method, credentials:'same-origin', headers:{'Accept':'application/json','X-CSRF-TOKEN':token}};
        if (payload) { options.headers['Content-Type']='application/json'; options.body=JSON.stringify(payload); }
        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'This action could not be completed.');
        return data;
    };
    document.querySelectorAll('[data-lesson-discussion]').forEach(panel => {
        const list = panel.querySelector('[data-comment-list]');
        const count = panel.querySelector('[data-comment-count]');
        const status = panel.querySelector('[data-comment-status]');
        let canModerate = false;
        const render = items => {
            list.replaceChildren();
            count.textContent = items.length + ' comments';
            if (!items.length) { const empty=document.createElement('p'); empty.className='text-xs text-slate-500'; empty.textContent='Be the first to start this lesson discussion.'; list.append(empty); return; }
            items.forEach(item => {
                const article=document.createElement('article');
                article.className='rounded-xl border border-slate-200 bg-white p-3 sm:p-4';
                article.style.marginLeft = item.parent_id ? '1rem' : '0';
                const top=document.createElement('div'); top.className='flex flex-wrap items-center justify-between gap-2';
                const author=document.createElement('strong'); author.className='text-xs text-slate-900'; author.textContent=item.author_name || 'Learner';
                const date=document.createElement('time'); date.className='text-[10px] text-slate-400'; date.textContent=new Date(item.created_at).toLocaleString();
                top.append(author,date);
                const body=document.createElement('p'); body.className='mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700'; body.textContent=item.body;
                article.append(top,body);
                if(item.is_pinned){const pin=document.createElement('span');pin.className='mt-2 inline-block text-[10px] font-bold text-amber-700';pin.textContent='Pinned by instructor';article.append(pin);}
                const actions=document.createElement('div');actions.className='mt-3 flex flex-wrap gap-3';
                const reply=document.createElement('button');reply.type='button';reply.className='text-[11px] font-bold text-sky-700';reply.textContent='Reply';reply.addEventListener('click',()=>{panel.querySelector('textarea[name=body]').value='';panel.querySelector('textarea[name=body]').dataset.parentId=String(item.id);panel.querySelector('textarea[name=body]').focus();status.textContent='Replying to '+(item.author_name||'learner');});actions.append(reply);
                if(canModerate || Number(item.user_id)===Number(panel.dataset.currentUser)){
                    const remove=document.createElement('button');remove.type='button';remove.className='text-[11px] font-bold text-rose-600';remove.textContent='Delete';remove.addEventListener('click',async()=>{if(!confirm('Delete this comment?'))return;try{await request(panel.dataset.updateTemplate.replace('__COMMENT__',item.id),'DELETE');await load();}catch(e){status.textContent=e.message;}});actions.append(remove);
                }
                article.append(actions);list.append(article);
            });
        };
        const load = async () => { try {const data=await request(panel.dataset.commentsUrl);canModerate=!!data.can_moderate;render(data.comments||[]);} catch(e){list.textContent=e.message;count.textContent='Unavailable';} };
        panel.querySelector('[data-comment-form]').addEventListener('submit',async event=>{
            event.preventDefault();const textarea=event.currentTarget.querySelector('textarea');const body=textarea.value.trim();if(!body)return;
            const button=event.currentTarget.querySelector('button');button.disabled=true;status.textContent='Posting…';
            try{await request(panel.dataset.storeUrl,'POST',{body,parent_id:textarea.dataset.parentId?Number(textarea.dataset.parentId):null});textarea.value='';delete textarea.dataset.parentId;status.textContent='Posted successfully.';await load();}
            catch(e){status.textContent=e.message;}finally{button.disabled=false;}
        });
        load();
    });
})();
</script>

@endsection
