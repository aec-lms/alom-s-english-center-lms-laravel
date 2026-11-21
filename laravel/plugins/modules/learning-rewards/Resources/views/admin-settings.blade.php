@extends('layouts.app')

@section('content')
<div class="container py-4">
    <h1 class="h3 mb-1">Learning Rewards Settings</h1>
    <p class="text-muted mb-4">Configure non-cash learning points and daily limits. Verified activity sources are required; students cannot submit their own XP.</p>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('plugins.learning-rewards.admin.rules.update') }}">
        @csrf @method('PUT')
        <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
            <thead><tr><th>Activity</th><th>Points</th><th>Daily cap</th><th>Enabled</th></tr></thead>
            <tbody>
            @foreach($rules as $index => $rule)
                <tr>
                    <td><input type="hidden" name="rules[{{ $index }}][activity_key]" value="{{ $rule->activity_key }}"><code>{{ $rule->activity_key }}</code></td>
                    <td><input class="form-control" type="number" min="0" max="500" name="rules[{{ $index }}][points]" value="{{ $rule->points }}" required></td>
                    <td><input class="form-control" type="number" min="0" max="5000" name="rules[{{ $index }}][daily_cap]" value="{{ $rule->daily_cap }}" required></td>
                    <td><input class="form-check-input" type="checkbox" name="rules[{{ $index }}][is_enabled]" value="1" @checked($rule->is_enabled) aria-label="Enable {{ $rule->activity_key }}"></td>
                </tr>
            @endforeach
            </tbody>
        </table></div><div class="card-body"><button class="btn btn-primary" type="submit">Save reward rules</button></div></div>
    </form>
    <div class="card mt-4"><div class="card-body"><h2 class="h5">Achievement badges</h2>
        @foreach($badges as $badge)<div class="border-bottom py-2"><strong>{{ $badge->name }}</strong><div class="small text-muted">{{ $badge->description }} · @if($badge->points_threshold){{ $badge->points_threshold }} XP @else {{ $badge->streak_threshold }} day streak @endif</div></div>@endforeach
    </div></div>
</div>
@endsection
