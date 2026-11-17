@extends('layouts.app')
@section('title', 'My Dashboard — AEC LMS')
@section('content')
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="max-w-6xl mx-auto px-4 py-10 space-y-8">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-sky-700">{{ ucwords(str_replace('_', ' ', $role)) }} Portal</p>
                <h1 class="text-3xl font-extrabold text-slate-900 mt-1">Welcome, {{ $user->name }}</h1>
                <p class="text-sm text-slate-500 mt-1">{{ $user->email }}</p>
            </div>
            @if (in_array($role, ['student', 'guardian']) && Route::has('plugins.attendance.portal'))
                <a href="{{ route('plugins.attendance.portal') }}" class="rounded-lg border border-sky-200 px-4 py-2.5 text-sm font-bold text-sky-700">My Attendance</a>
            @endif
            @if ($role === 'student' && Route::has('plugins.enrollments.my'))
                <a href="{{ route('plugins.enrollments.my') }}" class="rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-bold text-white">My Enrollments</a>
                @if (Route::has('public.exams'))
                    <a href="{{ route('public.exams') }}" class="rounded-lg border border-indigo-200 px-4 py-2.5 text-sm font-bold text-indigo-700">Available Exams</a>
                @endif
            @elseif (in_array($role, ['branch_manager', 'accountant']) && Route::has('plugins.enrollments.index'))
                <a href="{{ route('plugins.enrollments.index') }}" class="rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-bold text-white">Course Enrollments</a>
            @endif
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        <section class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @forelse ($metrics as $label => $value)
                <div class="bg-white rounded-xl border border-slate-200 p-4">
                    <div class="text-xs text-slate-500 capitalize">{{ str_replace('_', ' ', $label) }}</div>
                    <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ $value }}</div>
                </div>
            @empty
                <div class="col-span-full text-sm text-slate-500">Your portal information will appear here when your profile and modules are configured.</div>
            @endforelse
        </section>

        @if ($role === 'student')
            @if (!$student)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Your student profile has not been created yet. Please contact the admissions office.</div>
            @endif
            <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
                <h2 class="font-extrabold text-slate-900">My Courses</h2>
                @forelse ($enrollments as $enrollment)
                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
                        <div><div class="font-semibold text-sm">{{ $enrollment->title }}</div><div class="text-xs text-slate-500">৳{{ number_format((float) $enrollment->price, 2) }}</div></div>
                        <span class="text-xs font-bold uppercase {{ $enrollment->enrollment_status === 'active' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $enrollment->enrollment_status }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No course enrollments yet.</p>
                @endforelse
            </section>
            <section class="grid md:grid-cols-2 gap-4">
                <div class="bg-white rounded-2xl border border-slate-200 p-5">
                    <h2 class="font-extrabold mb-3">Fee Invoices</h2>
                    @forelse ($invoices as $invoice)
                        <div class="border-t py-2 text-sm flex justify-between gap-3"><span>{{ $invoice->invoice_number }}</span><span>{{ $invoice->currency }} {{ number_format((float) $invoice->net_amount, 2) }} · {{ $invoice->status }}</span></div>
                    @empty
                        <p class="text-sm text-slate-500">No invoices.</p>
                    @endforelse
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 p-5">
                    <h2 class="font-extrabold mb-3">Recent Exam Results</h2>
                    @forelse ($attempts as $attempt)
                        <div class="border-t py-2 text-sm flex justify-between gap-3"><span>{{ $attempt->exam_title }}</span><span>{{ $attempt->score_obtained }}/{{ $attempt->total_marks }}</span></div>
                    @empty
                        <p class="text-sm text-slate-500">No exam attempts.</p>
                    @endforelse
                </div>
            </section>
        @elseif ($role === 'teacher')
            <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
                <h2 class="font-extrabold">My Assigned Courses</h2>
                @forelse ($courses as $course)
                    <div class="border-t pt-3 text-sm"><a class="font-semibold text-sky-700" href="{{ route('public.courses.show', $course->slug) }}">{{ $course->title }}</a></div>
                @empty
                    <p class="text-sm text-slate-500">No courses are assigned to your teacher profile yet.</p>
                @endforelse
            </section>
            <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
                <h2 class="font-extrabold">Live Classes</h2>
                @forelse ($liveClasses as $liveClass)
                    <div class="border-t pt-3 text-sm flex justify-between gap-3"><span>{{ $liveClass->title }}</span><span>{{ $liveClass->starts_at }} · {{ $liveClass->status }}</span></div>
                @empty
                    <p class="text-sm text-slate-500">No live classes scheduled.</p>
                @endforelse
            </section>
        @elseif ($role === 'guardian')
            <section class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
                <h2 class="font-extrabold">Linked Students</h2>
                @forelse ($children as $child)
                    <div class="border-t pt-3 text-sm"><div class="font-semibold">{{ $child->name }}</div><div class="text-slate-500">{{ $child->student_code }} · {{ $child->email }}</div></div>
                @empty
                    <p class="text-sm text-slate-500">No student profiles are linked to your guardian account.</p>
                @endforelse
            </section>
            <section class="bg-white rounded-2xl border border-slate-200 p-5">
                <h2 class="font-extrabold mb-3">Linked Student Invoices</h2>
                @forelse ($invoices as $invoice)
                    <div class="border-t py-2 text-sm flex justify-between gap-3"><span>{{ $invoice->invoice_number }}</span><span>{{ $invoice->currency }} {{ number_format((float) $invoice->net_amount, 2) }} · {{ $invoice->status }}</span></div>
                @empty
                    <p class="text-sm text-slate-500">No invoices.</p>
                @endforelse
            </section>
        @elseif ($role === 'branch_manager')
            <div class="rounded-xl bg-white border border-slate-200 p-5 text-sm text-slate-600">All counts and enrollment records shown here are restricted to your assigned branch.</div>
        @elseif ($role === 'accountant')
            <section class="bg-white rounded-2xl border border-slate-200 p-5">
                <h2 class="font-extrabold mb-3">Recent Invoices</h2>
                @forelse ($invoices as $invoice)
                    <div class="border-t py-2 text-sm flex justify-between gap-3"><span>{{ $invoice->invoice_number }}</span><span>{{ $invoice->currency }} {{ number_format((float) $invoice->net_amount, 2) }} · {{ $invoice->status }}</span></div>
                @empty
                    <p class="text-sm text-slate-500">No invoices found.</p>
                @endforelse
            </section>
        @endif

        @if (isset($notifications) && $notifications->isNotEmpty())
            <section class="bg-white rounded-2xl border border-slate-200 p-5">
                <h2 class="font-extrabold mb-3">My Notifications</h2>
                @foreach ($notifications as $notification)
                    <article class="border-t py-3">
                        <h3 class="font-semibold text-sm">{{ $notification->title }}</h3>
                        <p class="text-xs text-slate-600 mt-1">{{ $notification->message }}</p>
                        <p class="text-[11px] text-slate-400 mt-1">{{ $notification->created_at }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        @if ($notices->isNotEmpty())
            <section class="bg-white rounded-2xl border border-slate-200 p-5">
                <h2 class="font-extrabold mb-3">Notices</h2>
                @foreach ($notices as $notice)
                    <article class="border-t py-3"><h3 class="font-semibold text-sm">{{ $notice->title }}</h3><p class="text-xs text-slate-600 mt-1">{{ strip_tags($notice->body) }}</p></article>
                @endforeach
            </section>
        @endif
    </main>
</div>
@endsection
