@extends('layouts.app')
@section('title', "Exams & Assessments — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 sm:py-16 px-4 sm:px-6 w-full" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <div class="max-w-[1160px] w-full mx-auto space-y-8">
            <header class="text-center max-w-2xl mx-auto space-y-3">
                <h1 class="text-3xl sm:text-4xl font-extrabold text-[#1E3A8A] tracking-tight">{{ __('Exams & Assessments') }}</h1>
                <p class="text-xs sm:text-sm text-slate-600">{{ __('Explore published mock tests and assessments. Sign in when you are ready to access an exam.') }}</p>
            </header>

            <form method="GET" action="{{ route('public.exams') }}" class="max-w-3xl mx-auto flex flex-col sm:flex-row gap-2 rounded-2xl bg-white p-3 shadow-sm border border-slate-200">
                <label class="sr-only" for="exam-search">{{ __('Search exams') }}</label>
                <input id="exam-search" type="search" name="q" value="{{ $searchTerm }}" maxlength="100" placeholder="{{ __('Search by exam title, subject or keyword') }}" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                <label class="sr-only" for="exam-category">{{ __('Category') }}</label>
                <select id="exam-category" name="category" class="rounded-xl border border-slate-200 px-3 py-3 text-sm">
                    <option value="all">{{ __('All subjects') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($activeCategory === $category)>{{ $category }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-xl bg-[#0077CC] px-5 py-3 text-sm font-bold text-white hover:bg-[#0060A8]">{{ __('Search') }}</button>
            </form>

            <div class="flex items-center justify-between gap-3 text-xs text-slate-500">
                <span>{{ trans_choice(':count published exam|:count published exams', $exams->count(), ['count' => $exams->count()]) }}</span>
                @if ($searchTerm !== '' || $activeCategory !== 'all')
                    <a href="{{ route('public.exams') }}" class="font-semibold text-[#0077CC] hover:underline">{{ __('Clear filters') }}</a>
                @endif
            </div>

            @if ($exams->isEmpty())
                <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold text-slate-800">{{ __('No published exams found') }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ __('Try another search term or subject, or check back later.') }}</p>
                </section>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    @foreach ($exams as $exam)
                        <article class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-sm flex flex-col justify-between gap-5">
                            <div class="space-y-3">
                                <span class="inline-flex px-3 py-1 rounded-full bg-[#E0F2FE] text-[#0284C7] text-[10px] font-bold uppercase tracking-wider">{{ $exam->category ?? __('Mock Exam') }}</span>
                                <h2 class="text-base font-extrabold text-slate-900">{{ $exam->title }}</h2>
                                @if (filled($exam->description ?? null))
                                    <p class="text-sm text-slate-600 leading-relaxed">{{ \Illuminate\Support\Str::limit(strip_tags((string) $exam->description), 150) }}</p>
                                @endif
                                <div class="text-xs text-slate-500">{{ __('Duration') }}: {{ $exam->duration_minutes ?? 30 }} {{ __('mins') }} · {{ __('Total Marks') }}: {{ $exam->total_marks ?? 50 }}</div>
                            </div>
                            @if (($exam->learn_earn_type ?? '') === 'paid' && (float) ($exam->access_price ?? 0) > 0 && Route::has('plugins.commerce_cart.add'))
                                @auth
                                    <form method="POST" action="{{ route('plugins.commerce_cart.add') }}">@csrf
                                        <input type="hidden" name="item_type" value="mock_test"><input type="hidden" name="item_id" value="{{ $exam->id }}">
                                        <button class="w-full py-2.5 rounded-xl bg-[#0077CC] hover:bg-[#0060A8] text-white text-xs font-bold">{{ __('Add Mock Test to Cart') }} · ৳{{ number_format((float) $exam->access_price, 2) }}</button>
                                    </form>
                                @else
                                    <a href="{{ route('login') }}" class="block w-full py-2.5 rounded-xl bg-[#0077CC] hover:bg-[#0060A8] text-white text-xs font-bold text-center">{{ __('Sign in to purchase') }} · ৳{{ number_format((float) $exam->access_price, 2) }}</a>
                                @endauth
                            @else
                                <a href="{{ route('login') }}" class="block w-full py-2.5 rounded-xl bg-[#0077CC] hover:bg-[#0060A8] text-white text-xs font-bold text-center">{{ __('Sign in to access') }}</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
