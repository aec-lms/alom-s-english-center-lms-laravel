@extends('layouts.app')

@section('title', 'সাপোর্ট টিকিট #' . $ticket->id)

@section('content')
<div class="page-header">
    <div><a href="{{ route('plugins.support.student.index') }}">← আমার টিকিট</a><h1>#{{ $ticket->id }} · {{ $ticket->subject }}</h1><p class="muted">অবস্থা: {{ $ticket->status }}</p></div>
</div>
<div class="panel" style="display:grid;gap:12px">
    @foreach($ticket->messages as $message)
        <article style="padding:14px;border:1px solid var(--border);border-radius:12px">
            <div class="meta-row" style="justify-content:space-between"><strong>{{ $message->sender_role === 'staff' ? 'সাপোর্ট টিম' : 'আপনি' }}</strong><small class="muted">{{ $message->created_at?->format('d M Y, h:i A') }}</small></div>
            <p style="white-space:pre-wrap;margin-bottom:0">{{ $message->body }}</p>
        </article>
    @endforeach
</div>
@if($ticket->status !== 'closed')
<div class="panel" style="margin-top:16px">
    <h2>উত্তর দিন</h2>
    <form method="POST" action="{{ route('plugins.support.student.reply', $ticket->id) }}">
        @csrf
        <label>বার্তা<textarea name="message" rows="4" minlength="2" maxlength="5000" required>{{ old('message') }}</textarea></label>
        <button class="btn primary" type="submit">পাঠান</button>
    </form>
</div>
@else
<div class="panel" style="margin-top:16px"><p class="muted">এই টিকিটটি বন্ধ। নতুন সহায়তার জন্য নতুন টিকিট তৈরি করুন।</p></div>
@endif
@endsection
