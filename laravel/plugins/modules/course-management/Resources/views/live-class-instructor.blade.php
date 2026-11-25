@extends('layouts.app')

@section('title', ($class->title ?? 'Live Class') . ' — Instructor Control Room')

@section('content')
<main class="mx-auto max-w-5xl space-y-6 px-4 py-8">
    <header class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-wide text-sky-700">AEC LMS · Instructor Live Control</p>
            <h1 class="mt-1 text-2xl font-extrabold text-slate-900">{{ $class->title }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $course->title ?? 'Course Live Session' }} · {{ $class->instructor_name ?? 'Instructor' }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            @if(!empty($class->meeting_url))
                <a href="{{ $class->meeting_url }}" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-sky-600 px-5 py-2.5 text-sm font-bold text-white">Launch meeting room</a>
            @endif
            <a href="{{ route('portal.dashboard') }}" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700">Back to dashboard</a>
        </div>
    </header>

    <section id="instructor-live-qa" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6"
             data-csrf="{{ csrf_token() }}"
             data-list-url="{{ route('plugins.learning-interactions.live.questions.index', $class->id) }}"
             data-announcement-url="{{ route('plugins.learning-interactions.live.announcements.store', $class->id) }}"
             data-answer-template="{{ route('plugins.learning-interactions.live.questions.answer', ['liveClass' => $class->id, 'question' => '__QUESTION__']) }}"
             data-moderate-template="{{ route('plugins.learning-interactions.live.questions.moderate', ['liveClass' => $class->id, 'question' => '__QUESTION__']) }}">
        <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">Student Questions &amp; Moderation</h2>
                <p class="mt-1 text-xs text-slate-500">Publish pinned announcements, answer students, and moderate the live Q&amp;A feed.</p>
            </div>
            <button id="refresh-live-qa" type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">Refresh</button>
        </div>
        <form id="instructor-announcement-form" class="grid gap-3 rounded-xl bg-slate-50 p-4">
            <label for="instructor-announcement" class="text-sm font-semibold text-slate-800">Pinned announcement</label>
            <textarea id="instructor-announcement" name="body" rows="2" maxlength="2000" required class="w-full rounded-lg border border-slate-300 bg-white p-3 text-sm" placeholder="Share an instruction or prompt for learners..."></textarea>
            <div class="flex items-center justify-between gap-3">
                <span id="instructor-live-status" role="status" aria-live="polite" class="text-xs text-slate-500"></span>
                <button class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold text-white" type="submit">Publish announcement</button>
            </div>
        </form>
        <div id="instructor-live-questions" class="grid gap-3" aria-live="polite"><p class="text-sm text-slate-500">Loading questions…</p></div>
    </section>
</main>
<script>
(() => {
    'use strict';
    const root = document.getElementById('instructor-live-qa');
    if (!root) return;
    const status = document.getElementById('instructor-live-status');
    const list = document.getElementById('instructor-live-questions');
    const request = async (url, method = 'GET', payload = null) => {
        const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf || '' } };
        if (payload !== null) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(payload); }
        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'The request could not be completed.');
        return data;
    };
    const el = (tag, cls, value) => {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (value !== undefined) node.textContent = String(value ?? '');
        return node;
    };
    const load = async () => {
        try {
            const payload = await request(root.dataset.listUrl);
            const items = Array.isArray(payload.questions) ? payload.questions : [];
            const parents = items.filter(item => !item.parent_id);
            const replies = new Map();
            items.filter(item => item.parent_id).forEach(item => {
                const group = replies.get(String(item.parent_id)) || [];
                group.push(item); replies.set(String(item.parent_id), group);
            });
            list.replaceChildren();
            if (!parents.length) { list.append(el('p', 'py-4 text-center text-sm text-slate-500', 'No questions yet.')); return; }
            parents.forEach(q => {
                const card = el('article', 'space-y-3 rounded-xl border border-slate-200 p-4');
                card.append(el('p', 'text-xs font-bold text-slate-700', (q.author_name || 'Learner') + (q.is_pinned ? ' · Pinned' : '') + ' · ' + Number(q.votes_count || 0) + ' votes'));
                card.append(el('p', 'whitespace-pre-wrap text-sm text-slate-900', q.body));
                (replies.get(String(q.id)) || []).forEach(reply => card.append(el('p', 'ml-4 rounded-lg bg-sky-50 p-3 text-xs text-slate-700', (reply.author_name || 'Learner') + ': ' + reply.body)));
                const controls = el('div', 'flex flex-wrap gap-2');
                const pin = el('button', 'rounded-lg border px-3 py-1.5 text-xs font-semibold', q.is_pinned ? 'Unpin' : 'Pin');
                pin.type = 'button';
                pin.addEventListener('click', async () => { try { await request(root.dataset.moderateTemplate.replace('__QUESTION__', q.id), 'PATCH', { is_pinned: !q.is_pinned }); await load(); } catch (e) { status.textContent = e.message; } });
                const hide = el('button', 'rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700', 'Hide');
                hide.type = 'button';
                hide.addEventListener('click', async () => { try { await request(root.dataset.moderateTemplate.replace('__QUESTION__', q.id), 'PATCH', { status: 'hidden' }); await load(); } catch (e) { status.textContent = e.message; } });
                controls.append(pin, hide);
                const form = el('form', 'flex gap-2');
                const input = el('input', 'min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm');
                input.type = 'text'; input.name = 'body'; input.maxLength = 2000; input.required = true; input.placeholder = 'Write an instructor answer…';
                const send = el('button', 'rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white', 'Answer'); send.type = 'submit';
                form.append(input, send);
                form.addEventListener('submit', async event => { event.preventDefault(); try { await request(root.dataset.answerTemplate.replace('__QUESTION__', q.id), 'POST', { body: input.value.trim() }); await load(); } catch (e) { status.textContent = e.message; } });
                card.append(controls, form); list.append(card);
            });
        } catch (error) { status.textContent = error.message; }
    };
    document.getElementById('instructor-announcement-form').addEventListener('submit', async event => {
        event.preventDefault();
        const input = event.currentTarget.elements.body;
        const body = input.value.trim();
        if (!body) return;
        try { await request(root.dataset.announcementUrl, 'POST', { body }); input.value = ''; status.textContent = 'Announcement published.'; await load(); }
        catch (error) { status.textContent = error.message; }
    });
    document.getElementById('refresh-live-qa').addEventListener('click', load);
    load();
})();
</script>
@endsection
