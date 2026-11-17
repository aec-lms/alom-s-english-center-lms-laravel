@extends('layouts.admin')
@section('title', 'AEC LMS AI Assistant')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">AEC LMS AI Assistant</h1>
            <p class="text-xs text-slate-500 mt-1">AI assistance is managed by your LMS. Integration credentials remain encrypted on the server and are never exposed to client browsers.</p>
        </div>
        <a href="{{ route('admin.plugins.configure', 'role-ai-assistant') }}" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-xs font-semibold">Configure AI Integration & Roles</a>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="text-sm font-bold mb-3">Recent Assistant Activity</h2>
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">User ID</th><th>Role</th><th>Integration</th><th>Model</th><th>Prompt</th><th>Est. Tokens</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($logs as $log)
                    <tr>
                        <td class="py-2">{{ $log->user_id }}</td>
                        <td>{{ $log->role_slug }}</td>
                        <td>{{ $log->provider }}</td>
                        <td class="font-mono">{{ $log->model }}</td>
                        <td class="truncate max-w-xs">{{ $log->prompt_excerpt }}</td>
                        <td>{{ $log->tokens_estimated }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No assistant requests recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
