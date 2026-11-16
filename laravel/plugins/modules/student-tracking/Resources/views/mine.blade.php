@extends('layouts.student')
@section('title', 'My Learning Activity — AEC LMS')
@section('content')
<div class="max-w-5xl mx-auto space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">My Learning Activity</h1>
        <p class="text-sm text-slate-500 mt-1">Your own LMS learning history.</p>
    </div>
    <div class="bg-white border rounded-xl p-5">
        @forelse ($events as $event)
            <div class="py-3 border-b last:border-0 flex flex-wrap justify-between gap-2">
                <div>
                    <p class="font-semibold">{{ str_replace('_', ' ', ucfirst($event->event_type)) }}</p>
                    <p class="text-sm text-slate-500">{{ $event->metadata['course_title'] ?? $event->metadata['lesson_title'] ?? 'LMS activity' }}</p>
                </div>
                <time class="text-sm text-slate-500">{{ $event->occurred_at?->format('d M Y, h:i A') }}</time>
            </div>
        @empty
            <p class="text-slate-500">No learning activity has been recorded yet.</p>
        @endforelse
        <div class="mt-4">{{ $events->links() }}</div>
    </div>
</div>
@endsection
