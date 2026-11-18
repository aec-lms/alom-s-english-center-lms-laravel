@extends('layouts.app')

@section('title', 'Quiz Contest Entries')

@section('content')
<div class="page-header"><div><a href="{{ route('plugins.quiz-contest.admin.index') }}">← সব প্রতিযোগিতা</a><h1>{{ $record->title }} — Entries</h1><p class="muted">স্কোর বেশি হলে আগে; সমান স্কোরে আগে জমা দেওয়া উত্তর উপরে থাকবে।</p></div></div>
<div class="panel"><div class="table-wrap"><table><thead><tr><th>Rank</th><th>Student</th><th>Score</th><th>Submitted</th></tr></thead><tbody>
@forelse($entries as $entry)
    <tr><td>{{ $entries->firstItem() + $loop->index }}</td><td>{{ $entry->user?->name }}<br><small class="muted">{{ $entry->user?->email }}</small></td><td>{{ $entry->score }} / {{ $record->questions->count() }}</td><td>{{ $entry->submitted_at?->format('d M Y, h:i A') }}</td></tr>
@empty
    <tr><td colspan="4" class="muted">কেউ এখনো অংশ নেয়নি।</td></tr>
@endforelse
</tbody></table></div>{{ $entries->links() }}</div>
@endsection
