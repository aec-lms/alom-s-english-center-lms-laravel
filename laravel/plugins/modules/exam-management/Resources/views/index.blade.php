@extends('layouts.admin')
@section('title', 'Exam & Question Bank — AEC LMS')
@section('content')
<div class="space-y-6 max-w-6xl">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Exams &amp; Question Bank</h1>
            <p class="text-xs text-slate-500 mt-0.5">Create, edit, publish, add MCQ questions, or delete exams independently without breaking LMS data.</p>
        </div>
        <a href="{{ route('public.exams') }}" target="_blank" class="px-4 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-50">
            Preview Public Exams &rarr;
        </a>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Create New Exam</h2>
        <form method="POST" action="{{ route('plugins.exams.store') }}" class="grid sm:grid-cols-4 gap-3 text-xs">
            @csrf
            <input name="title" required placeholder="Exam Title (e.g. IELTS Mock 02) *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2">
            <select name="course_id" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="">Standalone / General Exam</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}">{{ $course->title }}</option>
                @endforeach
            </select>
            <div class="grid grid-cols-2 gap-2">
                <input name="duration_minutes" type="number" min="5" value="45" required placeholder="Minutes" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="pass_marks" type="number" min="1" value="20" required placeholder="Pass" class="rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div class="sm:col-span-4 flex items-center justify-between">
                <label class="inline-flex items-center gap-2 font-semibold">
                    <input type="checkbox" name="is_published" value="1" checked> Publish immediately on /exams
                </label>
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold">Create Exam</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">All Exams ({{ $exams->total() }})</h2>
        <div class="space-y-3">
            @forelse ($exams as $exam)
                <div class="border border-slate-200 rounded-xl p-4 space-y-3 text-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <div class="font-bold text-sm text-slate-900">{{ $exam->title }}</div>
                            <div class="text-slate-500 font-mono">
                                {{ $exam->duration_minutes }} min &middot; Pass: {{ $exam->pass_marks }} &middot; Questions: {{ $exam->questions_count }} &middot; Attempts: {{ $exam->attempts_count }} &middot;
                                <span class="{{ $exam->is_published ? 'text-emerald-700 font-bold' : 'text-amber-700 font-bold' }}">{{ $exam->is_published ? 'Published' : 'Draft' }}</span>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('plugins.exams.destroy', $exam) }}" onsubmit="return confirm('Delete this exam?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold hover:bg-rose-50">Delete</button>
                        </form>
                    </div>

                    <details class="pt-2 border-t border-slate-100">
                        <summary class="cursor-pointer font-semibold text-[#0284C7]">Edit Exam Settings &amp; Add MCQ Question</summary>
                        <div class="mt-3 space-y-4">
                            <form method="POST" action="{{ route('plugins.exams.update', $exam) }}" class="grid sm:grid-cols-4 gap-2 bg-slate-50 p-3 rounded-xl">
                                @csrf
                                @method('PUT')
                                <input name="title" required value="{{ $exam->title }}" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <input name="duration_minutes" type="number" min="5" value="{{ $exam->duration_minutes }}" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <input name="pass_marks" type="number" min="1" value="{{ $exam->pass_marks }}" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <div class="sm:col-span-4 flex items-center justify-between">
                                    <label class="inline-flex items-center gap-2 font-semibold">
                                        <input type="checkbox" name="is_published" value="1" @checked($exam->is_published)> Published
                                    </label>
                                    <button type="submit" class="px-4 py-1.5 rounded-lg bg-slate-900 text-white font-bold">Update Exam</button>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('plugins.exams.questions.store', $exam) }}" class="grid sm:grid-cols-2 gap-2 bg-slate-50 p-3 rounded-xl">
                                @csrf
                                <input name="prompt" required placeholder="MCQ Question Prompt *" class="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <input name="options[]" required placeholder="Option 0 *" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <input name="options[]" required placeholder="Option 1 *" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <input name="options[]" required placeholder="Option 2 *" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <input name="options[]" required placeholder="Option 3 *" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <input name="correct_option_index" type="number" min="0" max="3" value="0" required placeholder="Correct Option (0-3)" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                <div class="flex items-center gap-2">
                                    <input name="marks" type="number" min="1" value="1" required placeholder="Marks" class="w-24 rounded-lg border border-slate-300 px-3 py-2 bg-white">
                                    <button type="submit" class="px-4 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Add Question</button>
                                </div>
                            </form>
                        </div>
                    </details>
                </div>
            @empty
                <p class="text-xs text-slate-400">No exams created yet.</p>
            @endforelse
        </div>
        {{ $exams->links() }}
    </div>
</div>
@endsection
