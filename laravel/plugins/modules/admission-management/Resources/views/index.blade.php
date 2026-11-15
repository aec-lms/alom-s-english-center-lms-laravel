@extends('layouts.admin')
@section('title', 'Admission Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Admission Management</h1>
        <p class="text-xs text-slate-500 mt-0.5">Process new learner admission applications, assign preferred batches, and approve intake.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">New Admission Intake</h2>
        <form method="POST" action="{{ route('plugins.admissions.store') }}" class="grid sm:grid-cols-3 gap-3 text-xs">
            @csrf
            <input name="applicant_name" required placeholder="Applicant Full Name *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="email" type="email" required placeholder="Email Address *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="phone" required placeholder="Mobile Phone *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="desired_course" required placeholder="Desired Course (e.g. IELTS Masterclass) *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="preferred_batch" placeholder="Preferred Batch / Slot" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="guardian_phone" placeholder="Guardian Phone (Optional)" class="rounded-lg border border-slate-300 px-3 py-2">
            <div class="sm:col-span-3 text-right">
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Submit Application</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Applications ({{ $applications->count() }})</h2>
        @forelse ($applications as $app)
            <div class="border border-slate-200 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <div class="font-bold text-sm text-slate-900">{{ $app->applicant_name }} <span class="font-mono text-xs text-slate-500">({{ $app->application_code }})</span></div>
                    <div class="text-slate-500">{{ $app->desired_course }} &middot; {{ $app->phone }} &middot; {{ $app->email }} &middot; Status: <strong class="uppercase">{{ $app->status }}</strong></div>
                </div>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('plugins.admissions.review', $app) }}">
                        @csrf
                        <input type="hidden" name="status" value="approved">
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-bold">Approve</button>
                    </form>
                    <form method="POST" action="{{ route('plugins.admissions.review', $app) }}">
                        @csrf
                        <input type="hidden" name="status" value="rejected">
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold">Reject</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="text-slate-400">No admission applications recorded yet.</p>
        @endforelse
    </div>
</div>
@endsection
