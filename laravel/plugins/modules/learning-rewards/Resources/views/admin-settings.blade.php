@extends('layouts.app')

@section('content')
<div class="container py-4">
    <h1 class="h3 mb-1">{{ __('Learning Rewards Settings') }}</h1>
    <p class="text-muted mb-4">{{ __('Configure non-cash learning points and daily limits. Verified activity sources are required; students cannot submit their own XP.') }}</p>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('plugins.learning-rewards.admin.rules.update') }}">
        @csrf
        @method('PUT')
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>{{ __('Activity') }}</th><th>{{ __('Points') }}</th><th>{{ __('Daily cap') }}</th><th>{{ __('Pass %') }}</th><th>{{ __('Enabled') }}</th></tr></thead>
                    <tbody>
                    @foreach($rules as $index => $rule)
                        <tr>
                            <td><input type="hidden" name="rules[{{ $index }}][activity_key]" value="{{ $rule->activity_key }}"><code>{{ $rule->activity_key }}</code></td>
                            <td><input class="form-control" type="number" min="0" max="500" name="rules[{{ $index }}][points]" value="{{ $rule->points }}" required></td>
                            <td><input class="form-control" type="number" min="0" max="5000" name="rules[{{ $index }}][daily_cap]" value="{{ $rule->daily_cap }}" required></td>
                            <td>@if($rule->activity_key === 'quiz_passed')<input class="form-control" type="number" min="1" max="100" name="rules[{{ $index }}][pass_percentage]" value="{{ $rule->pass_percentage ?? 60 }}" required>@else<span class="text-muted">—</span>@endif</td>
                            <td><input class="form-check-input" type="checkbox" name="rules[{{ $index }}][is_enabled]" value="1" @checked($rule->is_enabled) aria-label="{{ __('Enabled') }} {{ $rule->activity_key }}"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body"><button class="btn btn-primary" type="submit">{{ __('Save reward rules') }}</button></div>
        </div>
    </form>

    <div class="card mt-4"><div class="card-body">
        <h2 class="h5">{{ __('Create achievement badge') }}</h2>
        <form method="POST" action="{{ route('plugins.learning-rewards.admin.badges.store') }}" class="row g-2">
            @csrf
            <div class="col-md-3"><label class="form-label" for="badge-slug">{{ __('Slug') }}</label><input class="form-control" id="badge-slug" name="slug" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" maxlength="64" required></div>
            <div class="col-md-3"><label class="form-label" for="badge-name">{{ __('Name') }}</label><input class="form-control" id="badge-name" name="name" maxlength="100" required></div>
            <div class="col-md-3"><label class="form-label" for="badge-icon">{{ __('Icon key') }}</label><input class="form-control" id="badge-icon" name="icon" value="award" maxlength="64" required></div>
            <div class="col-md-3"><label class="form-label" for="badge-points">{{ __('XP threshold') }}</label><input class="form-control" id="badge-points" type="number" min="1" max="1000000" name="points_threshold"></div>
            <div class="col-md-3"><label class="form-label" for="badge-streak">{{ __('Streak days') }}</label><input class="form-control" id="badge-streak" type="number" min="1" max="3650" name="streak_threshold"></div>
            <div class="col-md-9"><label class="form-label" for="badge-description">{{ __('Description') }}</label><input class="form-control" id="badge-description" name="description" maxlength="240" required></div>
            <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('Create badge') }}</button></div>
        </form>
    </div></div>

    <div class="card mt-3"><div class="card-body">
        <h2 class="h5">{{ __('Manage achievement badges') }}</h2>
        @forelse($badges as $badge)
            <form method="POST" action="{{ route('plugins.learning-rewards.admin.badges.update', $badge->id) }}" class="border-bottom py-3">
                @csrf
                @method('PUT')
                <div class="row g-2 align-items-end">
                    <div class="col-md-2"><label class="form-label" for="badge-name-{{ $badge->id }}">{{ __('Name') }}</label><input class="form-control" id="badge-name-{{ $badge->id }}" name="name" value="{{ $badge->name }}" maxlength="100" required></div>
                    <div class="col-md-3"><label class="form-label" for="badge-description-{{ $badge->id }}">{{ __('Description') }}</label><input class="form-control" id="badge-description-{{ $badge->id }}" name="description" value="{{ $badge->description }}" maxlength="240" required></div>
                    <div class="col-md-2"><label class="form-label" for="badge-icon-{{ $badge->id }}">{{ __('Icon key') }}</label><input class="form-control" id="badge-icon-{{ $badge->id }}" name="icon" value="{{ $badge->icon }}" maxlength="64" required></div>
                    <div class="col-md-2"><label class="form-label" for="badge-points-{{ $badge->id }}">{{ __('XP threshold') }}</label><input class="form-control" id="badge-points-{{ $badge->id }}" type="number" min="1" max="1000000" name="points_threshold" value="{{ $badge->points_threshold }}"></div>
                    <div class="col-md-1"><label class="form-label" for="badge-streak-{{ $badge->id }}">{{ __('Streak days') }}</label><input class="form-control" id="badge-streak-{{ $badge->id }}" type="number" min="1" max="3650" name="streak_threshold" value="{{ $badge->streak_threshold }}"></div>
                    <div class="col-md-1"><input type="hidden" name="is_active" value="0"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($badge->is_active) id="badge-active-{{ $badge->id }}"><label class="form-check-label" for="badge-active-{{ $badge->id }}">{{ __('Active') }}</label></div></div>
                    <div class="col-md-1"><button class="btn btn-outline-primary" type="submit">{{ __('Save') }}</button></div>
                </div>
            </form>
        @empty
            <p class="text-muted mb-0">{{ __('No achievement badges have been configured.') }}</p>
        @endforelse
    </div></div>
</div>
@endsection
