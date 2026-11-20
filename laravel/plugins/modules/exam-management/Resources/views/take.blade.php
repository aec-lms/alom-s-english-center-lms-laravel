@extends('layouts.app')
@section('title', $exam->title . ' — Exam')
@section('content')
<style>
    @media (max-width: 639px) {
        header[data-header-style] > div:first-child {
            gap: .5rem;
            padding-inline: .75rem;
        }
        header[data-header-style] > div:first-child > a:first-child {
            flex: 1 1 auto;
            min-width: 0;
        }
        header[data-header-style] > div:first-child > a:first-child > span {
            min-width: 0;
            max-width: 6.5rem;
        }
        header[data-header-style] > div:first-child > a:first-child > span > span:first-child {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        header[data-header-style] > div:first-child > a:first-child > span > span:last-child {
            display: none;
        }
        header[data-header-style] > div:first-child > div:last-child {
            gap: .25rem;
        }
        header[data-header-style] > div:first-child > div:last-child > a {
            max-width: 5.5rem;
            padding-inline: .5rem;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    }
</style>
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
        <header class="bg-white border border-slate-200 rounded-2xl p-5">
            <a href="{{ route('portal.dashboard') }}" class="text-sm font-semibold text-sky-700">← Dashboard</a>
            <a href="{{ route('plugins.exams.history', $exam) }}" class="ml-3 text-sm font-semibold text-sky-700">My attempt history</a>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-3">{{ $exam->title }}</h1>
            <p class="text-sm text-slate-500 mt-2">{{ $exam->duration_minutes }} minutes · {{ $questions->count() }} questions · Pass marks: {{ $exam->pass_marks }}</p>
            <p class="text-xs text-slate-500 mt-2">Choose one answer for each question. Your score and time limit are enforced securely by the server.</p>
            <p class="text-sm font-bold text-rose-700 mt-2">Time remaining: <span id="exam-countdown" data-deadline="{{ $deadline->toIso8601String() }}">--:--</span></p>
        </header>

        @if($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 p-4 text-sm">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('plugins.exams.attempt', $exam) }}" data-autosave-url="{{ route('plugins.exams.autosave', $exam) }}" data-attempt-id="{{ $attempt->id }}" class="space-y-4" onsubmit="return confirm('Submit your answers now? You cannot change them after submission.')">
            @csrf
            <input type="hidden" name="attempt_id" value="{{ $attempt->id }}">
            @foreach($questions as $index => $question)
                <fieldset class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4" aria-labelledby="exam-question-{{ $question->id }}">
                    <legend class="sr-only">Question {{ $index + 1 }}</legend>
                    <div class="flex items-start justify-between gap-3">
                        <h2 id="exam-question-{{ $question->id }}" class="font-bold text-slate-900">{{ $index + 1 }}. {{ $question->prompt }}</h2>
                        <span class="text-xs text-slate-500 whitespace-nowrap">{{ number_format((float) $question->marks, 2) }} marks</span>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2">
                        @foreach((array) $question->options as $optionIndex => $option)
                            <label class="flex min-h-12 items-start gap-3 rounded-xl border border-slate-200 p-3 hover:border-sky-400 focus-within:border-sky-600 focus-within:ring-2 focus-within:ring-sky-600 focus-within:ring-offset-2 cursor-pointer">
                                <input type="radio" name="answers[{{ $question->id }}]" value="{{ $optionIndex }}" class="mt-1 h-4 w-4 accent-sky-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700" @checked((string) data_get($attempt->submitted_answers ?? [], (string) $question->id) === (string) $optionIndex) required>
                                <span class="text-sm text-slate-700">{{ $option }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            <p id="exam-autosave-status" class="text-xs text-slate-500" role="status" aria-live="polite">Answers save automatically as you choose them.</p>
            <div class="flex justify-end">
                <button type="submit" class="rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold px-6 py-3">Submit Exam</button>
            </div>
        </form>
    </main>
    <script>
        (() => {
            const timer = document.getElementById("exam-countdown");
            if (timer) {
                const deadline = new Date(timer.dataset.deadline).getTime();
                const render = () => {
                    const seconds = Math.max(0, Math.floor((deadline - Date.now()) / 1000));
                    timer.textContent = String(Math.floor(seconds / 60)).padStart(2, "0") + ":" + String(seconds % 60).padStart(2, "0");
                    if (seconds <= 0) timer.textContent = "Time expired — submit to record your attempt";
                };
                render();
                window.setInterval(render, 1000);
            }
            const form = document.querySelector("form[data-autosave-url]");
            const status = document.getElementById("exam-autosave-status");
            if (!form || !status) return;
            const csrf = form.querySelector('input[name="_token"]')?.value;
            let queue = Promise.resolve();
            form.querySelectorAll('input[type="radio"][name^="answers["]').forEach((input) => {
                input.addEventListener("change", () => {
                    const match = input.name.match(/^answers\[(\d+)\]$/);
                    if (!match) return;
                    status.textContent = "Saving answer…";
                    queue = queue.then(() => fetch(form.dataset.autosaveUrl, {
                        method: "POST",
                        credentials: "same-origin",
                        headers: {
                            "Content-Type": "application/json",
                            "Accept": "application/json",
                            "X-CSRF-TOKEN": csrf || ""
                        },
                        body: JSON.stringify({
                            attempt_id: Number(form.dataset.attemptId),
                            answers: { [match[1]]: Number(input.value) }
                        })
                    })).then(async (response) => {
                        if (!response.ok) throw new Error("Autosave was not accepted");
                        status.textContent = "All selected answers saved.";
                    }).catch(() => {
                        status.textContent = "Autosave unavailable — your current selections remain in this form. Submit before time expires.";
                    });
                });
            });
        })();
    </script>
</div>
@endsection
