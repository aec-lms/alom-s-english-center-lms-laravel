@extends('layouts.admin')
@section('title', 'SMS Marketing & Gateway — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <div class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold">Independent Communication Plugin</div>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-1">SMS Marketing & Alerts Gateway</h1>
        <p class="text-xs text-slate-500">Greenweb BD is the active sending adapter. SSLWireless, Twilio, and custom HTTP adapters are listed for future integration and will not send messages yet.</p>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <form method="POST" action="{{ route('plugins.sms.settings') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">1. Gateway Configuration</h2>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Provider</label>
                <select name="provider" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    <option value="greenweb" {{ $config['provider'] === 'greenweb' ? 'selected' : '' }}>Greenweb BD (active adapter)</option>
                    @if ($config['provider'] === 'sslwireless')
                        <option value="sslwireless" selected disabled>SSLWireless (integration pending)</option>
                    @endif
                    @if ($config['provider'] === 'twilio')
                        <option value="twilio" selected disabled>Twilio (integration pending)</option>
                    @endif
                    @if ($config['provider'] === 'custom')
                        <option value="custom" selected disabled>Custom HTTP (integration pending)</option>
                    @endif
                </select>
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Sender ID (manage approval in Greenweb portal)</label>
                <input name="sender_id" value="{{ $config['sender_id'] }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Custom API URL (if custom)</label>
                <input name="api_url" value="{{ $config['api_url'] }}" placeholder="https://sms.example.com/send" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">API Token (Encrypted) @if($config['has_token']) <span class="text-emerald-700">· Configured</span> @endif</label>
                <input type="password" name="api_token" placeholder="Enter new token to update" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold">Save SMS Gateway</button>
        </form>

        <form method="POST" action="{{ route('plugins.sms.send') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
            @csrf
            <h2 class="text-sm font-bold text-slate-900">2. Send Bulk / Targeted SMS Campaign</h2>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Campaign Title *</label>
                <input name="title" required placeholder="New Batch Admission Alert" class="w-full rounded-xl border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Target Audience</label>
                <select name="audience" class="w-full rounded-xl border border-slate-300 px-3 py-2 bg-white">
                    <option value="students">All Students</option>
                    <option value="teachers">All Teachers</option>
                    <option value="guardians">All Guardians</option>
                    <option value="all_users">All Registered Users</option>
                    <option value="custom">Custom Phone Numbers</option>
                </select>
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">Custom Phone Numbers (comma-separated, if custom)</label>
                <input name="custom_numbers" placeholder="+88017XXXXXXXX, +88018XXXXXXXX" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono">
            </div>
            <div>
                <label class="font-semibold text-slate-700 block mb-1">SMS Text *</label>
                <textarea name="message" rows="3" required maxlength="640" placeholder="Write concise SMS alert..." class="w-full rounded-xl border border-slate-300 px-3 py-2"></textarea>
            </div>
            <button class="px-5 py-2.5 rounded-xl bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Send SMS Campaign</button>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
        <h2 class="text-sm font-bold text-slate-900">SMS Campaign History</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="text-slate-500 border-b"><tr><th class="py-2">Campaign</th><th>Audience</th><th>Recipients</th><th>Provider</th><th>Status</th><th></th></tr></thead>
                <tbody class="divide-y">
                    @forelse ($campaigns as $c)
                        <tr>
                            <td class="py-2.5 font-semibold">{{ $c->title }}</td>
                            <td class="uppercase">{{ $c->audience }}</td>
                            <td>{{ $c->recipient_count }}</td>
                            <td>{{ $c->provider }}</td>
                            <td><span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-bold">{{ $c->status }}</span></td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('plugins.sms.destroy', $c) }}">
                                    @csrf @method('DELETE')
                                    <button class="text-rose-600 font-semibold">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-slate-400">No SMS campaigns sent yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
