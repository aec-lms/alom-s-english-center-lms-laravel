@extends('layouts.admin')
@section('title', 'Batch Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Study Batch &amp; Cohort Management</h1>
        <p class="text-xs text-slate-500 mt-0.5">Manage active batches, seat capacities, classrooms, and schedules.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Create Study Batch</h2>
        <form method="POST" action="{{ route('plugins.batches.store') }}" class="grid sm:grid-cols-3 gap-3 text-xs">
            @csrf
            <input name="batch_code" required placeholder="Batch Code (e.g. IELTS-M1) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
            <input name="name" required placeholder="Batch Title *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="course_title" placeholder="Linked Course Name" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="schedule" required placeholder="Schedule (e.g. Sun, Tue 10 AM) *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="room_number" placeholder="Room / Online Link" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="max_capacity" type="number" min="1" value="30" required class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="start_date" type="date" class="rounded-lg border border-slate-300 px-3 py-2">
            <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="ongoing">Ongoing</option>
                <option value="upcoming">Upcoming</option>
                <option value="completed">Completed</option>
            </select>
            <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Create Batch</button>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">All Batches ({{ $batches->count() }})</h2>
        @forelse ($batches as $batch)
            <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-3">
                <div>
                    <div class="font-bold text-sm text-slate-900">{{ $batch->name }} <span class="text-xs font-mono text-sky-700">[{{ $batch->batch_code }}]</span></div>
                    <div class="text-slate-500">{{ $batch->schedule }} &middot; Capacity {{ $batch->enrolled_count }}/{{ $batch->max_capacity }} &middot; <span class="uppercase font-semibold">{{ $batch->status }}</span></div>
                </div>
                <details class="text-xs">
                    <summary class="cursor-pointer text-sky-700 font-bold">Edit</summary>
                    <form method="POST" action="{{ route('plugins.batches.update', $batch) }}" class="grid sm:grid-cols-2 gap-2 mt-2">
                        @csrf @method('PUT')
                        <input name="batch_code" value="{{ $batch->batch_code }}" required class="rounded border px-2 py-1">
                        <input name="name" value="{{ $batch->name }}" required class="rounded border px-2 py-1">
                        <input name="course_title" value="{{ $batch->course_title }}" class="rounded border px-2 py-1">
                        <input name="schedule" value="{{ $batch->schedule }}" required class="rounded border px-2 py-1">
                        <input name="room_number" value="{{ $batch->room_number }}" class="rounded border px-2 py-1">
                        <input name="max_capacity" type="number" min="1" max="500" value="{{ $batch->max_capacity }}" required class="rounded border px-2 py-1">
                        <input name="start_date" type="date" value="{{ optional($batch->start_date)->format('Y-m-d') }}" class="rounded border px-2 py-1">
                        <select name="status" class="rounded border px-2 py-1">
                            @foreach (['upcoming', 'ongoing', 'completed'] as $status)
                                <option value="{{ $status }}" @selected($batch->status === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                        <button class="rounded bg-sky-700 text-white px-3 py-1 font-bold">Save changes</button>
                    </form>
                </details>
                <form method="POST" action="{{ route('plugins.batches.destroy', $batch) }}" onsubmit="return confirm('Remove batch?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold">Remove</button>
                </form>
            </div>
        @empty
            <p class="text-slate-400">No batches created yet.</p>
        @endforelse
    </div>
</div>
@endsection
