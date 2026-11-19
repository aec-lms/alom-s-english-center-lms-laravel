@extends('layouts.app')
@section('title', "Exams & Assessments — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 flex items-center justify-center px-4 py-12 sm:py-16" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        @guest
            <section class="w-full max-w-[440px] rounded-2xl bg-white px-7 sm:px-9 py-9 text-center shadow-[0_20px_50px_-15px_rgba(15,23,42,0.12)] border border-white/90">
                <div class="w-12 h-12 rounded-xl bg-[#0077CC] text-white flex items-center justify-center mx-auto shadow-md shadow-[#0077CC]/25">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>

                <h1 class="text-xl sm:text-[22px] font-extrabold text-slate-900 tracking-tight mt-5">
                    Sign in to explore exams
                </h1>
                <p class="text-xs text-slate-500 mt-1.5 leading-relaxed">
                    Browse public and private course exams once you are signed in.
                </p>

                <div class="mt-6 flex justify-center">
                    <a href="{{ route('login') }}" class="px-5 py-2.5 rounded-lg bg-[#0077CC] hover:bg-[#0060A8] text-white text-xs font-bold inline-flex items-center gap-1.5 shadow-sm transition-colors">
                        <span>Go to Login</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </section>
        @else
            <div class="max-w-[1160px] w-full mx-auto space-y-8">
                <div class="text-center space-y-2">
                    <h1 class="text-3xl font-extrabold text-[#1E3A8A] tracking-tight">{{ __('Available Mock Tests & Assessments') }}</h1>
                    <p class="text-xs sm:text-sm text-slate-600">{{ __('Select an online exam below or launch from your LMS portal dashboard.') }}</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    @foreach ($exams as $exam)
                        <article class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col justify-between space-y-5">
                            <div class="space-y-2">
                                <span class="inline-block px-2.5 py-0.5 rounded-full bg-[#E0F2FE] text-[#0284C7] text-[10px] font-bold uppercase tracking-wider">
                                    {{ $exam->category ?? 'Mock Exam' }}
                                </span>
                                <h2 class="text-base font-extrabold text-slate-900">{{ $exam->title }}</h2>
                                <div class="text-xs text-slate-500">
                                    Duration: {{ $exam->duration_minutes ?? 30 }} mins &middot; Total Marks: {{ $exam->total_marks ?? 50 }}
                                </div>
                            </div>
                            @if (($exam->learn_earn_type ?? '') === 'paid' && (float) ($exam->access_price ?? 0) > 0 && Route::has('plugins.commerce_cart.add'))
                                <form method="POST" action="{{ route('plugins.commerce_cart.add') }}">@csrf
                                    <input type="hidden" name="item_type" value="mock_test"><input type="hidden" name="item_id" value="{{ $exam->id }}">
                                    <button class="w-full py-2.5 rounded-lg bg-[#0077CC] hover:bg-[#0060A8] text-white text-xs font-bold">{{ __('Add Mock Test to Cart') }} · ৳{{ number_format((float) $exam->access_price, 2) }}</button>
                                </form>
                            @else
                                <a href="{{ route('admin.dashboard') }}" class="w-full py-2.5 rounded-lg bg-[#0077CC] hover:bg-[#0060A8] text-white text-xs font-bold text-center transition-colors">{{ __('Open in Portal') }}</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        @endguest
    </main>

    @include('public.partials.footer')
</div>
@endsection
