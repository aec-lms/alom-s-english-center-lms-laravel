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
                <video id="protected-video" class="hidden w-full h-full" controls controlsList="nodownload noplaybackrate" disablePictureInPicture playsinline oncontextmenu="return false"></video>
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
                            data-title="{{ $lesson->title }}"\n                            data-lesson-id="{{ $lesson->id }}"\n                            data-course-title="{{ $course->title }}">
                            Open Lesson
                        </button>
                    @else
                        <span class="text-xs text-slate-400">Unavailable</span>
                    @endif
                </div>
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
        const active = document.querySelector('.protected-lesson-open[data-lesson-id="' + title.dataset.lessonId + '"]');
        if (active) recordLessonCompletion(active, video);
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
@endsection
