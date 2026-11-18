@extends('layouts.admin')

@section('title', 'Device Sessions')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Device Sessions</h1>
            <p class="mt-1 text-sm text-slate-500">Review active browser logins and revoke sessions that should no longer have access.</p>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach (['active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked'] as $key => $label)
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="text-sm text-slate-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($counts[$key]) }}</div>
            </div>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All statuses</option>
            @foreach (['active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <input name="role" value="{{ $filters['role'] ?? '' }}" placeholder="Role slug (e.g. student)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Filter</button>
        <a href="{{ route('admin.security.sessions.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">Reset</a>
    </form>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Device</th>
                    <th class="px-4 py-3">IP Address</th>
                    <th class="px-4 py-3">Last Seen</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($sessions as $session)
                    @php($isRevoked = $session->revoked_at !== null)
                    @php($isActive = !$isRevoked && $session->last_seen_at && $session->last_seen_at->gte(now()->subMinutes((int) config('session.lifetime', 120))))
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-slate-900">{{ $session->user?->name ?? 'Deleted user' }}</div>
                            <div class="text-xs text-slate-500">{{ $session->user?->email ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-800">{{ $session->device_name ?: 'Unknown device' }}</div>
                            <div class="max-w-xs truncate text-xs text-slate-500" title="{{ $session->user_agent }}">{{ $session->user_agent }}</div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $session->ip_address ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $session->last_seen_at?->format('d M Y, h:i A') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($isRevoked)
                                <span class="rounded-full bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">Revoked</span>
                            @elseif ($isActive)
                                <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">Active</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">Expired</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if (!$isRevoked)
                                <form method="POST" action="{{ route('admin.security.sessions.revoke', $session) }}" onsubmit="return confirm('Revoke this login session?')">
                                    @csrf
                                    @method('PATCH')
                                    <button class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Revoke</button>
                                </form>
                            @else
                                <span class="text-xs text-slate-400">{{ $session->revoked_at?->format('d M Y, h:i A') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No device sessions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $sessions->links() }}</div>
</div>
@endsection
