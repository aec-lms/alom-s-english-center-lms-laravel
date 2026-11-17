@extends('layouts.app')
@section('title', 'My Enrollments — AEC LMS')
@section('content')
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="max-w-5xl mx-auto px-4 py-10 space-y-6">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">My Enrollments</h1>
            <p class="text-sm text-slate-500 mt-1">Your course access, enrollment requests and fee invoices.</p>
        </div>
        @if (session('status'))
            <div class="rounded-lg bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif
        <div class="space-y-3">
            @forelse ($enrollments as $enrollment)
                <article class="bg-white rounded-xl border border-slate-200 p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h2 class="font-bold text-slate-900">{{ $enrollment->course?->title ?? 'Course unavailable' }}</h2>
                        <p class="text-xs text-slate-500 mt-1">Enrolled {{ $enrollment->created_at?->toDateString() }} · {{ strtoupper($enrollment->status) }}</p>
                        @if ($feesIntegrationAvailable && $enrollment->invoice)
                            <p class="text-sm mt-2">Invoice {{ $enrollment->invoice->invoice_number }} · {{ $enrollment->invoice->currency }} {{ number_format((float) $enrollment->invoice->net_amount, 2) }} · {{ strtoupper($enrollment->invoice->status) }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($enrollment->status === 'active' && Route::has('plugins.lessons.course'))
                            <a href="{{ route('plugins.lessons.course', $enrollment->course_id) }}" class="px-4 py-2 rounded-lg bg-sky-600 text-white text-xs font-bold">Start Learning</a>
                        @endif
                        <div class="text-sm font-bold {{ $enrollment->status === 'active' ? 'text-emerald-700' : 'text-amber-700' }}">
                            {{ $enrollment->status === 'active' ? 'Course access active' : ($enrollment->status === 'pending' ? 'Awaiting payment / approval' : ucfirst($enrollment->status)) }}
                        </div>
                        @if ($enrollment->status === 'active')
                            @foreach (($enrollment->course?->liveClasses?->whereIn('status', ['upcoming', 'live']) ?? collect()) as $liveClass)
                                <div class="mt-3 rounded-lg border border-sky-200 bg-sky-50 p-3">
                                    <p class="text-xs font-bold text-slate-900">{{ $liveClass->title }}</p>
                                    <p class="text-xs text-slate-600 mt-1">{{ $liveClass->starts_at }} · {{ strtoupper($liveClass->platform) }} · {{ $liveClass->capacity }} LMS seats</p>
                                    <form method="POST" action="{{ route('plugins.live-classes.join', $liveClass) }}" class="mt-2">
                                        @csrf
                                        <button type="submit" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-bold text-white hover:bg-sky-700">Join Live Class</button>
                                    </form>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </article>
            @empty
                <div class="bg-white border border-dashed border-slate-300 rounded-xl p-10 text-center text-sm text-slate-500">
                    You have no course enrollments yet. Browse the published courses to request enrollment.
                    <div class="mt-4"><a href="{{ route('public.courses') }}" class="inline-flex px-4 py-2 rounded-lg bg-sky-600 text-white font-bold">Browse Courses</a></div>
                </div>
            @endforelse
        </div>
        {{ $enrollments->links() }}
    </main>
</div>
@endsection
