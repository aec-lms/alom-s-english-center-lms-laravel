@extends('layouts.admin')
@section('title', 'Recycle Bin — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-5">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Admin Recycle Bin</h1>
        <p class="text-xs text-slate-500 mt-1">Restore supported records without losing their linked academic history.</p>
    </div>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Students</h2>
            <span class="text-xs text-slate-500">{{ $students->total() }} item(s)</span>
        </div>
        @forelse ($students as $student)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $student->user?->name ?? 'Deleted account' }}</div>
                    <div class="text-xs text-slate-500">{{ $student->student_code }} · {{ $student->user?->email ?? 'No linked email' }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $student->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.students.restore', $student->id) }}" onsubmit="return confirm('Restore this student and their previous account access?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted students.</p>
        @endforelse
        <div>{{ $students->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Instructors</h2>
            <span class="text-xs text-slate-500">{{ $teachers->total() }} item(s)</span>
        </div>
        @forelse ($teachers as $teacher)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $teacher->user?->name ?? 'Deleted account' }}</div>
                    <div class="text-xs text-slate-500">{{ $teacher->employee_code }} · {{ $teacher->user?->email ?? 'No linked email' }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $teacher->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.teachers.restore', $teacher->id) }}" onsubmit="return confirm('Restore this instructor and their previous account access?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted instructors.</p>
        @endforelse
        <div>{{ $teachers->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Courses</h2>
            <span class="text-xs text-slate-500">{{ $courses->total() }} item(s)</span>
        </div>
        @forelse ($courses as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    <div class="text-xs text-slate-500">{{ $record->code }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.courses.restore', $record->id) }}" onsubmit="return confirm('Restore this course record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted courses.</p>
        @endforelse
        <div>{{ $courses->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Exams</h2>
            <span class="text-xs text-slate-500">{{ $exams->total() }} item(s)</span>
        </div>
        @forelse ($exams as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->title }}</div>
                    
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.exams.restore', $record->id) }}" onsubmit="return confirm('Restore this exam record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted exams.</p>
        @endforelse
        <div>{{ $exams->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Study Batches</h2>
            <span class="text-xs text-slate-500">{{ $batches->total() }} item(s)</span>
        </div>
        @forelse ($batches as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->name }}</div>
                    <div class="text-xs text-slate-500">{{ $record->batch_code }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.batches.restore', $record->id) }}" onsubmit="return confirm('Restore this study batche record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted study batches.</p>
        @endforelse
        <div>{{ $batches->links() }}</div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold text-slate-900">Deleted Academic Classes</h2>
            <span class="text-xs text-slate-500">{{ $classes->total() }} item(s)</span>
        </div>
        @forelse ($classes as $record)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-slate-900">{{ $record->name }}</div>
                    <div class="text-xs text-slate-500">{{ $record->code }}</div>
                    <div class="text-[11px] text-slate-400">Moved to trash: {{ $record->deleted_at?->format('Y-m-d H:i') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.recycle-bin.classes.restore', $record->id) }}" onsubmit="return confirm('Restore this academic classe record?')">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold">Restore</button>
                </form>
            </div>
        @empty
            <p class="border-t border-slate-100 pt-4 text-xs text-slate-400">No deleted academic classes.</p>
        @endforelse
        <div>{{ $classes->links() }}</div>
    </section>
</div>
@endsection
