@extends('layouts.admin')
@section('title', 'Gmail & Email Marketing — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 text-[11px] font-bold">Independent Email Marketing Plugin</div>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Gmail & Email Marketing Studio</h1>
        <p class="text-xs text-slate-500">Connect your Gmail SMTP / Workspace App Password and send promotional or announcement emails to targeted LMS groups.</p>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <form method="POST" action="{{ route('plugins.gmail.settings') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">1. Gmail SMTP / Workspace Sender</h2>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Gmail Address *</label>
                <input type="email" name="gmail_address" required value="{{ $config['gmail_address'] }}" placeholder="academy@gmail.com" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Sender Display Name</label>
                <input name="sender_name" value="{{ $config['sender_name'] }}" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">SMTP Host</label>
                    <input name="smtp_host" value="{{ $config['smtp_host'] }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
                </div>
                <div>
                    <label class="font-semibold text-slate-700 block mb-1">SMTP Port</label>
                    <input name="smtp_port" value="{{ $config['smtp_port'] }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
                </div>
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Gmail App Password (Encrypted) @if($config['has_secret']) <span class="text-emerald-700">· Configured</span> @endif</label>
                <input type="password" name="app_password" placeholder="16-character Gmail App Password" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold">Save Gmail Credentials</button>
        </form>

        <form method="POST" action="{{ route('plugins.gmail.send') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">2. Launch Email Campaign</h2>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Email Subject *</label>
                <input name="subject" required placeholder="New IELTS Intensive Batch Scholarship Offer" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Target Audience</label>
                <select name="audience" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    <option value="students">All Students</option>
                    <option value="teachers">All Teachers</option>
                    <option value="guardians">All Guardians</option>
                    <option value="all_users">All LMS Users</option>
                    <option value="custom">Custom Email List</option>
                </select>
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Custom Emails (comma-separated, if custom)</label>
                <input name="custom_emails" placeholder="student1@gmail.com, student2@gmail.com" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Email Body (HTML supported) *</label>
                <textarea name="body_html" rows="4" required placeholder="<h2>Special Admission Notice</h2><p>Enroll before Friday for 25% discount.</p>" class="w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Send Email Campaign</button>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
        <h2 class="text-sm font-bold text-slate-900">Email Marketing History</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="text-slate-500 border-b"><tr><th class="py-2">Subject</th><th>Audience</th><th>Recipients</th><th>Status</th><th></th></tr></thead>
                <tbody class="divide-y">
                    @forelse ($campaigns as $c)
                        <tr>
                            <td class="py-2.5 font-semibold">{{ $c->subject }}</td>
                            <td class="uppercase">{{ $c->audience }}</td>
                            <td>{{ $c->recipient_count }}</td>
                            <td><span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold">{{ $c->status }}</span></td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('plugins.gmail.destroy', $c) }}">
                                    @csrf @method('DELETE')
                                    <button class="text-rose-600 font-semibold">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-400">No email campaigns sent yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
