@extends('layouts.admin')
@section('title', 'Manual Student Admin — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Student Management (Manual Add, Edit, Recycle Bin)</h1>
        <p class="text-xs text-slate-500">Enroll students manually, update branch/contact/status, reset passwords, or move a student to the Admin Recycle Bin while preserving history.</p>
    </div>

    <form method="POST" action="/admin/students" class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        @csrf
        <h2 class="text-sm font-bold text-slate-900">Add Student Manually</h2>
        <div class="grid sm:grid-cols-3 gap-3">
            <input name="name" required placeholder="Full Name *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input type="email" name="email" required placeholder="Email Address *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="phone" placeholder="Phone Number" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="student_code" placeholder="Student Code (auto if blank)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
            <input type="password" name="password" required minlength="8" placeholder="Password (min 8 chars) *" class="rounded-lg border border-slate-300 px-3 py-2">
            <select name="branch_id" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="">No Specific Branch (Online / Global)</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->code }})</option>
                @endforeach
            </select>
        </div>
        <button class="px-4 py-2 rounded-lg bg-[#0057FF] hover:bg-blue-700 text-white font-bold">Enroll Student</button>
    </form>

    <div class="space-y-3">
        @forelse ($students as $student)
            <div class="bg-white rounded-2xl border border-slate-200 p-4 text-xs space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded">{{ $student->student_code }}</span>
                        <strong class="text-sm text-slate-900">{{ $student->user?->name }}</strong>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $student->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $student->status }}</span>
                    </div>
                    <form method="POST" action="/admin/students/{{ $student->id }}" onsubmit="return confirm('Move student {{ $student->student_code }} to the Admin Recycle Bin? You can restore this record later.')">
                        @csrf @method('DELETE')
                        <button class="px-3 py-1 rounded-lg border border-rose-200 text-rose-700 font-semibold hover:bg-rose-50">Move to Recycle Bin</button>
                    </form>
                </div>

                <form method="POST" action="/admin/students/{{ $student->id }}" class="grid sm:grid-cols-2 lg:grid-cols-8 gap-2 pt-2 border-t border-slate-100">
                    @csrf @method('PUT')
                    <input name="student_code" value="{{ $student->student_code }}" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono">
                    <input name="name" value="{{ $student->user?->name }}" required class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                    <input type="email" name="email" value="{{ $student->user?->email }}" required class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                    <input name="phone" value="{{ $student->user?->phone }}" placeholder="Phone" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                    <select name="status" class="rounded-lg border border-slate-300 px-2.5 py-1.5 bg-white">
                        <option value="active" {{ $student->status === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ $student->status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        <option value="graduated" {{ $student->status === 'graduated' ? 'selected' : '' }}>Graduated</option>
                    </select>
                    <select name="branch_id" class="rounded-lg border border-slate-300 px-2.5 py-1.5 bg-white">
                        <option value="">No Specific Branch (Online / Global)</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" {{ (int) $student->branch_id === (int) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="New password (leave blank to keep)" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                    <button class="rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-semibold px-3 py-1.5">Update</button>
                </form>
            </div>
        @empty
            <div class="bg-white rounded-2xl border border-slate-200 p-6 text-xs text-slate-400">No students enrolled yet.</div>
        @endforelse
    </div>
</div>
@endsection
