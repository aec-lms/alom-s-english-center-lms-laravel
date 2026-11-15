@extends('layouts.admin')
@section('title', 'Email Notifications — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Email Notifications &amp; Templates</h1>
        <p class="text-xs text-slate-500 mt-0.5">Configure reusable notification templates and dispatch student/guardian emails via encrypted SMTP.</p>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Save Email Template</h2>
            <form method="POST" action="{{ route('plugins.email_notifications.templates.store') }}" class="grid gap-3 text-xs">
                @csrf
                <input name="template_key" required placeholder="Template Key (e.g. admission_welcome) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
                <input name="subject" required placeholder="Email Subject *" class="rounded-lg border border-slate-300 px-3 py-2">
                <textarea name="body_html" rows="3" required placeholder="Email body text or HTML..." class="rounded-lg border border-slate-300 px-3 py-2"></textarea>
                <button type="submit" class="px-4 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Save Template</button>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">Dispatch Email</h2>
            <form method="POST" action="{{ route('plugins.email_notifications.send') }}" class="grid gap-3 text-xs">
                @csrf
                <input name="recipient_email" type="email" required placeholder="Recipient Email *" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="subject" required placeholder="Subject Line *" class="rounded-lg border border-slate-300 px-3 py-2">
                <textarea name="message" rows="3" required placeholder="Notification message..." class="rounded-lg border border-slate-300 px-3 py-2"></textarea>
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white font-bold">Send / Queue Email</button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Delivery Logs ({{ $logs->count() }})</h2>
        @forelse ($logs as $log)
            <div class="border border-slate-200 rounded-xl p-3 flex items-center justify-between gap-3">
                <div>
                    <div class="font-bold text-slate-900">{{ $log->subject }} &rarr; {{ $log->recipient_email }}</div>
                    <div class="text-slate-500">{{ $log->body_excerpt }}</div>
                </div>
                <span class="px-2.5 py-1 rounded-full bg-slate-100 font-bold uppercase text-[11px]">{{ $log->status }}</span>
            </div>
        @empty
            <p class="text-slate-400">No email notifications logged yet.</p>
        @endforelse
    </div>
</div>
@endsection
