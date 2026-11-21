@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div><h1 class="h3 mb-1">Learning Leaderboard</h1><p class="text-muted mb-0">Celebrate consistency, not just test scores.</p></div>
        <a href="{{ route('plugins.learning-rewards.dashboard') }}" class="btn btn-outline-secondary">My rewards</a>
    </div>
    <div class="btn-group mb-3" role="group" aria-label="Leaderboard period">
        <a class="btn {{ $period === 'week' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('plugins.learning-rewards.leaderboard', ['period' => 'week']) }}">This week</a>
        <a class="btn {{ $period === 'month' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('plugins.learning-rewards.leaderboard', ['period' => 'month']) }}">This month</a>
        <a class="btn {{ $period === 'all' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('plugins.learning-rewards.leaderboard', ['period' => 'all']) }}">All time</a>
    </div>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Rank</th><th>Student</th><th class="text-end">XP</th></tr></thead>
        <tbody>
        @forelse($query as $index => $row)
            <tr><td>{{ $query->firstItem() + $index }}</td><td>{{ $row->student_name }}</td><td class="text-end fw-bold">{{ number_format((int) $row->points) }}</td></tr>
        @empty
            <tr><td colspan="3" class="text-center text-muted py-4">No leaderboard entries yet.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    <div class="mt-3">{{ $query->links() }}</div>
</div>
@endsection
