@extends('layouts.app')
@section('title', ($course->seo_title ?? $course->title) . " — Alom's English Center")
@section('content')
@php
    $thumb = !empty($course->thumbnail) ? $course->thumbnail : asset('assets/images/hero_educator_studio_1794552233836.jpg');
    $price = (float) ($course->price ?? 0);
    $origPrice = (float) ($course->original_price ?? 0);
@endphp
<div class="min-h-screen flex flex-col bg-[#F8FAFC]">
    @include('public.partials.navbar')

    <div class="w-full bg-white border-b border-slate-100">
        <div class="max-w-[1160px] mx-auto px-4 sm:px-6 py-3 flex items-center gap-2 text-xs text-slate-500">
            <a href="{{ route('home') }}" class="hover:text-[#0284C7]">{{ __('Home') }}</a>
            <span>&rsaquo;</span>
            <a href="{{ route('public.courses') }}" class="hover:text-[#0284C7]">{{ __('Courses') }}</a>
            <span>&rsaquo;</span>
            <span class="font-semibold text-slate-800 truncate">{{ $course->title }}</span>
        </div>
    </div>

    <main class="flex-1 py-10 px-4 sm:px-6">
        <div class="max-w-[1160px] mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            <div class="lg:col-span-8 space-y-6">
                <div class="rounded-2xl overflow-hidden bg-white border border-slate-200/80 shadow-xs">
                    <div class="relative aspect-[16/9] bg-[#022D41]">
                        <img src="{{ $thumb }}" alt="{{ $course->title }}" class="w-full h-full object-cover">
                        @if (!empty($course->category))
                            <span class="absolute top-4 left-4 px-3 py-1 rounded-full bg-[#0284C7] text-white text-xs font-bold uppercase">{{ $course->category }}</span>
                        @endif
                    </div>
                    <div class="p-6 sm:p-8 space-y-4">
                        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">{{ $course->title }}</h1>
                        @if (($socialProofSettings['enabled'] ?? false) && ($socialProofSettings['show_on_details'] ?? true))
                            <div class="flex flex-wrap items-center gap-4 text-xs text-slate-500">
                                @if ($socialProofSettings['show_views'] ?? true)
                                    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><strong class="text-slate-800">{{ number_format($courseViews) }}</strong> views</span>
                                @endif
                                @if ($socialProofSettings['show_purchases'] ?? true)
                                    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 1.9-1.4L22 8H6"/><circle cx="10" cy="21" r="1"/><circle cx="19" cy="21" r="1"/></svg><strong class="text-slate-800">{{ number_format($coursePurchases) }}</strong> purchased</span>
                                @endif
                            </div>
                        @endif
                        <div class="flex flex-wrap items-center gap-4 text-xs text-slate-600">
                            <span>{{ __('Instructor:') }} <strong class="text-slate-900">{{ $course->instructor_name ?? "Alom's English Center" }}</strong></span>
                            <span>Duration: <strong class="text-slate-900">{{ $course->duration_weeks ?? 12 }} {{ __('Weeks') }}</strong></span>
                        </div>
                        <p class="text-sm text-slate-600 leading-relaxed">{{ $course->description }}</p>

                        @if ($linkedPost)
                            <div class="mt-4 p-4 rounded-xl bg-sky-50/70 border border-sky-200/70 space-y-1.5">
                                <div class="text-[11px] font-bold uppercase tracking-wider text-[#0284C7]">{{ __('Curriculum Guide & Post Overview') }}</div>
                                <div class="text-sm font-bold text-slate-900">{{ $linkedPost->title }}</div>
                                <p class="text-xs text-slate-600 leading-relaxed">{{ strip_tags($linkedPost->content) }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                @if (!empty($course->pdf_path) || !empty($course->pdf_thumbnail))
                    <div class="rounded-2xl bg-white border border-slate-200/80 p-6 flex flex-col sm:flex-row items-center gap-5 shadow-xs">
                        @if (!empty($course->pdf_thumbnail))
                            <img src="{{ $course->pdf_thumbnail }}" alt="Course Brochure Thumbnail" class="w-24 h-32 rounded-xl object-cover border border-slate-200 shadow-xs shrink-0">
                        @endif
                        <div class="flex-1 space-y-1.5 text-center sm:text-left">
                            <span class="inline-block px-2.5 py-0.5 rounded bg-rose-50 text-rose-700 text-[10px] font-bold uppercase">{{ __('PDF Study Brochure') }}</span>
                            <h2 class="text-base font-extrabold text-slate-900">{{ __('Download Course Routine & Sample Lecture Sheet') }}</h2>
                            <p class="text-xs text-slate-500">{{ __('Preview full weekly syllabus, mock exam schedule, and sample notes before joining.') }}</p>
                        </div>
                        @if (!empty($course->pdf_path))
                            <a href="{{ $course->pdf_path }}" target="_blank" rel="noopener" class="px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold shrink-0">{{ __('Download PDF') }}</a>
                        @endif
                    </div>
                @endif

                <div class="rounded-2xl bg-white border border-slate-200/80 p-6 sm:p-8 space-y-4 shadow-xs">
                    <h2 class="text-lg font-extrabold text-slate-900 border-l-4 border-[#0284C7] pl-3">{{ __('Course Curriculum & Modules') }}</h2>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($syllabus as $i => $module)
                            <li class="py-3 flex items-center gap-3 text-sm text-slate-700">
                                <span class="w-7 h-7 rounded-lg bg-sky-50 text-[#0284C7] font-mono font-bold text-xs flex items-center justify-center shrink-0">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="font-medium">{{ $module }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <aside class="lg:col-span-4 space-y-5 lg:sticky lg:top-24">
                <div class="rounded-2xl bg-white border border-slate-200/90 shadow-lg p-6 space-y-5">
                    <div>
                        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ __('Course Enrollment Fee') }}</div>
                        <div class="flex items-baseline gap-2.5 mt-1">
                            <span id="course-final-price" class="text-3xl font-extrabold font-mono text-[#0284C7]">৳{{ number_format($price) }}</span>
                            @if ($origPrice > $price)
                                <span class="text-sm font-mono text-slate-400 line-through">৳{{ number_format($origPrice) }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-2.5">
                        <div class="text-xs font-bold text-slate-700">{{ __('Have Coupon Code?') }}</div>
                        <form id="coupon-check-form" class="flex gap-2">
                            <input id="coupon-code-input" type="text" placeholder="{{ __('Enter Promo Code') }}" class="flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-mono uppercase">
                            <button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold">{{ __('Apply') }}</button>
                        </form>
                        <div id="coupon-feedback" class="hidden text-[11px] font-semibold"></div>
                    </div>

                    @auth
                        @if (auth()->user()->hasRole('student') && Route::has('plugins.enrollments.store'))
                            @if ($price > 0 && Route::has('plugins.commerce_cart.add'))
                                <form method="POST" action="{{ route('plugins.commerce_cart.add') }}">
                                    @csrf
                                    <input type="hidden" name="item_type" value="course">
                                    <input type="hidden" name="item_id" value="{{ $course->id }}">
                                    <button type="submit" class="w-full py-3.5 px-5 rounded-xl bg-[#0057FF] hover:bg-[#0046CC] text-white text-sm font-bold flex items-center justify-center shadow-sm transition-colors">{{ __('Add Course to Cart') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('plugins.enrollments.store', $course) }}">
                                    @csrf
                                    <button type="submit" class="w-full py-3.5 px-5 rounded-xl bg-[#0057FF] hover:bg-[#0046CC] text-white text-sm font-bold flex items-center justify-center shadow-sm transition-colors">
                                        {{ $price > 0 ? 'Request Enrollment & Create Invoice' : 'Enroll in Free Course' }}
                                    </button>
                                </form>
                            @endif
                            @if (session('status'))
                                <p class="text-xs text-emerald-700 mt-2">{{ session('status') }}</p>
                            @endif
                            @if ($errors->any())
                                <p class="text-xs text-rose-600 mt-2">{{ $errors->first() }}</p>
                            @endif
                            <a href="{{ route('plugins.enrollments.my') }}" class="block text-center text-xs text-sky-700 font-bold mt-3">{{ __('View My Enrollments') }}</a>
                        @else
                            <p class="text-xs text-center text-slate-500">
                                {{ auth()->user()->hasRole('student') ? 'Enrollment is currently unavailable.' : 'Sign in with a student account to enroll in this course.' }}
                            </p>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="w-full py-3.5 px-5 rounded-xl bg-[#0057FF] hover:bg-[#0046CC] text-white text-sm font-bold flex items-center justify-center shadow-sm transition-colors">
                            Login to Enroll
                        </a>
                    @endauth

                    @auth
                        @if (auth()->user()->hasRole('student') && app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-wishlist') && Route::has('plugins.course-wishlist.store'))
                            <form method="POST" action="{{ route('plugins.course-wishlist.store', $course->id) }}">
                                @csrf
                                <button type="submit" class="w-full mt-3 py-3 px-5 rounded-xl border border-sky-200 bg-sky-50 hover:bg-sky-100 text-sky-800 text-sm font-bold transition-colors">
                                    {{ __('Save to My Wishlist') }}
                                </button>
                            </form>
                        @endif
                    @endauth

                    <p class="text-[11px] text-center text-slate-400">Instant access on Web, Apple iOS &amp; Google Android apps after enrollment.</p>
                </div>
                @include('public.partials.widget-area', ['area' => 'course-sidebar'])
            </aside>
        </div>
    </main>

    @include('public.partials.footer')
</div>

<script>
(() => {
    const form = document.getElementById('coupon-check-form');
    const input = document.getElementById('coupon-code-input');
    const feedback = document.getElementById('coupon-feedback');
    const priceEl = document.getElementById('course-final-price');
    const basePrice = @json($price);
    if (!form || !input || !feedback || !priceEl) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const code = input.value.trim();
        if (!code) return;
        feedback.className = 'text-[11px] font-semibold text-slate-500';
        feedback.textContent = 'Validating coupon...';
        try {
            const resp = await fetch(@json(route('public.courses.coupon')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({ coupon_code: code, price: basePrice }),
            });
            const data = await resp.json();
            if (resp.ok && data.valid) {
                priceEl.textContent = '৳' + Number(data.final_price).toLocaleString();
                feedback.className = 'text-[11px] font-bold text-emerald-700';
                feedback.textContent = data.message;
            } else {
                feedback.className = 'text-[11px] font-bold text-rose-600';
                feedback.textContent = data.message || 'Invalid coupon code.';
            }
        } catch {
            feedback.className = 'text-[11px] font-bold text-rose-600';
            feedback.textContent = 'Unable to verify coupon right now.';
        }
    });
})();
</script>
@endsection
