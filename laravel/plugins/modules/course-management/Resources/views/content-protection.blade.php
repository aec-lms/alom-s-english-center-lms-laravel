@extends('layouts.admin')
@section('title', 'Lesson Content Protection — AEC LMS')
@section('content')
<div class="max-w-6xl space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Lesson Content Protection</h1>
            <p class="text-sm text-slate-500 mt-1">Upload private lesson media and control student access and downloads.</p>
        </div>
        <a href="{{ route('plugins.courses.index') }}" class="px-4 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold">Back to Courses</a>
    </div>

    @if (session('status'))
        <div class="rounded-lg bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg bg-rose-50 text-rose-800 px-4 py-3 text-sm">{{ $errors->first() }}</div>
    @endif

    @forelse ($courses as $course)
        <section class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="font-bold text-slate-900">{{ $course->title }}</h2>
                <p class="text-xs text-slate-500">{{ $course->code }} · {{ $course->lessons->count() }} lessons</p>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($course->lessons as $lesson)
                    <div class="p-5 grid lg:grid-cols-2 gap-5">
                        <div>
                            <p class="text-xs font-bold text-sky-700">LESSON {{ $lesson->sort_order }}</p>
                            <h3 class="font-semibold text-slate-900 mt-1">{{ $lesson->title }}</h3>
                            @if ($lesson->media_path)
                                <p class="text-xs text-emerald-700 mt-2">Media uploaded · {{ $lesson->media_mime }}</p>
                            @else
                                <p class="text-xs text-amber-700 mt-2">No protected media uploaded.</p>
                            @endif
                        </div>
                        <div class="space-y-4">
                            <form method="POST" action="{{ route('plugins.courses.lessons.media.upload', $lesson->id) }}" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-2">
                                @csrf
                                <input type="file" name="media" required accept=".mp4,.webm,.mp3,.m4a,.pdf" class="min-w-0 flex-1 text-xs border border-slate-200 rounded-lg p-2">
                                <button class="px-4 py-2 rounded-lg bg-sky-600 text-white text-xs font-bold">Upload Protected Media</button>
                            </form>
                            <form method="POST" action="{{ route('plugins.courses.lessons.protection.update', $lesson->id) }}" class="flex flex-wrap items-center gap-x-5 gap-y-3 text-xs">
                                @csrf
                                @method('PATCH')
                                <label class="inline-flex items-center gap-2">
                                    <input type="hidden" name="protection_enabled" value="0">
                                    <input type="checkbox" name="protection_enabled" value="1" @checked($lesson->protection_enabled)>
                                    Protected Access
                                </label>
                                <label class="inline-flex items-center gap-2">
                                    <input type="hidden" name="is_downloadable" value="0">
                                    <input type="checkbox" name="is_downloadable" value="1" @checked($lesson->is_downloadable)>
                                    Allow Downloads
                                </label>
                                <button class="px-3 py-2 rounded-lg bg-slate-900 text-white font-bold">Save Policy</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="p-5 text-sm text-slate-500">No lessons have been added to this course.</p>
                @endforelse
            </div>
        </section>
    @empty
        <div class="bg-white border border-dashed border-slate-300 rounded-xl p-10 text-center text-sm text-slate-500">No courses found.</div>
    @endforelse
</div>
@endsection
