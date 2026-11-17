@extends('layouts.admin')
@section('title', 'Dashboard — AEC LMS')
@section('content')
@php
    $widgetLabels = [
        'users' => 'Registered Accounts',
        'plugins' => 'Plugin Overview',
        'backups' => 'Encrypted Backups',
        'audit_trail' => 'Security Audit Trail',
        'system_info' => 'System Information',
    ];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Core System Overview</h1>
            <p class="text-xs text-slate-500">Your dashboard layout is saved per administrator account.</p>
        </div>
        <details class="relative">
            <summary class="cursor-pointer rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm">Customize Dashboard</summary>
            <form method="POST" action="{{ route('admin.dashboard.widgets') }}" class="absolute right-0 z-20 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">
                @csrf
                <h2 class="mb-3 text-sm font-bold text-slate-900">Show dashboard widgets</h2>
                <div class="space-y-3">
                    @foreach ($widgetLabels as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="widgets[]" value="{{ $key }}" @checked(in_array($key, $widgets, true)) class="rounded border-slate-300">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-4 flex items-center justify-between gap-2">
                    <button type="button" onclick="this.form.querySelectorAll('input[type=checkbox]').forEach((item) => item.checked = true)" class="text-xs font-semibold text-slate-500">Reset all</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white">Save layout</button>
                </div>
                <p class="mt-2 text-[11px] text-slate-400">Choose any combination. Preferences apply only to your account.</p>
            </form>
        </details>
    </div>

    @unless($setupCompleted)
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5" data-setup-reminder>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="font-bold text-amber-950">আপনার LMS সেটআপ অসম্পূর্ণ</h2><p class="mt-1 text-sm text-amber-800">প্রতিষ্ঠানের তথ্য, লোগো, শিক্ষাবর্ষ ও পেমেন্ট পদ্ধতি সম্পন্ন করুন।</p></div>
                <a href="{{ route('admin.setup.index') }}" class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-bold text-white">Setup Wizard চালু করুন</a>
            </div>
        </section>
    @endunless

    @if (in_array('users', $widgets, true) || in_array('plugins', $widgets, true) || in_array('backups', $widgets, true))
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if (in_array('users', $widgets, true))
                <div data-dashboard-widget="users" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-xs text-slate-500">Registered Accounts</div>
                    <div class="mt-1 text-2xl font-extrabold">{{ $metrics['users'] }}</div>
                </div>
            @endif
            @if (in_array('plugins', $widgets, true))
                <div data-dashboard-widget="plugins" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-xs text-slate-500">Discovered Plugins</div>
                    <div class="mt-1 text-2xl font-extrabold">{{ $metrics['plugins_total'] }}</div>
                </div>
                <div data-dashboard-widget="plugins" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-xs text-slate-500">Active Plugins</div>
                    <div class="mt-1 text-2xl font-extrabold text-emerald-700">{{ $metrics['plugins_enabled'] }}</div>
                </div>
            @endif
            @if (in_array('backups', $widgets, true))
                <div data-dashboard-widget="backups" class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="text-xs text-slate-500">Encrypted Backups</div>
                    <div class="mt-1 text-2xl font-extrabold">{{ $metrics['backups'] }}</div>
                </div>
            @endif
        </div>
    @endif

    @if (in_array('system_info', $widgets, true))
        <section data-dashboard-widget="system_info" class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-bold text-slate-900">System Information</h2>
            <div class="grid gap-3 text-sm sm:grid-cols-2">
                <div><span class="text-slate-500">Core version:</span> <strong>{{ $metrics['core_version'] }}</strong></div>
                <div><span class="text-slate-500">PHP runtime:</span> <strong>{{ $metrics['php_version'] }}</strong></div>
            </div>
        </section>
    @endif

    @if (in_array('audit_trail', $widgets, true))
        <section data-dashboard-widget="audit_trail" class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-bold text-slate-900">Recent Security Audit Trail</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b text-slate-500">
                        <tr><th class="py-2">Action</th><th>Entity</th><th>Actor</th><th>Time</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($recentAudits as $log)
                            <tr>
                                <td class="py-2 font-mono">{{ $log->action }}</td>
                                <td>{{ $log->entity_type }}:{{ $log->entity_id }}</td>
                                <td>{{ $log->user?->email ?? 'system' }}</td>
                                <td>{{ $log->created_at }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-400">No audit events recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection
