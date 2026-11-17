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
@endsection
