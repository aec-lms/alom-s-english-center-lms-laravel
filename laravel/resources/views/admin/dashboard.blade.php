@extends('layouts.admin')
@section('title', 'Dashboard — AEC LMS')
@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Core System Overview</h1>
        <p class="text-xs text-slate-500">Core v{{ $metrics['core_version'] }} · PHP {{ $metrics['php_version'] }}</p>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 p-5">
            <div class="text-xs text-slate-500">Registered Accounts</div>
            <div class="text-2xl font-extrabold mt-1">{{ $metrics['users'] }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5">
            <div class="text-xs text-slate-500">Discovered Plugins</div>
            <div class="text-2xl font-extrabold mt-1">{{ $metrics['plugins_total'] }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5">
            <div class="text-xs text-slate-500">Active Plugins</div>
            <div class="text-2xl font-extrabold text-emerald-700 mt-1">{{ $metrics['plugins_enabled'] }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5">
            <div class="text-xs text-slate-500">Encrypted Backups</div>
            <div class="text-2xl font-extrabold mt-1">{{ $metrics['backups'] }}</div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-3">Recent Security Audit Trail</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left">
                <thead class="text-slate-500 border-b">
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
    </div>
</div>
@endsection
