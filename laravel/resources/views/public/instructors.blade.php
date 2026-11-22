@extends('layouts.app')
@section('title', "Our Expert Instructors — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 sm:py-16 px-4 sm:px-6 w-full" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <div class="max-w-[1060px] mx-auto space-y-10">
            <div class="text-center space-y-2.5 max-w-2xl mx-auto">
                <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-[#0084D4]">
                    Our Expert Instructors
                </h1>
                <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                    Meet our dedicated team of educators and certified IELTS &amp; English mentors
                </p>

                <div class="pt-4">
                    <h2 class="text-lg sm:text-xl font-extrabold tracking-tight text-[#0084D4]">
                        Instructor Panel
                    </h2>
                    <div class="w-14 h-[3px] rounded-full mx-auto mt-2 bg-[#0084D4]"></div>
                </div>
            </div>

            <form method="GET" action="{{ route('public.instructors') }}" class="max-w-2xl mx-auto flex gap-2 rounded-2xl bg-white p-3 shadow-sm border border-slate-200">
                <label class="sr-only" for="instructor-search">{{ __('Search instructors') }}</label>
                <input id="instructor-search" type="search" name="q" value="{{ $searchTerm }}" maxlength="100" placeholder="{{ __('Search by instructor, subject or qualification') }}" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                <button type="submit" class="rounded-xl bg-[#0077CC] px-5 py-3 text-sm font-bold text-white hover:bg-[#0060A8]">{{ __('Search') }}</button>
            </form>

            @if ($instructors->isEmpty())
                <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold text-slate-800">{{ __('No instructors found') }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ __('Try another name, subject or qualification.') }}</p>
                </section>
            @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-7">
                @foreach ($instructors as $inst)
                    <article class="rounded-2xl bg-white shadow-[0_14px_35px_-12px_rgba(15,23,42,0.12)] border border-slate-100/90 overflow-hidden flex flex-col items-center text-center pb-7 hover:-translate-y-1 hover:shadow-xl transition-all duration-200">
                        <div class="w-full h-24 bg-[#007CC7]"></div>

                        <div class="-mt-12 w-24 h-24 rounded-full border-4 border-white shadow-md overflow-hidden bg-[#0B2239] shrink-0">
                            <img src="{{ $inst['avatar'] }}" alt="{{ $inst['name'] }}" class="w-full h-full object-cover">
                        </div>

                        <div class="px-5 pt-3.5 flex-1 flex flex-col items-center">
                            <a href="{{ route('public.instructors.show', $inst['id']) }}" class="text-base font-extrabold text-slate-900 hover:text-[#0284C7] tracking-tight">{{ $inst['name'] }}</a>

                            <div class="mt-2 inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#E6F4FE] text-[#007CC7] text-[11px] font-bold">
                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                                <span>{{ $inst['subject'] }}</span>
                            </div>

                            <p class="mt-3 text-[11px] text-slate-600 font-medium leading-relaxed">{{ $inst['role'] }}</p>
                            <p class="mt-0.5 text-[11px] text-slate-400 leading-relaxed">{{ $inst['credentials'] }}</p>
                        </div>

                        <div class="mt-5 flex items-center justify-center gap-2.5">
                            <a href="{{ route('public.learn') }}" aria-label="{{ $inst['name'] }} YouTube" class="w-8 h-8 rounded-full bg-[#E11D48] hover:bg-[#BE123C] text-white flex items-center justify-center shadow-xs transition-colors">
                                <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 7.2a2.7 2.7 0 0 0-1.9-1.9C18 4.8 12 4.8 12 4.8s-6 0-7.7.5a2.7 2.7 0 0 0-1.9 1.9C2 8.9 2 12 2 12s0 3.1.4 4.8a2.7 2.7 0 0 0 1.9 1.9c1.7.5 7.7.5 7.7.5s6 0 7.7-.5a2.7 2.7 0 0 0 1.9-1.9c.4-1.7.4-4.8.4-4.8s0-3.1-.4-4.8zM10 15.2V8.8L15.4 12 10 15.2z"/></svg>
                            </a>
                            <a href="{{ route('public.courses') }}" aria-label="{{ $inst['name'] }} Facebook" class="w-8 h-8 rounded-full bg-[#1877F2] hover:bg-[#145DBB] text-white flex items-center justify-center shadow-xs transition-colors">
                                <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 9H16V6h-2.5C11.6 6 10 7.6 10 9.5V11H8v3h2v7h3v-7h2.3l.7-3H13V9.5c0-.3.2-.5.5-.5z"/></svg>
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
            @endif
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
