@extends('layouts.app')
@section('title', $bundle->title)
@section('content')
@include('public.partials.navbar')
<div class="mx-auto max-w-4xl px-4 py-8 space-y-6"><a href="{{ route('course-bundles.index') }}" class="text-sm text-sky-700 underline">← All bundles</a><section class="rounded-2xl border border-slate-200 bg-white p-6"><span class="text-xs font-bold text-sky-700">COURSE BUNDLE</span><h1 class="mt-2 text-3xl font-extrabold">{{ $bundle->title }}</h1><p class="mt-3 text-slate-600">{{ $bundle->description }}</p><p class="mt-5 text-2xl font-extrabold">৳{{ number_format((float)$bundle->price, 2) }}</p><h2 class="mt-6 font-bold">Included courses</h2><ul class="mt-3 space-y-2">@foreach($courses as $course)<li class="rounded-lg bg-slate-50 px-4 py-3">{{ $course->title }}</li>@endforeach</ul>
@if(!$purchaseAvailable)<p class="mt-6 rounded-lg bg-amber-50 p-3 text-amber-900">A course in this bundle currently has an open batch. Please contact admissions for enrollment options.</p>@elseif(auth()->check() && auth()->user()->hasRole('student'))<form method="POST" action="{{ route('course-bundles.checkout', $bundle->slug) }}" class="mt-6">@csrf<button class="w-full rounded-xl bg-sky-600 px-5 py-3 font-bold text-white">Purchase this bundle</button></form>@elseif(!auth()->check())<a href="{{ route('login') }}" class="mt-6 inline-flex rounded-xl bg-sky-600 px-5 py-3 font-bold text-white">Sign in to purchase</a>@endif
</section></div>
@endsection
