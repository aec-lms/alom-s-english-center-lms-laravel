@extends('layouts.admin')
@section('title', 'Board Question Solver Activity')
@section('content')
<div class="mx-auto max-w-7xl space-y-5">
    <h1 class="text-2xl font-extrabold text-slate-900">Board Question Solver Activity</h1>
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Student</th><th class="p-3">Subject</th><th class="p-3">Board / Year</th><th class="p-3">Question</th><th class="p-3">Mode</th><th class="p-3">Date</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($history as $entry)
                    <tr>
                        <td class="p-3">{{ $entry->user?->name ?? 'Deleted user' }}</td>
                        <td class="p-3">{{ $entry->subject }}</td>
                        <td class="p-3">{{ $entry->board ?: '—' }} / {{ $entry->exam_year ?: '—' }}</td>
                        <td class="max-w-md p-3"><span class="line-clamp-2">{{ $entry->question }}</span></td>
                        <td class="p-3">{{ $entry->mode }}</td>
                        <td class="whitespace-nowrap p-3">{{ $entry->created_at?->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-slate-400">No student activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $history->links() }}
</div>
@endsection
