@extends('layouts.admin')
@section('title', 'Admission Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-7xl">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Admission Management</h1>
            <p class="text-sm text-slate-500 mt-1">Manage applications from submission through screening, interview, decision and intake.</p>
            <a href="{{ route('plugins.admissions.track') }}" target="_blank" rel="noopener" class="mt-2 inline-flex text-xs font-semibold text-sky-700 hover:underline">Open applicant tracking page</a>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            @foreach (['pending' => 'Pending', 'screening' => 'Screening', 'shortlisted' => 'Shortlisted', 'waitlisted' => 'Waitlisted', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                <span class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-slate-600">{{ $label }} <strong class="ml-1 text-slate-900">{{ (int) ($statusCounts[$key] ?? 0) }}</strong></span>
            @endforeach
        </div>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <div>
            <h2 class="text-sm font-bold text-slate-900">Register Walk-in Applicant</h2>
            <p class="text-xs text-slate-500 mt-1">New entries start as pending and receive a unique application tracking code.</p>
        </div>
        <form method="POST" action="{{ route('plugins.admissions.store') }}" class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3 text-sm">
            @csrf
            <input name="applicant_name" required maxlength="160" placeholder="Applicant full name *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="email" type="email" required maxlength="160" placeholder="Email address *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="phone" required maxlength="40" placeholder="Mobile phone *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="guardian_phone" maxlength="40" placeholder="Guardian phone" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="desired_course" required maxlength="160" placeholder="Desired course *" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="preferred_batch" maxlength="80" placeholder="Preferred batch / slot" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="previous_education" maxlength="160" placeholder="Previous education" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="date_of_birth" type="date" max="{{ now()->subDay()->toDateString() }}" aria-label="Date of birth" class="rounded-lg border border-slate-300 px-3 py-2">
            @if ($branches->isNotEmpty() && !auth()->user()->hasRole('branch_manager'))
                <select name="branch_id" class="rounded-lg border border-slate-300 px-3 py-2">
                    <option value="">Select branch</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
            <textarea name="address" rows="2" maxlength="2000" placeholder="Address (optional)" class="rounded-lg border border-slate-300 px-3 py-2 sm:col-span-2"></textarea>
            <div class="sm:col-span-2 xl:col-span-4 flex justify-end">
                <button type="submit" class="rounded-lg bg-sky-700 px-5 py-2.5 font-bold text-white hover:bg-sky-800">Create Application</button>
            </div>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="text-sm font-bold text-slate-900">Application Pipeline</h2>
                <p class="text-xs text-slate-500 mt-1">{{ $applications->total() }} matching applications · 20 per page</p>
            </div>
            <form method="GET" action="{{ route('plugins.admissions.index') }}" class="flex flex-wrap gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, code, phone, email…" class="min-w-48 rounded-lg border border-slate-300 px-3 py-2 text-xs">
                <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-xs">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white">Filter</button>
                @if (request()->hasAny(['q', 'status']))
                    <a href="{{ route('plugins.admissions.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs">Clear</a>
                @endif
            </form>
        </div>

        <div class="space-y-3">
            @forelse ($applications as $app)
                <article class="rounded-xl border border-slate-200 p-4 space-y-3">
                    <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-bold text-slate-900">{{ $app->applicant_name }}</h3>
                                <span class="rounded-md bg-slate-100 px-2 py-1 font-mono text-[11px] text-slate-600">{{ $app->application_code }}</span>
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase {{ $app->status === 'approved' ? 'bg-emerald-50 text-emerald-700' : ($app->status === 'rejected' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ $app->status }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-600">{{ $app->desired_course }} · {{ $app->preferred_batch ?: 'Batch not selected' }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $app->phone }} · {{ $app->email }} @if ($app->guardian_phone) · Guardian: {{ $app->guardian_phone }} @endif</p>
                            <p class="mt-1 text-[11px] text-slate-400">Source: {{ ucfirst(str_replace('_', ' ', $app->application_source ?? 'website')) }} · Applied {{ $app->created_at?->format('d M Y, h:i A') }}</p>
                            @if ($app->reviewer_notes)
                                <p class="mt-2 rounded-lg bg-slate-50 p-2 text-xs text-slate-600"><strong>Review note:</strong> {{ $app->reviewer_notes }}</p>
                            @endif
                        </div>
                        @if (!in_array($app->status, ['approved', 'rejected'], true))
                            <form method="POST" action="{{ route('plugins.admissions.review', $app) }}" class="grid sm:grid-cols-2 xl:grid-cols-1 gap-2 min-w-56">
                                @csrf
                                <select name="status" required class="rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                    <option value="">Move to…</option>
                                    @foreach ($statuses as $status)
                                        @if (in_array($status, $transitions[$app->status] ?? [], true))
                                            <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <input type="datetime-local" name="interview_at" value="{{ $app->interview_at?->format('Y-m-d\TH:i') }}" aria-label="Interview schedule" class="rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <select name="assigned_to" aria-label="Assign application to staff" class="rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                    <option value="">Unassigned</option>
                                    @foreach ($staff as $member)
                                        <option value="{{ $member->id }}" @selected((int) $app->assigned_to === (int) $member->id)>{{ $member->name }}</option>
                                    @endforeach
                                </select>
                                <textarea name="reviewer_notes" rows="2" maxlength="2000" placeholder="Reviewer notes (required when rejecting)" class="rounded-lg border border-slate-300 px-3 py-2 text-xs sm:col-span-2 xl:col-span-1"></textarea>
                                <button type="submit" class="rounded-lg bg-sky-700 px-4 py-2 text-xs font-bold text-white sm:col-span-2 xl:col-span-1">Save Review</button>
                            </form>
                        @endif
                    </div>

                    <div class="grid gap-3 border-t border-slate-100 pt-3 lg:grid-cols-[1fr_auto] lg:items-center">
                        <div class="space-y-2">
                            <form method="POST" enctype="multipart/form-data" action="{{ route('plugins.admissions.documents.store', $app) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <label class="text-xs font-semibold text-slate-600" for="admission-document-{{ $app->id }}">Applicant document</label>
                                <input id="admission-document-{{ $app->id }}" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required class="max-w-xs text-xs">
                                <button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Upload privately</button>
                                <span class="text-[11px] text-slate-400">PDF/JPG/PNG · max 10 MB</span>
                            </form>
                            @if ($app->documents->isNotEmpty())
                                <ul class="flex flex-wrap gap-2">
                                    @foreach ($app->documents as $document)
                                        <li><a href="{{ route('plugins.admissions.documents.download', [$app, $document]) }}" class="text-xs font-semibold text-sky-700 hover:underline">{{ $document->original_name }}</a><span class="ml-1 text-[10px] text-slate-400">({{ number_format($document->size_bytes / 1024, 0) }} KB)</span></li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        @if ($app->status === 'approved' && !$app->converted_student_id && $studentConversionAvailable)
                            <form method="POST" action="{{ route('plugins.admissions.convert_to_student', $app) }}" onsubmit="return confirm('Convert this approved applicant into a student account?')">
                                @csrf
                                <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2.5 text-xs font-bold text-white hover:bg-emerald-800">Convert to Student</button>
                            </form>
                        @elseif ($app->converted_student_id)
                            <span class="text-xs font-semibold text-emerald-700">Student profile #{{ $app->converted_student_id }} created</span>
                        @endif
                    </div>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-400">No applications match this view.</p>
            @endforelse
        </div>
        <div>{{ $applications->links() }}</div>
    </section>
</div>
@endsection
