@extends('layouts.app')

@section('title', 'Redeem Course Gift — AEC LMS')

@section('content')
<div class="mx-auto max-w-xl px-4 py-12">
    <section class="rounded-3xl border border-slate-200 bg-white p-7 text-center shadow-xl sm:p-9">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-50 text-3xl">🎁</div>
        <p class="mt-5 text-xs font-bold uppercase tracking-[.2em] text-violet-700">A gift of learning</p>
        <h1 class="mt-2 text-2xl font-black text-slate-900">{{ $gift->course_title }}</h1>
        @if ($gift->recipient_name)
            <p class="mt-2 text-sm text-slate-600">For {{ $gift->recipient_name }}</p>
        @endif
        @if ($gift->message)
            <blockquote class="mt-5 rounded-xl bg-slate-50 p-4 text-sm italic text-slate-600">{{ $gift->message }}</blockquote>
        @endif
        <p class="mt-5 text-xs text-slate-500">Signed in as {{ auth()->user()->email }}. This gift will be added to this account.</p>
        <form method="POST" action="{{ route('course-commerce.gifts.redeem', $token) }}" class="mt-6">
            @csrf
            <button type="submit" class="w-full rounded-xl bg-violet-600 px-5 py-3 font-extrabold text-white hover:bg-violet-700">Redeem and add course to my learning</button>
        </form>
    </section>
</div>
@endsection
