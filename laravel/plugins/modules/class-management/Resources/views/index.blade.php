@extends('layouts.admin')
@section('title', 'Class Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Class &amp; Routine Management</h1>
        <p class="text-xs text-slate-500 mt-0.5">Schedule academic classes, sections, classrooms, and faculty routines.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Add Class Schedule</h2>
        <form method="POST" action="{{ route('plugins.classes.store') }}" class="grid sm:grid-cols-3 gap-3 text-xs">
            @csrf
            <input name="code" required placeholder="Class Code (e.g. IELTS-C1) *" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
            <input name="name" required placeholder="Class Title *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="section" required value="Section A" placeholder="Section *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="subject" required placeholder="Subject / Track *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="instructor_name" placeholder="Assigned Teacher" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="room_number" placeholder="Room / Zoom Hall" class="rounded-lg border border-slate-300 px-3 py-2">
            <select name="day_of_week" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day)
                    <option value="{{ $day }}">{{ $day }}</option>
                @endforeach
            </select>
            <input name="start_time" required value="10:00" placeholder="Start Time" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="end_time" required value="11:30" placeholder="End Time" class="rounded-lg border border-slate-300 px-3 py-2">
            <div class="sm:col-span-3 text-right">
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Add Class</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Scheduled Classes ({{ $classes->count() }})</h2>
        @forelse ($classes as $cls)
            <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-3">
                <div>
                    <div class="font-bold text-sm text-slate-900">{{ $cls->name }} &mdash; {{ $cls->section }}</div>
                    <div class="text-slate-500 font-mono">{{ $cls->code }} &middot; {{ $cls->subject }} &middot; {{ $cls->day_of_week }} {{ $cls->start_time }}-{{ $cls->end_time }} &middot; {{ $cls->room_number ?: 'Online' }}</div>
                </div>
                <form method="POST" action="{{ route('plugins.classes.destroy', $cls) }}" onsubmit="return confirm('Delete class?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold">Remove</button>
                </form>
            </div>
        @empty
            <p class="text-slate-400">No classes scheduled yet.</p>
        @endforelse
    </div>
</div>
@endsection
