@extends('layouts.app')
@section('title', 'Course Bundles')
@section('content')
@include('public.partials.navbar')
<div class="mx-auto max-w-6xl px-4 py-8 space-y-6"><div><h1 class="text-3xl font-extrabold">Course Bundles</h1><p class="mt-2 text-slate-500">Explore curated course packages and save with a single purchase.</p></div>
<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">@forelse($bundles as $bundle)<a href="{{ route('course-bundles.show', $bundle->slug) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-sky-300"><span class="text-xs font-semibold text-sky-700">COURSE PACKAGE</span><h2 class="mt-2 text-xl font-bold">{{ $bundle->title }}</h2><p class="mt-2 text-sm text-slate-500">{{ \Illuminate\Support\Str::limit($bundle->description, 140) }}</p><p class="mt-4 font-extrabold text-lg">৳{{ number_format((float)$bundle->price, 2) }}</p><p class="mt-2 text-xs text-slate-500">{{ $bundle->items->count() }} courses included</p></a>@empty<p class="text-slate-500">No bundles are currently available.</p>@endforelse</div>{{ $bundles->links() }}</div>
@endsection
