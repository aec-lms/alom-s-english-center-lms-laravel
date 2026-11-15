@extends('layouts.app')
@section('title', $instructor['name'] . " — Alom's English Center")
@section('content')
<div class="min-h-screen flex flex-col bg-[#F8FAFC]">
    @include('public.partials.navbar')

    <main class="flex-1 py-12 px-4 sm:px-6" style="background: linear-gradient(135deg, #EAE9FF 0%, #F2F3FF 45%, #E5F4FE 100%)">
        <div class="max-w-[980px] mx-auto space-y-8">
            <div class="rounded-2xl bg-white border border-slate-200/80 p-7 sm:p-9 shadow-xs flex flex-col sm:flex-row items-center gap-6">
                <img src="{{ $instructor['avatar'] }}" alt="{{ $instructor['name'] }}" class="w-28 h-28 rounded-full object-cover border-4 border-sky-100 shadow-md shrink-0">
                <div class="space-y-2 text-center sm:text-left">
                    <span class="inline-block px-3 py-1 rounded-full bg-sky-50 text-[#0284C7] text-xs font-bold">{{ $instructor['subject'] }}</span>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900">{{ $instructor['name'] }}</h1>
                    <p class="text-sm text-slate-600 font-medium">{{ $instructor['role'] }}</p>
                    <p class="text-xs text-slate-500">{{ $instructor['credentials'] }}</p>
                </div>
            </div>

            <div class="space-y-4">
                <h2 class="text-lg font-extrabold text-slate-900">Featured Courses at Alom's English Center</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    @foreach ($courses as $c)
                        <a href="{{ route('public.courses.show', $c->slug) }}" class="rounded-2xl bg-white border border-slate-200 p-5 hover:shadow-md transition-all flex items-center justify-between gap-4">
                            <div>
                                <div class="text-xs font-bold text-[#0284C7]">{{ $c->category ?? 'Course' }}</div>
                                <div class="text-sm font-extrabold text-slate-900 mt-0.5">{{ $c->title }}</div>
                            </div>
                            <span class="font-mono font-extrabold text-sm text-[#0284C7] shrink-0">৳{{ number_format((float) $c->price) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </main>

    @include('public.partials.footer')
</div>
@endsection
