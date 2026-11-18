@extends('layouts.app')
@section('title', __('Board Question Solver'))
@section('content')
@include('public.partials.navbar')
<main class="mx-auto max-w-5xl space-y-5 px-4 py-8">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-sky-700">Learning Tools</p>
            <h1 class="mt-1 text-3xl font-extrabold text-slate-900">{{ __('Board Question Solver') }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ __('Practise SSC, HSC and board questions with worked explanations in Bangla or English.') }}</p>
        </div>
        <a href="{{ route('student.board-solver.history') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">{{ __('My solved questions') }}</a>
    </header>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <form id="board-solver-form" enctype="multipart/form-data" class="grid gap-4">
            @csrf
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm font-semibold text-slate-700">{{ __('Education Board') }}
                    <select name="board" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="">{{ __('Not specified') }}</option>
                        @foreach (['Dhaka','Chattogram','Rajshahi','Cumilla','Jashore','Barishal','Sylhet','Dinajpur','Mymensingh','Madrasah','Technical'] as $board)
                            <option value="{{ $board }}">{{ $board }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">{{ __('Exam year') }}
                    <input type="number" name="exam_year" min="1990" max="{{ now()->year + 1 }}" placeholder="{{ now()->year }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="text-sm font-semibold text-slate-700">{{ __('Class / Level') }}
                    <select name="class_level" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="">{{ __('Select') }}</option>
                        @foreach (['Class 6','Class 7','Class 8','Class 9','SSC','HSC','Alim','Dakhil','Vocational','Other'] as $level)
                            <option value="{{ $level }}">{{ $level }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">{{ __('Subject') }}
                    <input required name="subject" maxlength="100" placeholder="English, Bangla, Mathematics…" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="text-sm font-semibold text-slate-700">{{ __('Question type') }}
                    <select name="question_type" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="creative">{{ __('Creative / CQ') }}</option>
                        <option value="mcq">MCQ</option>
                        <option value="short">{{ __('Short question') }}</option>
                        <option value="grammar">{{ __('Grammar / Writing') }}</option>
                        <option value="math">{{ __('Mathematics') }}</option>
                        <option value="science">{{ __('Science') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">{{ __('Answer language') }}
                    <select name="language" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="bn" @selected($settings['default_language'] === 'bn')>বাংলা</option>
                        <option value="en" @selected($settings['default_language'] === 'en')>English</option>
                    </select>
                </label>
            </div>

            <label class="text-sm font-semibold text-slate-700">{{ __('Write / paste the question') }}
                <textarea name="question" rows="5" maxlength="{{ $settings['max_question_length'] }}" placeholder="এখানে প্রশ্ন লিখুন…" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"></textarea>
            </label>

            @if ($settings['allow_image_questions'])
                <label class="text-sm font-semibold text-slate-700">{{ __('Or upload a clear question photo (JPG, PNG, WebP; max :size MB)', ['size' => number_format($settings['max_image_kb'] / 1024, 1)]) }}
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </label>
            @endif

            <label class="text-sm font-semibold text-slate-700">{{ __('Solution mode') }}
                <select name="mode" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="full">{{ __('Full step-by-step solution') }}</option>
                    <option value="hints">{{ __('Hints first (practice mode)') }}</option>
                    <option value="explain">{{ __('Explain the concept') }}</option>
                </select>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button id="board-solver-submit" type="submit" class="rounded-xl bg-sky-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-sky-800">{{ __('Solve Question') }}</button>
                <p id="board-solver-status" class="text-sm text-slate-600" role="status" aria-live="polite"></p>
            </div>
        </form>
    </section>

    <section id="board-solver-answer" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" hidden>
        <h2 class="text-lg font-bold text-slate-900">{{ __('Solution') }}</h2>
        <div id="board-solver-answer-text" class="mt-3 whitespace-pre-wrap text-sm leading-7 text-slate-700"></div>
    </section>
</main>
<script>
(() => {
    const form = document.getElementById('board-solver-form');
    const button = document.getElementById('board-solver-submit');
    const status = document.getElementById('board-solver-status');
    const answer = document.getElementById('board-solver-answer');
    const answerText = document.getElementById('board-solver-answer-text');
    if (!form || !button || !status || !answer || !answerText) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        button.disabled = true;
        button.textContent = 'Solving…';
        status.textContent = 'Your question is being analysed. Please wait.';
        answer.hidden = true;

        try {
            const response = await fetch(@json(route('student.board-solver.solve')), {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to solve this question.');
            answerText.textContent = data.solution;
            answer.hidden = false;
            status.textContent = 'Solution ready. Review the steps and practise on your own.';
        } catch (error) {
            status.textContent = error.message || 'Unable to solve this question right now.';
        } finally {
            button.disabled = false;
            button.textContent = @json(__('Solve Question'));
        }
    });
})();
</script>
@endsection
