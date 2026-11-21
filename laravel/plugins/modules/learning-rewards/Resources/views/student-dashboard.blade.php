@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('My Learning Rewards') }}</h1>
            <p class="text-muted mb-0">{{ __('Build a learning habit and celebrate every milestone.') }}</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('plugins.learning-rewards.leaderboard') }}">{{ __('Leaderboard') }}</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ __('Total XP') }}</div><div class="display-5 fw-bold">{{ number_format($points) }}</div></div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ __('Current streak') }}</div><div class="display-5 fw-bold">{{ number_format((int) ($streak->current_streak ?? 0)) }} <span class="fs-6">{{ __('days') }}</span></div><div class="small text-muted">{{ __('Longest:') }} {{ (int) ($streak->longest_streak ?? 0) }} {{ __('days') }}</div></div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ __('Achievements') }}</div><div class="display-5 fw-bold">{{ $badges->count() }}</div></div></div></div>
    </div>

    <div class="card mb-4"><div class="card-body">
        <h2 class="h5">{{ __('Your badges') }}</h2>
        @forelse($badges as $badge)
            <span class="badge bg-warning text-dark me-2 mb-2" title="{{ $badge->description }}">{{ $badge->name }}</span>
        @empty
            <p class="text-muted mb-0">{{ __('Complete lessons, pass quizzes or attend classes to unlock your first badge.') }}</p>
        @endforelse
    </div></div>

    <div class="card mb-4"><div class="card-body">
        <h2 class="h5">{{ __('Recent learning activity') }}</h2>
        @forelse($activity as $item)
            <div class="d-flex justify-content-between border-bottom py-2 gap-3"><span>{{ __($item->description) }}</span><strong class="{{ $item->points < 0 ? 'text-danger' : 'text-success' }}">{{ $item->points > 0 ? '+' : '' }}{{ $item->points }} XP</strong></div>
        @empty
            <p class="text-muted mb-0">{{ __('Your verified learning activity will appear here.') }}</p>
        @endforelse
    </div></div>

    <div class="card"><div class="card-body">
        <h2 class="h5">{{ __('Privacy') }}</h2>
        <form method="POST" action="{{ route('plugins.learning-rewards.privacy') }}">
            @csrf @method('PATCH')
            <input type="hidden" name="leaderboard_opt_out" value="0"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="leaderboard_opt_out" value="1" id="leaderboardOptOut" @checked((bool) ($profile->leaderboard_opt_out ?? false))><label class="form-check-label" for="leaderboardOptOut">{{ __('Hide me from leaderboards') }}</label></div>
            <input type="hidden" name="profile_public" value="0"><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="profile_public" value="1" id="profilePublic" @checked((bool) ($profile->profile_public ?? true))><label class="form-check-label" for="profilePublic">{{ __('Show my achievement profile') }}</label></div>
            <button class="btn btn-primary" type="submit">{{ __('Save privacy settings') }}</button>
        </form>
    </div></div>
</div>
@endsection
