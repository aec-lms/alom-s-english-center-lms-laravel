@extends('layouts.admin')
@section('title', 'Backup & Restore — AEC LMS')
@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Encrypted Backup & Restore</h1>
            <p class="text-xs text-slate-500">AES-256-CBC + HMAC-SHA256 encrypted database and private storage backups.</p>
        </div>
        <form method="POST" action="{{ route('admin.backups.store') }}">
            @csrf
            <input type="hidden" name="include_files" value="1">
            <button class="px-4 py-2 rounded-lg bg-sky-600 text-white text-xs font-bold">Create Encrypted Backup</button>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Archive</th><th>SHA-256</th><th>Size</th><th>Created</th><th>Action</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($records as $backup)
                    <tr>
                        <td class="py-2 font-mono">{{ $backup->filename }}</td>
                        <td class="font-mono">{{ substr($backup->checksum_sha256, 0, 16) }}…</td>
                        <td>{{ number_format($backup->size_bytes / 1024, 1) }} KB</td>
                        <td>{{ $backup->created_at }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.backups.restore', $backup) }}" onsubmit="return confirm('Restore database from {{ $backup->filename }}?')">
                                @csrf
                                <input type="hidden" name="confirm_restore" value="1">
                                <button class="px-3 py-1 rounded border border-amber-400 text-amber-800 font-semibold">Restore</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-slate-400">No backups created yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
