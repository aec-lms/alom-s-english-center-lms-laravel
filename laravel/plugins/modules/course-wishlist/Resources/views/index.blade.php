@extends('layouts.app')

@section('title', __('My Wishlist') . ' | ' . config('app.name'))

@section('content')
@include('public.partials.navbar')
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <span class="badge bg-info">{{ __('My learning') }}</span>
            <h1 class="h3 mt-2 mb-1">{{ __('My Wishlist') }}</h1>
            <p class="text-muted mb-0">{{ __('Courses you saved to explore later.') }}</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ url('/courses') }}">{{ __('Browse courses') }}</a>
    </div>

    @if(session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if($courses->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body py-5 text-center">
                <h2 class="h5">{{ __('Your wishlist is empty') }}</h2>
                <p class="text-muted">{{ __('Save published courses and they will appear here.') }}</p>
                <a class="btn btn-primary" href="{{ url('/courses') }}">{{ __('Explore courses') }}</a>
            </div>
        </div>
    @else
        <div class="row g-3">
            @foreach($courses as $course)
                <div class="col-12 col-md-6 col-xl-4" wire:key="wishlist-course-{{ $course->id }}">
                    <article class="card h-100 border-0 shadow-sm">
                        @if($course->thumbnail)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($course->thumbnail) }}"
                                 alt="{{ $course->title }}" loading="lazy"
                                 class="card-img-top" style="aspect-ratio:16/9;object-fit:cover;">
                        @endif
                        <div class="card-body d-flex flex-column">
                            <h2 class="h5">{{ $course->title }}</h2>
                            <p class="text-muted small">{{ \Illuminate\Support\Str::limit(strip_tags((string) $course->description), 130) }}</p>
                            <p class="fw-semibold mt-auto">{{ __('BDT') }} {{ number_format((float) $course->price, 2) }}</p>
                            <div class="d-flex gap-2">
                                <a class="btn btn-primary btn-sm" href="{{ url('/courses/' . $course->slug) }}">{{ __('View course') }}</a>
                                <form method="POST" action="{{ route('plugins.course-wishlist.destroy', $course->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">{{ __('Remove') }}</button>
                                </form>
                            </div>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $courses->links() }}</div>
    @endif
</div>
@endsection
