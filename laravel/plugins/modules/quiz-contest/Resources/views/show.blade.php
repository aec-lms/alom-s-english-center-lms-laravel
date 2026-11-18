@extends('layouts.app')

@section('title', $record->title . ' | Quiz Contest')

@section('content')
<div class="page-header"><div><a href="{{ route('plugins.quiz-contest.index') }}">← সব প্রতিযোগিতা</a><h1>{{ $record->title }}</h1><p class="muted">{{ $record->description }}</p></div></div>
<div class="dash-summary" style="margin-bottom:20px">
    <div class="summary-box"><span class="muted">পুরস্কার</span><strong>{{ $record->prize_description }}</strong></div>
    <div class="summary-box"><span class="muted">অংশগ্রহণকারী</span><strong>{{ $record->entries_count }}</strong></div>
    <div class="summary-box"><span class="muted">প্রশ্ন</span><strong>{{ $record->questions->count() }}</strong></div>
    <div class="summary-box"><span class="muted">শেষ সময়</span><strong>{{ $record->ends_at->format('d M, h:i A') }}</strong></div>
</div>
@if($entry)
    <div class="panel">
        <span class="badge success">জমা হয়েছে</span>
        <h2>অংশগ্রহণের জন্য ধন্যবাদ</h2>
        <p>আপনার উত্তর জমা হয়েছে {{ $entry->submitted_at->format('d M Y, h:i A') }} সময়ে।</p>
        @if($answerReviewOpen)
            <p><strong>আপনার স্কোর:</strong> {{ $entry->score }} / {{ $record->questions->count() }}</p>
            @foreach($record->questions as $question)
                @php($selected = $entry->answers[$question->id] ?? null)
                <div style="padding:10px 0;border-top:1px solid var(--border)">
                    <strong>{{ $loop->iteration }}. {{ $question->question }}</strong>
                    <p>আপনার উত্তর: {{ $question->options[$selected] ?? '—' }}</p>
                    <p>সঠিক উত্তর: {{ $question->options[$question->correct_option] ?? '—' }}</p>
                </div>
            @endforeach
        @else
            <p class="muted">প্রতিযোগিতা শেষ না হওয়া পর্যন্ত সঠিক উত্তর ও স্কোর গোপন থাকবে।</p>
        @endif
    </div>
@elseif($record->status === 'published' && now()->between($record->starts_at, $record->ends_at))
    @auth
        @if(auth()->user()->hasRole('student'))
            <form class="panel" method="POST" action="{{ route('plugins.quiz-contest.submit', $record->id) }}">
                @csrf
                <h2>প্রশ্নগুলোর উত্তর দিন</h2>
                @foreach($record->questions as $question)
                    <fieldset style="border:1px solid var(--border);border-radius:12px;padding:16px;margin:14px 0">
                        <legend style="font-weight:700">{{ $loop->iteration }}. {{ $question->question }}</legend>
                        @foreach($question->options as $index => $option)
                            <label style="display:flex;gap:10px;align-items:center;padding:8px 0;font-weight:500"><input type="radio" name="answers[{{ $question->id }}]" value="{{ $index }}" required style="width:auto"> {{ $option }}</label>
                        @endforeach
                    </fieldset>
                @endforeach
                <p class="muted">প্রতি অ্যাকাউন্টে একবার অংশগ্রহণ করা যাবে। অংশগ্রহণ সম্পূর্ণ বিনামূল্যে।</p>
                <button class="btn primary" type="submit">উত্তর জমা দিন</button>
            </form>
        @else
            <div class="panel">এই প্রতিযোগিতায় শুধু শিক্ষার্থীরা অংশ নিতে পারবেন।</div>
        @endif
    @else
        <div class="panel"><h2>অংশ নিতে লগইন করুন</h2><a class="btn primary" href="{{ route('login') }}">লগইন</a> <a class="btn secondary" href="{{ route('register') }}">অ্যাকাউন্ট খুলুন</a></div>
    @endauth
@else
    <div class="panel">এই প্রতিযোগিতায় এখন উত্তর গ্রহণ করা হচ্ছে না।</div>
@endif
@if($record->winners->isNotEmpty())
    <div class="panel" style="margin-top:20px"><h2>🏆 বিজয়ী তালিকা</h2><p class="muted">সর্বোচ্চ স্কোর, এরপর সবচেয়ে আগে উত্তর জমা—এই নিয়মে র‍্যাঙ্ক করা হয়েছে।</p>
        @foreach($record->winners as $winner)
            <div style="padding:10px 0;border-bottom:1px solid var(--border)"><strong>#{{ $winner->rank }} — {{ $winner->user?->name }}</strong><div class="muted">{{ $winner->prize_snapshot }}</div></div>
        @endforeach
    </div>
@endif
@endsection
