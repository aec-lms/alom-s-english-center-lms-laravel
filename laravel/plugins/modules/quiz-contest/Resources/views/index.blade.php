@extends('layouts.app')

@section('title', 'Quiz Contest | ' . config('app.name'))

@section('content')
<div class="page-header"><div><h1>Quiz Contest</h1><p class="muted">বিনামূল্যের জ্ঞানভিত্তিক প্রতিযোগিতায় অংশ নিন। স্কোর ও জমা দেওয়ার সময় অনুযায়ী বিজয়ী নির্ধারিত হবে।</p></div></div>
<div class="course-list">
@forelse($contests as $contest)
    <article class="course-item">
        <div class="meta-row"><div>
            <span class="badge {{ now()->between($contest->starts_at, $contest->ends_at) ? 'success' : 'info' }}">{{ now()->lt($contest->starts_at) ? 'আসন্ন' : (now()->gt($contest->ends_at) ? 'সময় শেষ' : 'চলছে') }}</span>
            <h2 style="margin:10px 0">{{ $contest->title }}</h2>
            <p class="muted">{{ \Illuminate\Support\Str::limit($contest->description, 180) }}</p>
            <p><strong>পুরস্কার:</strong> {{ $contest->prize_description }}</p>
            <small class="muted">{{ $contest->questions_count }}টি প্রশ্ন · {{ $contest->entries_count }} জন অংশগ্রহণকারী · শেষ {{ $contest->ends_at->format('d M Y, h:i A') }}</small>
        </div><div><a class="btn primary" href="{{ route('plugins.quiz-contest.show', $contest->id) }}">দেখুন ও অংশ নিন</a></div></div>
    </article>
@empty
    <div class="panel">এখন কোনো প্রকাশিত কুইজ প্রতিযোগিতা নেই। পরে আবার দেখুন।</div>
@endforelse
</div>
<div style="margin-top:18px">{{ $contests->links() }}</div>
@endsection
