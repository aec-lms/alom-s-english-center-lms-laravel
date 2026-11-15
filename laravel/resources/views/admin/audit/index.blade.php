@extends('layouts.admin')
@section('title', 'Audit Logs — AEC LMS')
@section('content')
<div class="space-y-4">
    <h1 class="text-2xl font-extrabold text-slate-900">Security Audit Logs</h1>
    <div class="bg-white rounded-2xl border border-slate-200 p-5 overflow-x-auto">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Action</th><th>Entity</th><th>Actor</th><th>IP</th><th>Time</th></tr></thead>
            <tbody class="divide-y">
                @foreach ($logs as $log)
                    <tr>
                        <td class="py-2 font-mono">{{ $log->action }}</td>
                        <td>{{ $log->entity_type }}:{{ $log->entity_id }}</td>
                        <td>{{ $log->user?->email ?? 'system' }}</td>
                        <td>{{ $log->ip_address }}</td>
                        <td>{{ $log->created_at }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
