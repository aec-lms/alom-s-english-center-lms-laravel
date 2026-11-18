@extends('layouts.admin')
@section('title', 'Board Question Solver Settings')
@section('content')
<div class="mx-auto max-w-4xl space-y-5">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-extrabold text-slate-900">Board Question Solver</h1><p class="mt-1 text-sm text-slate-500">Uses the encrypted AEC AI Assistant provider and shared daily token budget. Uploaded photos are processed in memory, not stored.</p></div>
        <a href="{{ route('admin.board-solver.history') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold">Student activity</a>
    </header>
    @if (session('status'))<div role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('admin.board-solver.settings.update') }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
        @csrf
        @method('PUT')
        <label class="flex items-center gap-3 text-sm font-semibold"><input type="checkbox" name="enabled" value="1" @checked($settings['enabled'])> Enable Board Question Solver for students</label>
        <label class="flex items-center gap-3 text-sm font-semibold"><input type="checkbox" name="allow_image_questions" value="1" @checked($settings['allow_image_questions'])> Allow JPG, PNG and WebP question photos</label>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-semibold">Maximum question length
                <input type="number" name="max_question_length" min="200" max="12000" required value="{{ $settings['max_question_length'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold">Maximum image size (KB)
                <input type="number" name="max_image_kb" min="256" max="5120" required value="{{ $settings['max_image_kb'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold">Daily requests per student
                <input type="number" name="daily_request_limit" min="1" max="50" required value="{{ $settings['daily_request_limit'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold">Default answer language
                <select name="default_language" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="bn" @selected($settings['default_language'] === 'bn')>বাংলা</option>
                    <option value="en" @selected($settings['default_language'] === 'en')>English</option>
                </select>
            </label>
        </div>
        <p class="text-xs text-slate-500">The per-student request cap is also bounded by the global AEC AI Assistant daily request and token budgets.</p>
        <button type="submit" class="rounded-lg bg-sky-700 px-5 py-2.5 text-sm font-bold text-white">Save settings</button>
    </form>
</div>
@endsection
