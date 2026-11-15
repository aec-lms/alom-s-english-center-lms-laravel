@extends('layouts.admin')
@section('title', 'Update Center — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Real LMS Universal Update System</h1>
        <p class="text-xs text-slate-500">Current Core Version: <strong>{{ $coreVersion }}</strong>. All Core &amp; Plugin updates undergo SHA-256 + HMAC signature verification, ZIP path-traversal screening, isolated staging extraction, database migration, and encrypted pre-update backup with one-click rollback.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-bold text-slate-900">Remote Release Channel</h2>
                <p class="text-[11px] text-slate-500">Configure the signed release feed URL for Core &amp; 39 Independent Plugins.</p>
            </div>
            <form method="POST" action="{{ route('admin.updates.check') }}">
                @csrf
                <button class="px-3.5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold">Check Update Feed Now</button>
            </form>
        </div>
        <form method="POST" action="{{ route('admin.updates.feed') }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <input name="feed_url" type="url" required value="{{ $feedUrl }}" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 font-mono">
            <button class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 font-bold text-slate-800">Save Feed URL</button>
        </form>
    </div>

    <form method="POST" action="{{ route('admin.updates.apply') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 text-xs">
        @csrf
        <h2 class="text-sm font-bold text-slate-900">Upload &amp; Apply Signed Core or Plugin ZIP Package</h2>
        <div class="grid sm:grid-cols-3 gap-4">
            <label class="block font-semibold">Signed ZIP Package
                <input type="file" name="package" required accept=".zip" class="mt-1 w-full">
            </label>
            <label class="block font-semibold">SHA-256 Checksum
                <input type="text" name="sha256" required maxlength="64" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            </label>
            <label class="block font-semibold">HMAC-SHA256 Signature
                <input type="text" name="signature" required maxlength="64" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono">
            </label>
        </div>
        <button class="px-4 py-2 rounded-lg bg-sky-600 hover:bg-sky-700 text-white font-bold">Stage, Verify &amp; Apply Update</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <h2 class="text-sm font-bold mb-3">Update Execution &amp; Rollback History</h2>
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Target</th><th>Version</th><th>Status</th><th>Backup Ref</th><th>Timestamp</th><th class="text-right">Action</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($logs as $log)
                    <tr>
                        <td class="py-2 font-semibold">{{ $log->target_type }}:{{ $log->target_slug }}</td>
                        <td>{{ $log->from_version ?? 'new' }} &rarr; {{ $log->to_version }}</td>
                        <td>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $log->status === 'applied' ? 'bg-emerald-50 text-emerald-700' : ($log->status === 'rolled_back' ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700') }}">
                                {{ $log->status }}
                            </span>
                        </td>
                        <td class="font-mono text-[11px]">{{ $log->backup_reference }}</td>
                        <td>{{ $log->created_at }}</td>
                        <td class="text-right">
                            @if ($log->backup_reference && $log->status === 'applied')
                                <form method="POST" action="{{ route('admin.updates.rollback', $log) }}" class="inline">
                                    @csrf
                                    <button class="px-2.5 py-1 rounded bg-amber-100 hover:bg-amber-200 text-amber-900 font-bold">Rollback</button>
                                </form>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-slate-400">No update history yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
