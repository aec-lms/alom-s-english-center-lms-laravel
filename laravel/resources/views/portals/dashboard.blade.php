@extends('layouts.app')
@section('title', 'My Dashboard — AEC LMS')
@section('content')
<div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-sky-50/70">
    @include('public.partials.navbar')
    <main class="mx-auto max-w-7xl space-y-8 px-4 py-6 sm:px-6 sm:py-10 lg:px-8">
        <section class="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br {{ $role === 'student' ? 'from-sky-950 via-cyan-900 to-teal-800' : ($role === 'teacher' ? 'from-indigo-950 via-violet-900 to-blue-800' : ($role === 'guardian' ? 'from-fuchsia-950 via-purple-900 to-indigo-800' : ($role === 'branch_manager' ? 'from-amber-950 via-orange-900 to-rose-800' : 'from-slate-950 via-indigo-950 to-sky-900'))) }} px-6 py-8 text-white shadow-xl sm:px-9 sm:py-10"><div aria-hidden="true" class="pointer-events-none absolute -right-16 -top-24 h-72 w-72 rounded-full bg-sky-400/20 blur-3xl"></div><div aria-hidden="true" class="pointer-events-none absolute -bottom-28 left-1/3 h-64 w-64 rounded-full bg-violet-400/20 blur-3xl"></div><div class="relative flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-extrabold uppercase tracking-[.22em] text-sky-200">{{ ucwords(str_replace('_', ' ', $role)) }} Portal</p>
                <h1 class="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl">Welcome, {{ $user->display_name ?: $user->name }}</h1>
                <p class="mt-2 text-sm text-slate-300">{{ $user->email }}</p>
                <a href="{{ route('profile.settings.edit') }}" class="mt-3 inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-white/20 focus:outline-none focus:ring-4 focus:ring-white/30">
                    <span aria-hidden="true">✦</span> Customize my profile
                </a>
            </div>
            @if (in_array($role, ['student', 'guardian']) && Route::has('plugins.attendance.portal'))
                <a href="{{ route('plugins.attendance.portal') }}" class="rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-bold text-white backdrop-blur transition hover:bg-white/20">My Attendance</a>
            @endif
            @if ($role === 'student' && Route::has('plugins.enrollments.my'))
                <a href="{{ route('plugins.enrollments.my') }}" class="rounded-xl bg-white px-4 py-2.5 text-sm font-extrabold text-slate-900 shadow-lg transition hover:bg-sky-50">My Enrollments</a>
                @if (Route::has('course-commerce.cart'))
                    <a href="{{ route('course-commerce.cart') }}" class="rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-bold text-white backdrop-blur transition hover:bg-white/20">Course Cart</a>
                @endif
                @if (Route::has('course-commerce.vouchers.form'))
                    <a href="{{ route('course-commerce.vouchers.form') }}" class="rounded-xl border border-emerald-200/40 bg-emerald-400/15 px-4 py-2.5 text-sm font-bold text-emerald-100 backdrop-blur transition hover:bg-emerald-400/25">Gift Voucher</a>
                @endif
                @if (Route::has('public.exams'))
                    <a href="{{ route('public.exams') }}" class="rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-bold text-white backdrop-blur transition hover:bg-white/20">Available Exams</a>
                @endif
            @elseif (in_array($role, ['branch_manager', 'accountant']) && Route::has('plugins.enrollments.index'))
                <a href="{{ route('plugins.enrollments.index') }}" class="rounded-xl bg-white px-4 py-2.5 text-sm font-extrabold text-slate-900 shadow-lg transition hover:bg-sky-50">Course Enrollments</a>
            @endif
            @if ($role === 'student' && $boardQuestionSolverAvailable)
                <a href="{{ route('student.board-solver.index') }}" class="rounded-xl border border-violet-200/40 bg-violet-400/15 px-4 py-2.5 text-sm font-bold text-violet-100 backdrop-blur transition hover:bg-violet-400/25">{{ __('Board Question Solver') }}</a>
            @endif
        </div></section>

        @if (session('status'))
            <div class="rounded-lg bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        <section aria-label="Dashboard summary" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            @forelse ($metrics as $label => $value)
                <div class="group relative overflow-hidden rounded-2xl border border-white border-t-4 {{ $role === 'student' ? 'border-t-teal-500' : ($role === 'teacher' ? 'border-t-violet-500' : ($role === 'guardian' ? 'border-t-fuchsia-500' : ($role === 'branch_manager' ? 'border-t-orange-500' : 'border-t-sky-500'))) }} bg-white p-4 shadow-sm ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-0.5 hover:shadow-lg sm:p-5">
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                    <div class="mt-2 text-3xl font-black tracking-tight text-slate-900 sm:text-4xl">{{ $value }}</div>
                </div>
            @empty
                <div class="col-span-full text-sm text-slate-500">Your portal information will appear here when your profile and modules are configured.</div>
            @endforelse
        </section>

        @if ($role === 'student')
            @if (!$student)
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 shadow-sm">Your student profile has not been created yet. Please contact the admissions office.</div>
            @endif
            <section class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6 space-y-4">
                <h2 class="text-lg font-black tracking-tight text-slate-900">My Courses</h2>
                @forelse ($enrollments as $enrollment)
                    <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4 transition hover:border-sky-200 hover:bg-sky-50/40">
                        <div class="flex items-center justify-between gap-3">
                            <div><div class="font-semibold text-sm">{{ $enrollment->title }}</div><div class="text-xs text-slate-500">৳{{ number_format((float) $enrollment->price, 2) }}</div></div>
                            <span class="text-xs font-bold uppercase {{ $enrollment->enrollment_status === 'active' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $enrollment->enrollment_status }}</span>
                        </div>
                        @if (in_array($enrollment->enrollment_status, ['active', 'completed'], true))
                            @if ($enrollment->total_lessons > 0)
                                <div class="mt-3">
                                    <div class="mb-1 flex items-center justify-between gap-3 text-xs">
                                        <span class="font-semibold text-slate-600">{{ __('Learning progress') }}</span>
                                        <strong class="text-slate-900">{{ $enrollment->progress_percent }}%</strong>
                                    </div>
                                    <div role="progressbar"
                                         aria-label="{{ $enrollment->title }} {{ __('learning progress') }}"
                                         aria-valuemin="0" aria-valuemax="100"
                                         aria-valuenow="{{ $enrollment->progress_percent }}"
                                         aria-valuetext="{{ __(':completed of :total lessons completed', ['completed' => $enrollment->completed_lessons, 'total' => $enrollment->total_lessons]) }}"
                                         class="h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div data-progress-fill data-progress-target="{{ max(0, min(100, (int) $enrollment->progress_percent)) }}" class="h-full rounded-full bg-gradient-to-r from-sky-600 via-blue-600 to-cyan-500 shadow-[0_0_10px_rgba(14,165,233,.22)]"
                                             style="width: 0%;"></div>
                                    </div>
                                    <p class="mt-1 text-[11px] text-slate-500">{{ $enrollment->completed_lessons }} / {{ $enrollment->total_lessons }} {{ __('lessons completed') }}</p>
                                </div>
                            @else
                                <p class="mt-2 text-xs text-slate-500">{{ __('No published lessons are available in this course yet.') }}</p>
                            @endif
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No course enrollments yet.</p>
                @endforelse
            </section>
            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
                    <h2 class="mb-4 text-lg font-black tracking-tight text-slate-900">Fee Invoices</h2>
                    @forelse ($invoices as $invoice)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 py-3 text-sm"><span>{{ $invoice->invoice_number }}</span><span>{{ $invoice->currency }} {{ number_format((float) $invoice->net_amount, 2) }} · {{ $invoice->status }}</span></div>
                    @empty
                        <p class="text-sm text-slate-500">No invoices.</p>
                    @endforelse
                </div>
                <div class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
                    <h2 class="mb-4 text-lg font-black tracking-tight text-slate-900">Recent Exam Results</h2>
                    @forelse ($attempts as $attempt)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 py-3 text-sm"><span>{{ $attempt->exam_title }}</span><span>{{ $attempt->score_obtained }}/{{ $attempt->total_marks }}</span></div>
                    @empty
                        <p class="text-sm text-slate-500">No exam attempts.</p>
                    @endforelse
                </div>
            </section>
        @elseif ($role === 'teacher')
            <section class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6 space-y-4">
                <h2 class="text-lg font-black tracking-tight text-slate-900">My Assigned Courses</h2>
                @forelse ($courses as $course)
                    <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4 text-sm transition hover:border-sky-200 hover:bg-sky-50/40"><a class="font-semibold text-sky-700" href="{{ route('public.courses.show', $course->slug) }}">{{ $course->title }}</a></div>
                @empty
                    <p class="text-sm text-slate-500">No courses are assigned to your teacher profile yet.</p>
                @endforelse
            </section>
            <section class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6 space-y-4">
                <h2 class="text-lg font-black tracking-tight text-slate-900">Live Classes</h2>
                @forelse ($liveClasses as $liveClass)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50/70 px-4 py-3 text-sm"><span>{{ $liveClass->title }}</span><span>{{ $liveClass->starts_at }} · {{ $liveClass->status }}</span></div>
                @empty
                    <p class="text-sm text-slate-500">No live classes scheduled.</p>
                @endforelse
            </section>
        @elseif ($role === 'guardian')
            @if (Route::has('plugins.guardian-portal.dashboard'))
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-fuchsia-200 bg-fuchsia-50 p-4">
                    <div><h2 class="font-bold text-fuchsia-950">Full Guardian Portal</h2><p class="text-sm text-fuchsia-800">View each linked child’s courses, learning activity, attendance, exam results, invoices and notices.</p></div>
                    <a href="{{ route('plugins.guardian-portal.dashboard') }}" class="rounded-xl bg-fuchsia-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-fuchsia-800">Open Guardian Portal</a>
                </div>
            @endif
            <section class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6 space-y-4">
                <h2 class="text-lg font-black tracking-tight text-slate-900">Linked Students</h2>
                @forelse ($children as $child)
                    <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4 text-sm transition hover:border-sky-200 hover:bg-sky-50/40"><div class="font-semibold">{{ $child->name }}</div><div class="text-slate-500">{{ $child->student_code }} · {{ $child->email }}</div></div>
                @empty
                    <p class="text-sm text-slate-500">No student profiles are linked to your guardian account.</p>
                @endforelse
            </section>
            <section class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="mb-4 text-lg font-black tracking-tight text-slate-900">Linked Student Invoices</h2>
                @forelse ($invoices as $invoice)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 py-3 text-sm"><span>{{ $invoice->invoice_number }}</span><span>{{ $invoice->currency }} {{ number_format((float) $invoice->net_amount, 2) }} · {{ $invoice->status }}</span></div>
                @empty
                    <p class="text-sm text-slate-500">No invoices.</p>
                @endforelse
            </section>
        @elseif ($role === 'branch_manager')
            <section class="grid gap-4 lg:grid-cols-3" aria-label="Branch performance">
                <article class="rounded-3xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-wider text-emerald-700">Collected revenue</p>
                    <p class="mt-2 text-3xl font-black tracking-tight text-slate-900">৳{{ number_format((float) ($metrics['collected_revenue'] ?? 0), 2) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Recorded payments for this branch</p>
                </article>
                <article class="rounded-3xl border border-sky-100 bg-gradient-to-br from-sky-50 to-white p-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-wider text-sky-700">Today's attendance</p>
                    <p class="mt-2 text-3xl font-black tracking-tight text-slate-900">{{ (int) ($metrics['attendance_today'] ?? 0) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Attendance records in your branch today</p>
                </article>
                <article class="rounded-3xl border border-violet-100 bg-gradient-to-br from-violet-50 to-white p-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-wider text-violet-700">Present / late today</p>
                    <p class="mt-2 text-3xl font-black tracking-tight text-slate-900">{{ (int) ($metrics['present_today'] ?? 0) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Present and late attendance records</p>
                </article>
            </section>
            <div class="rounded-3xl border border-sky-200 bg-gradient-to-br from-sky-50 to-indigo-50 p-6 text-sm text-slate-700 shadow-sm">All counts, attendance records and invoice totals shown here are restricted to your assigned branch.</div>
        @elseif ($role === 'accountant')
            <section class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="mb-4 text-lg font-black tracking-tight text-slate-900">Recent Invoices</h2>
                @forelse ($invoices as $invoice)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 py-3 text-sm"><span>{{ $invoice->invoice_number }}</span><span>{{ $invoice->currency }} {{ number_format((float) $invoice->net_amount, 2) }} · {{ $invoice->status }}</span></div>
                @empty
                    <p class="text-sm text-slate-500">No invoices found.</p>
                @endforelse
            </section>
        @endif

        @if (isset($notifications) && $notifications->isNotEmpty())
            <section class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="mb-4 text-lg font-black tracking-tight text-slate-900">My Notifications</h2>
                @foreach ($notifications as $notification)
                    <article class="border-t border-slate-100 py-4 first:border-0">
                        <h3 class="font-semibold text-sm">{{ $notification->title }}</h3>
                        <p class="text-xs text-slate-600 mt-1">{{ $notification->message }}</p>
                        <p class="text-[11px] text-slate-400 mt-1">{{ $notification->created_at }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        @if ($notices->isNotEmpty())
            <section class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="mb-4 text-lg font-black tracking-tight text-slate-900">Notices</h2>
                @foreach ($notices as $notice)
                    <article class="border-t border-slate-100 py-4 first:border-0"><h3 class="font-semibold text-sm">{{ $notice->title }}</h3><p class="text-xs text-slate-600 mt-1">{{ strip_tags($notice->body) }}</p></article>
                @endforeach
            </section>
        @endif
    </main>
</div>

<style>
    [data-progress-fill] { width: 0; transition: width 1.15s cubic-bezier(.22,1,.36,1); position: relative; overflow: hidden; }
    [data-progress-fill]::after { content: ""; position: absolute; inset: 0; background: linear-gradient(105deg, transparent 20%, rgba(255,255,255,.42) 48%, transparent 76%); transform: translateX(-110%); animation: student-progress-sheen 2.4s ease-in-out .8s infinite; }
    @keyframes student-progress-sheen { 0%, 35% { transform: translateX(-110%); } 75%, 100% { transform: translateX(110%); } }
    @media (prefers-reduced-motion: reduce) { [data-progress-fill] { transition: none !important; } [data-progress-fill]::after { animation: none !important; } }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const bars = document.querySelectorAll('[data-progress-fill][data-progress-target]');
    const animateBar = (bar) => {
        const target = Math.max(0, Math.min(100, Number(bar.dataset.progressTarget) || 0));
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { bar.style.width = target + '%'; return; }
        requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.width = target + '%'; }));
    };
    if (!('IntersectionObserver' in window)) { bars.forEach(animateBar); return; }
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => { if (!entry.isIntersecting) return; animateBar(entry.target); observer.unobserve(entry.target); });
    }, { threshold: 0.2 });
    bars.forEach((bar) => observer.observe(bar));
});
</script>
@endsection
