@extends('layouts.app')

@section('title', __('Learning Paths — Administration'))

@section('content')
<div class="mx-auto max-w-7xl space-y-6 px-4 py-8">
    <header>
        <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">{{ __('Academic Management') }}</p>
        <h1 class="mt-1 text-3xl font-extrabold text-slate-900">{{ __('Learning Path Administration') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Build ordered learning journeys. Learners must complete each course before the next step unlocks.') }}</p>
    </header>
    @if (session('status')) <div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div> @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-6">
        <h2 class="text-lg font-bold text-slate-900">{{ __('Create a learning path') }}</h2>
        <form method="POST" action="{{ route('plugins.learning-paths.admin.store') }}" class="mt-4 grid gap-4 md:grid-cols-2">
            @csrf
            <label class="text-sm font-medium">{{ __('Path title') }}<input name="title" required maxlength="180" class="mt-1 w-full rounded-lg border-slate-300" value="{{ old('title') }}"></label>
            <label class="text-sm font-medium">{{ __('Status') }}<select name="status" class="mt-1 w-full rounded-lg border-slate-300"><option value="draft">{{ __('Draft') }}</option><option value="published">{{ __('Published') }}</option></select></label>
            <label class="text-sm font-medium md:col-span-2">{{ __('Description') }}<textarea name="description" rows="2" maxlength="5000" class="mt-1 w-full rounded-lg border-slate-300">{{ old('description') }}</textarea></label>
            @unless (auth()->user()->hasRole('branch_manager'))
                <label class="text-sm font-medium">{{ __('Branch (optional)') }}<select name="branch_id" class="mt-1 w-full rounded-lg border-slate-300"><option value="">{{ __('All branches') }}</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            @endunless
<div class="md:col-span-2" data-learning-path-steps>
                <p class="mb-2 text-sm font-medium">{{ __('Course sequence (use ↑ and ↓ to set the order)') }}</p>
                <div class="space-y-3" data-course-step-list>
                    @foreach ([1, 2] as $stepNumber)
                        <div class="flex items-end gap-2" data-course-step>
                            <label class="flex-1 text-sm">{{ __('Step :number', ['number' => $stepNumber]) }}<select name="course_ids[]" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">{{ __('Choose a course…') }}</option>@foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->title }} · #{{ $course->id }}</option>@endforeach</select></label>
                            <div class="flex gap-1"><button type="button" data-move-up class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Move step up') }}">↑</button><button type="button" data-move-down class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Move step down') }}">↓</button><button type="button" data-remove-step class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Remove step') }}">×</button></div>
                        </div>
                    @endforeach
                </div>
                <template data-course-step-template>
                    <div class="flex items-end gap-2" data-course-step>
                        <label class="flex-1 text-sm">{{ __('Step') }} <span data-step-number>3</span><select name="course_ids[]" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">{{ __('Choose a course…') }}</option>@foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->title }} · #{{ $course->id }}</option>@endforeach</select></label>
                        <div class="flex gap-1"><button type="button" data-move-up class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Move step up') }}">↑</button><button type="button" data-move-down class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Move step down') }}">↓</button><button type="button" data-remove-step class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Remove step') }}">×</button></div>
                    </div>
                </template>
                <button type="button" data-add-step class="mt-3 rounded-lg border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700">{{ __('+ Add course step') }}</button>
            </div>
            <div class="md:col-span-2"><button class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white">{{ __('Create path') }}</button></div>
        </form>
    </section>

    <section class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">{{ __('Existing paths') }}</h2>
        @forelse ($paths as $path)
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h3 class="text-lg font-bold">{{ $path->title }}</h3><p class="text-sm text-slate-500">{{ $path->slug }} · {{ __(':count learners', ['count' => $path->learner_count]) }} · {{ __('Branch') }} {{ $path->branch_id ?? __('All branches') }}</p></div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase">{{ $path->status }}</span>
                </div>
                <ol class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($path->courses as $course)<li class="rounded-lg bg-slate-50 p-3 text-sm"><span class="mr-2 font-bold text-indigo-600">{{ $course->position }}.</span>{{ $course->course_title }}</li>@endforeach
                </ol>
                <form method="POST" action="{{ route('plugins.learning-paths.admin.status', $path->id) }}" class="mt-4 flex flex-wrap items-center gap-2">
                    @csrf @method('PATCH')
                    <select name="status" class="rounded-lg border-slate-300 text-sm"><option value="draft" @selected($path->status==='draft')>{{ __('Draft') }}</option><option value="published" @selected($path->status==='published')>{{ __('Published') }}</option><option value="archived" @selected($path->status==='archived')>Archived</option></select>
                    <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">{{ __('Update status') }}</button>
                </form>
                <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold text-indigo-700">{{ __('Edit path details') }}</summary>
                    <form method="POST" action="{{ route('plugins.learning-paths.admin.update', $path->id) }}" class="mt-3 grid gap-3 md:grid-cols-2">
                        @csrf @method('PUT')
                        <label class="text-sm">{{ __('Title') }}<input name="title" required maxlength="180" value="{{ $path->title }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
                        <label class="text-sm">{{ __('Status') }}<select name="status" class="mt-1 w-full rounded-lg border-slate-300"><option value="draft" @selected($path->status==='draft')>{{ __('Draft') }}</option><option value="published" @selected($path->status==='published')>{{ __('Published') }}</option><option value="archived" @selected($path->status==='archived')>Archived</option></select></label>
                        <label class="text-sm md:col-span-2">{{ __('Description') }}<textarea name="description" rows="2" class="mt-1 w-full rounded-lg border-slate-300">{{ $path->description }}</textarea></label>
                        @unless (auth()->user()->hasRole('branch_manager'))
                            <label class="text-sm">Branch<select name="branch_id" class="mt-1 w-full rounded-lg border-slate-300"><option value="">{{ __('All branches') }}</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}" @selected((int) $path->branch_id === (int) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                        @endunless
                        <div class="md:col-span-2" data-learning-path-steps>
                            <p class="mb-2 text-sm font-medium">{{ __('Course sequence (curriculum is locked once a learner joins)') }}</p>
                            <div class="space-y-3" data-course-step-list>
                                @foreach ($path->courses as $step)
                                    <div class="flex items-end gap-2" data-course-step>
                                        <label class="flex-1 text-sm">{{ __('Step :number', ['number' => $step->position]) }}<select name="course_ids[]" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">{{ __('Choose a course…') }}</option>@foreach ($courses as $course)<option value="{{ $course->id }}" @selected((int) $step->course_id === (int) $course->id)>{{ $course->title }} · #{{ $course->id }}</option>@endforeach</select></label>
                                        <div class="flex gap-1"><button type="button" data-move-up class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Move step up') }}">↑</button><button type="button" data-move-down class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Move step down') }}">↓</button><button type="button" data-remove-step class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Remove step') }}">×</button></div>
                                    </div>
                                @endforeach
                            </div>
                            <template data-course-step-template>
                                <div class="flex items-end gap-2" data-course-step>
                                    <label class="flex-1 text-sm">{{ __('Step') }} <span data-step-number>{{ $path->courses->count() + 1 }}</span><select name="course_ids[]" required class="mt-1 w-full rounded-lg border-slate-300"><option value="">{{ __('Choose a course…') }}</option>@foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->title }} · #{{ $course->id }}</option>@endforeach</select></label>
                                    <div class="flex gap-1"><button type="button" data-move-up class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Move step up') }}">↑</button><button type="button" data-move-down class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Move step down') }}">↓</button><button type="button" data-remove-step class="rounded border px-2 py-2 text-sm" aria-label="{{ __('Remove step') }}">×</button></div>
                                </div>
                            </template>
                            <button type="button" data-add-step class="mt-3 rounded-lg border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700">{{ __('+ Add course step') }}</button>
                        </div>
                        <div class="md:col-span-2"><button class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700">{{ __('Save changes') }}</button></div>
                    </form>
                </details>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed p-8 text-center text-sm text-slate-500">{{ __('No learning paths created yet.') }}</div>
        @endforelse
        {{ $paths->links() }}
    </section>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-learning-path-steps]').forEach((container) => {
        const list = container.querySelector('[data-course-step-list]');
        const template = container.querySelector('[data-course-step-template]');
        const add = container.querySelector('[data-add-step]');
        const renumber = () => list.querySelectorAll('[data-course-step]').forEach((row, index) => {
            const number = row.querySelector('[data-step-number]');
            if (number) number.textContent = String(index + 1);
            const label = row.querySelector('label');
            if (label && !number) label.childNodes[0].textContent = @json(__('Step')) + ' ' + (index + 1);
        });
        add?.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            list.appendChild(fragment);
            renumber();
        });
        list.addEventListener('click', (event) => {
            const row = event.target.closest('[data-course-step]');
            if (!row) return;
            if (event.target.closest('[data-move-up]') && row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
            } else if (event.target.closest('[data-move-down]') && row.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row);
            } else if (event.target.closest('[data-remove-step]') && list.querySelectorAll('[data-course-step]').length > 2) {
                row.remove();
            }
            renumber();
        });
        renumber();
    });
});
</script>
@endsection
