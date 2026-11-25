@extends('layouts.app')
@section('title', "Official Result & Certificate Portal — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 flex items-center justify-center px-4 py-12 sm:py-16" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <section class="w-full max-w-[540px] rounded-2xl bg-white border border-white/90 p-7 sm:p-9 shadow-[0_20px_50px_-15px_rgba(15,23,42,0.12)] space-y-6">
            <div class="text-center space-y-2">
                <span class="inline-block px-3 py-1 rounded-full bg-[#E6F4FE] text-[#007CC7] text-[10px] font-extrabold uppercase tracking-wider">
                    Official Verification
                </span>
                <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">
                    Result &amp; Certificate Portal
                </h1>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Verify official student examination scorecards and digital certificates using your verification ID.
                </p>
            </div>

            <form method="GET" action="{{ route('public.result') }}" class="flex flex-col sm:flex-row gap-2.5">
                <input
                    name="code"
                    type="text"
                    required
                    value="{{ $queryCode }}"
                    placeholder="Enter Certificate / Verification ID"
                    class="flex-1 rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm font-mono text-slate-900 placeholder:text-slate-400 outline-none focus:border-[#0057FF] focus:ring-2 focus:ring-[#0057FF]/15"
                >
                <button type="submit" class="px-5 py-2.5 rounded-lg bg-[#0057FF] hover:bg-[#0046CC] text-white font-bold text-xs sm:text-sm shadow-sm transition-colors">
                    Verify Now
                </button>
            </form>

            @if ($lookupResult)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 space-y-1.5 text-xs text-emerald-900">
                    <div class="font-extrabold text-sm">Verified Credential Found</div>
                    <div>Code: <span class="font-mono font-bold">{{ $lookupResult->verification_code }}</span></div>
                    @if (!empty($lookupResult->student_name))
                        <div>Student: <strong>{{ $lookupResult->student_name }}</strong></div>
                    @endif
                    @if (!empty($lookupResult->course_title))
                        <div>Program: <strong>{{ $lookupResult->course_title }}</strong></div>
                    @endif
                    @if (!empty($lookupResult->grade_or_band))
                        <div>Score / Grade: <strong>{{ $lookupResult->grade_or_band }}</strong></div>
                    @endif
                    @if (!empty($lookupResult->issued_at))
                        <div>Issued: <strong>{{ $lookupResult->issued_at }}</strong></div>
                    @endif
                </div>

                <section class="space-y-3" aria-labelledby="achievement-card-title">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="achievement-card-title" class="text-sm font-extrabold text-slate-900">Achievement Card</h2>
                        <button type="button" onclick="window.print()" class="rounded-lg bg-[#0057FF] px-4 py-2 text-xs font-bold text-white hover:bg-[#0046CC]">Print / Save PDF</button>
                    </div>
                    <article id="achievement-card" class="relative overflow-hidden rounded-2xl border border-sky-200 bg-gradient-to-br from-[#071D49] via-[#075985] to-[#0284C7] p-6 sm:p-8 text-white shadow-lg">
                        <div class="absolute -right-12 -top-12 h-40 w-40 rounded-full border-[18px] border-white/10" aria-hidden="true"></div>
                        <div class="relative space-y-5">
                            <header class="flex items-center justify-between gap-3 border-b border-white/20 pb-4">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-sky-200">Alom's English Center</p>
                                    <p class="mt-1 text-lg font-black tracking-tight">Achievement Card</p>
                                </div>
                                <span class="rounded-full border border-emerald-200/50 bg-emerald-300/15 px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-100">Verified</span>
                            </header>
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-sky-200">Awarded to</p>
                                <h3 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight">{{ $lookupResult->student_name }}</h3>
                                <p class="mt-3 text-sm text-sky-100">For successfully completing</p>
                                <p class="mt-1 text-lg font-bold">{{ $lookupResult->course_title }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-3 border-t border-white/20 pt-4">
                                <div>
                                    <p class="text-[9px] font-bold uppercase tracking-wider text-sky-200">Grade / Band</p>
                                    <p class="mt-1 text-base font-extrabold">{{ $lookupResult->grade_or_band }}</p>
                                </div>
                                <div>
                                    <p class="text-[9px] font-bold uppercase tracking-wider text-sky-200">Issued</p>
                                    <p class="mt-1 text-base font-extrabold">{{ \Illuminate\Support\Carbon::parse($lookupResult->issued_at)->format('d M Y') }}</p>
                                </div>
                            </div>
                            <footer class="flex flex-wrap items-end justify-between gap-3 border-t border-white/20 pt-4">
                                <div>
                                    <p class="text-[9px] uppercase tracking-wider text-sky-200">Verification ID</p>
                                    <p class="mt-1 font-mono text-xs font-bold">{{ $lookupResult->verification_code }}</p>
                                </div>
                                <p class="text-[9px] font-semibold uppercase tracking-wider text-sky-100">Official digital achievement</p>
                            </footer>
                        </div>
                    </article>
                </section>
                <style>
                    @media print {
                        body * { visibility: hidden !important; }
                        #achievement-card, #achievement-card * { visibility: visible !important; }
                        #achievement-card { position: absolute; inset: 0; width: 100%; box-shadow: none !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
                        @page { size: landscape; margin: 12mm; }
                    }
                </style>
            @elseif ($lookupError)
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs font-medium text-rose-700">
                    {{ $lookupError }}
                </div>
            @endif

            <div class="pt-4 border-t border-slate-100 text-center space-y-2">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Official Education Board Results</div>
                <div class="flex flex-wrap justify-center gap-2 text-xs">
                    <a href="http://www.educationboardresults.gov.bd/" target="_blank" rel="noopener" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-sky-50 text-slate-700 hover:text-[#0284C7] font-semibold">Education Board Portal &rarr;</a>
                    <a href="https://eboardresults.com/v2/home" target="_blank" rel="noopener" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-sky-50 text-slate-700 hover:text-[#0284C7] font-semibold">Web-Based Board Result &rarr;</a>
                </div>
            </div>
        </section>
    </main>

    @include('public.partials.footer')
</div>
@endsection
