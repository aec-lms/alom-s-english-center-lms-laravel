@extends('layouts.admin')
@section('title', 'Notice Board — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Notice Board & Announcements</h1>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Title</th><th>Audience</th><th>Branch</th><th>Pinned</th><th>Published</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($notices as $notice)
                    <tr>
                        <td class="py-2 font-semibold">{{ $notice->title }}</td>
                        <td class="uppercase">{{ $notice->audience_role }}</td>
                        <td>{{ $notice->branch_id ?? 'Global' }}</td>
                        <td>{{ $notice->is_pinned ? 'Yes' : 'No' }}</td>
                        <td>{{ $notice->published_at?->toDateTimeString() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-slate-400">No notices published yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
