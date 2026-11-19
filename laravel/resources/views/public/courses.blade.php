@extends('layouts.app')
@section('title', "All Courses & Batches — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 sm:py-14 px-4 sm:px-6 w-full" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <div class="max-w-[1200px] mx-auto space-y-8">
            <div class="text-center max-w-2xl mx-auto space-y-3">
                <h1 class="text-3xl sm:text-4xl font-extrabold text-[#0084D4] tracking-tight">
                    All Courses &amp; Batches
                </h1>
                <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                    Explore our structured live and recorded academic programs led by certified mentors.
                </p>

                <div class="pt-2 flex flex-wrap items-center justify-center gap-2">
                    <a
                        href="{{ route('public.courses', ['category' => 'all']) }}"
                        class="px-4 py-1.5 rounded-full text-xs font-bold inline-flex items-center gap-2 transition-all {{ $activeCategory === 'all' ? 'bg-gradient-to-r from-[#2563EB] to-[#4F46E5] text-white shadow-md' : 'bg-white text-slate-700 border border-slate-200 hover:border-blue-300' }}"
                    >
                        <span>{{ __('All Courses') }}</span>
                        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono {{ $activeCategory === 'all' ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-600' }}">{{ $courses->count() }}</span>
                    </a>

                    @foreach ($categories as $cat)
                        @php $isCat = strcasecmp($activeCategory, (string) $cat) === 0; @endphp
                        <a
                            href="{{ route('public.courses', ['category' => $cat]) }}"
                            class="px-4 py-1.5 rounded-full text-xs font-semibold inline-flex items-center gap-2 transition-all {{ $isCat ? 'bg-gradient-to-r from-[#2563EB] to-[#4F46E5] text-white shadow-md font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:border-blue-300' }}"
                        >
                            <span>{{ $cat }}</span>
                            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-mono {{ $isCat ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-500' }}">{{ $courses->where('category', $cat)->count() }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($filteredCourses->isEmpty())
                <div class="rounded-2xl border border-slate-200 bg-white p-10 text-center md:col-span-2 lg:col-span-3">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ __('No courses found in this learning path yet') }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ __('Choose another category or browse all available courses.') }}</p>
                    <a href="{{ route('public.courses', ['category' => 'all']) }}" class="mt-4 inline-flex rounded-lg bg-[#0284C7] px-4 py-2 text-xs font-bold text-white">{{ __('Browse All Courses') }}</a>
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($filteredCourses as $course)
                    @php
                        $thumb = !empty($course->thumbnail) ? $course->thumbnail : asset('assets/images/hero_educator_studio_1794552233836.jpg');
                        $price = (int) ($course->price ?? 0);
                        $origPrice = (int) ($course->original_price ?? 0);
                        $proof = $courseSocialProofSettings ?? ['enabled' => false];
                        $views = (int) (($courseSocialProofStats[$course->id]['views'] ?? 0));
                        $purchases = (int) (($courseSocialProofStats[$course->id]['purchases'] ?? 0));
                    @endphp
                    <article class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden shadow-xs hover:shadow-xl hover:-translate-y-1 transition-all duration-200 flex flex-col">
                        <a href="{{ route('public.courses.show', $course->slug) }}" class="relative h-48 bg-[#022D41] overflow-hidden block">
                            <img src="{{ $thumb }}" alt="{{ $course->title }}" class="w-full h-full object-cover">
                            @if (!empty($course->category))
                                <span class="absolute top-3 left-3 px-2.5 py-1 rounded-full bg-[#0284C7] text-white font-bold text-[10px] uppercase tracking-wider shadow-sm">
                                    {{ $course->category }}
                                </span>
                            @endif
                            @if (!empty($course->pdf_path))
                                <span class="absolute top-3 right-3 px-2 py-0.5 rounded bg-rose-600 text-white font-bold text-[10px] uppercase">PDF</span>
                            @endif
                            <span class="absolute bottom-3 right-3 px-2.5 py-1 rounded-full bg-slate-950/80 text-amber-300 font-bold text-[10px]">
                                {{ $course->duration_weeks ?? 12 }} Weeks
                            </span>
                        </a>

                        <div class="p-5 flex-1 flex flex-col justify-between space-y-4">
                            <div class="space-y-1.5">
                                <a href="{{ route('public.courses.show', $course->slug) }}" class="font-extrabold text-base text-slate-900 hover:text-[#0284C7] leading-snug line-clamp-2 block">{{ $course->title }}</a>
                                <p class="text-xs text-slate-500 leading-relaxed line-clamp-2">{{ $course->description ?? '' }}</p>
                            </div>

                            @if (($proof['enabled'] ?? false) && ($proof['show_on_cards'] ?? true))
                                <div class="flex flex-wrap items-center gap-4 text-[11px] font-semibold text-slate-500">
                                    @if ($proof['show_views'] ?? true)
                                        <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>{{ number_format($views) }} views</span>
                                    @endif
                                    @if ($proof['show_purchases'] ?? true)
                                        <span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 1.9-1.4L22 8H6"/><circle cx="10" cy="21" r="1"/><circle cx="19" cy="21" r="1"/></svg>{{ number_format($purchases) }} purchased</span>
                                    @endif
                                </div>
                            @endif

                            <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                                <div>
                                    <div class="text-lg font-extrabold font-mono text-[#0284C7]">৳{{ number_format($price) }}</div>
                                    @if ($origPrice > $price)
                                        <div class="text-[11px] font-mono text-slate-400 line-through">৳{{ number_format($origPrice) }}</div>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    @if (auth()->check() && auth()->user()->hasRole('student') && app(\App\Core\Plugins\PluginManager::class)->isEnabled('course-wishlist') && Route::has('plugins.course-wishlist.store'))
                                        <form method="POST" action="{{ route('plugins.course-wishlist.store', $course->id) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-2 border border-sky-200 text-[#0284C7] hover:bg-sky-50 font-bold rounded-lg text-xs transition-colors">{{ __('Save') }}</button>
                                        </form>
                                    @endif
                                    <a href="{{ route('public.courses.show', $course->slug) }}" class="px-4 py-2 bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold rounded-lg text-xs shadow-xs transition-colors">
                                        View Course
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
