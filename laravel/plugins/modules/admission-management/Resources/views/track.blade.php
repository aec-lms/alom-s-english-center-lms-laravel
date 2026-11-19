@extends('layouts.app')
@section('title', 'Track Admission Application — AEC LMS')
@section('content')
<main class="mx-auto max-w-2xl px-4 py-12">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-5">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-sky-700">Applicant Portal</p>
            <h1 class="mt-2 text-2xl font-extrabold text-slate-900">Track your admission</h1>
            <p class="mt-2 text-sm text-slate-600">Enter your application tracking code and the email address used during submission.</p>
        </div>

        <form method="POST" action="{{ route('plugins.admissions.track_submit') }}" class="space-y-3">
            @csrf
            <label class="block text-sm font-semibold text-slate-700">Application tracking code
                <input name="application_code" required maxlength="40" value="{{ old('application_code') }}" placeholder="ADM-XXXXXXXXXX" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="block text-sm font-semibold text-slate-700">Applicant email
                <input name="email" type="email" required maxlength="160" value="{{ old('email') }}" placeholder="you@example.com" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <button type="submit" class="w-full rounded-lg bg-sky-700 px-5 py-3 font-bold text-white hover:bg-sky-800">Check Application Status</button>
        </form>

        @if ($searched && !$application)
            <p role="status" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">We could not find an application matching those details. Check the tracking code and email, then try again.</p>
        @endif

        @if ($application)
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3" role="status">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-bold text-slate-900">Application found</h2>
                    <span class="rounded-full bg-white px-3 py-1 text-xs font-bold uppercase text-sky-800">{{ $application->status }}</span>
                </div>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500">Tracking code</dt><dd class="font-mono font-semibold text-slate-900">{{ $application->application_code }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Applicant</dt><dd class="font-semibold text-slate-900">{{ $application->applicant_name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Course</dt><dd class="font-semibold text-slate-900">{{ $application->desired_course }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Submitted</dt><dd class="font-semibold text-slate-900">{{ $application->created_at?->format('d M Y') }}</dd></div>
                    @if ($application->interview_at)
                        <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Interview schedule</dt><dd class="font-semibold text-slate-900">{{ $application->interview_at->format('d M Y, h:i A') }}</dd></div>
                    @endif
                </dl>
                <p class="text-xs text-slate-500">For assistance, contact the admissions office and provide your tracking code.</p>
            </div>
        @endif
    </section>
</main>
@endsection
