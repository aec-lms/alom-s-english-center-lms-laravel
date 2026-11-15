@extends('layouts.admin')
@section('title', 'Notification Center — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Notification Center</h1>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">User ID</th><th>Channel</th><th>Title</th><th>Status</th><th>Created</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($notifications as $n)
                    <tr>
                        <td class="py-2">{{ $n->user_id }}</td>
                        <td class="uppercase">{{ $n->channel }}</td>
                        <td>{{ $n->title }}</td>
                        <td class="uppercase font-semibold">{{ $n->status }}</td>
                        <td>{{ $n->created_at?->toDateTimeString() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-slate-400">No notifications recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
