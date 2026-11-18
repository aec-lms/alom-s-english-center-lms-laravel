@extends('layouts.app')

@section('title', ($profile->display_name ?: $profile->name).' — Alom’s English Center')

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
    <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div class="h-40 bg-gradient-to-r from-sky-500 via-indigo-600 to-fuchsia-600 sm:h-56">
            @if ($profile->cover_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($profile->cover_path) }}" alt="" class="h-full w-full object-cover">
            @endif
        </div>
        <div class="px-6 pb-8">
            <div class="-mt-14 flex h-28 w-28 items-center justify-center overflow-hidden rounded-3xl border-4 border-white bg-indigo-100 text-3xl font-black text-indigo-700 shadow">
                @if ($profile->avatar_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($profile->avatar_path) }}" alt="{{ $profile->display_name ?: $profile->name }}" class="h-full w-full object-cover">
                @else
                    {{ strtoupper(substr($profile->display_name ?: $profile->name, 0, 1)) }}
                @endif
            </div>
            <h1 class="mt-4 text-3xl font-black text-slate-900">{{ $profile->display_name ?: $profile->name }}</h1>
            @if ($profile->username)<p class="mt-1 text-sm font-semibold text-indigo-600">{{ '@'.$profile->username }}</p>@endif
            @if ($profile->headline)<p class="mt-3 text-base font-semibold text-slate-700">{{ $profile->headline }}</p>@endif
            @if ($profile->location)<p class="mt-2 text-sm text-slate-500">{{ $profile->location }}</p>@endif
            @if ($profile->bio)<p class="mt-5 whitespace-pre-line text-sm leading-7 text-slate-600">{{ $profile->bio }}</p>@endif
            @if ($profile->website)<a href="{{ $profile->website }}" target="_blank" rel="noopener noreferrer" class="mt-5 inline-flex text-sm font-bold text-indigo-700 underline">Personal website</a>@endif
            @if (is_array($profile->social_links) && count($profile->social_links))
                <div class="mt-6 flex flex-wrap gap-2">
                    @foreach ($profile->social_links as $platform => $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="rounded-full border border-indigo-100 bg-indigo-50 px-4 py-2 text-xs font-bold capitalize text-indigo-700">{{ $platform }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </article>
</div>
@endsection
