@extends('layouts.admin')
@section('title', 'Certificate Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Certificate Management</h1>
        <p class="text-xs text-slate-500 mt-0.5">Issue tamper-evident digital certificates verifiable on the public /result portal.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-bold text-slate-900">Issue New Certificate</h2>
        <form method="POST" action="{{ route('plugins.certificates.store') }}" class="grid sm:grid-cols-3 gap-3 text-xs">
            @csrf
            <input name="student_name" required placeholder="Student Full Name *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="student_code" placeholder="Student ID (e.g. STU-101)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono">
            <input name="course_title" required placeholder="Course / Program Title *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="grade_or_band" required placeholder="Grade / Band Score (e.g. Band 8.0) *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="issued_at" type="date" required value="{{ now()->toDateString() }}" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="remarks" placeholder="Examiner Remarks (Optional)" class="rounded-lg border border-slate-300 px-3 py-2">
            <div class="sm:col-span-3 text-right">
                <button type="submit" class="px-5 py-2 rounded-lg bg-[#0284C7] text-white font-bold">Issue Certificate</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3 text-xs">
        <h2 class="text-sm font-bold text-slate-900">Issued Certificates ({{ $certificates->count() }})</h2>
        @forelse ($certificates as $cert)
            <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-3">
                <div>
                    <div class="font-bold text-sm text-slate-900">{{ $cert->student_name }} &mdash; {{ $cert->course_title }}</div>
                    <div class="text-slate-500 font-mono">{{ $cert->verification_code }} &middot; {{ $cert->grade_or_band }} &middot; Issued {{ $cert->issued_at?->toDateString() }} &middot; <strong class="uppercase">{{ $cert->status }}</strong></div>
                </div>
                @if ($cert->status === 'issued')
                    <form method="POST" action="{{ route('plugins.certificates.revoke', $cert) }}" onsubmit="return confirm('Revoke certificate?')">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 font-bold">Revoke</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-slate-400">No certificates issued yet.</p>
        @endforelse
    </div>
</div>
@endsection
