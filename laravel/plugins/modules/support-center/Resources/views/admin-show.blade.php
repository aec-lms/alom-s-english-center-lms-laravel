@extends('layouts.app')

@section('title', 'Support Ticket #' . $ticket->id)

@section('content')
<div class="page-header">
    <div><a href="{{ route('plugins.support.admin.index') }}">← সব টিকিট</a><h1>#{{ $ticket->id }} · {{ $ticket->subject }}</h1><p class="muted">{{ $ticket->user?->name }} · {{ $ticket->user?->email }} · Branch {{ $ticket->branch_id ?? '—' }}</p></div>
    <form method="POST" action="{{ route('plugins.support.admin.status', $ticket->id) }}" class="meta-row">
        @csrf @method('PATCH')
        <select name="status" aria-label="টিকিটের অবস্থা">@foreach(['open','in_progress','closed'] as $status)<option value="{{ $status }}" @selected($ticket->status === $status)>{{ $status }}</option>@endforeach</select>
        <button class="btn secondary" type="submit">অবস্থা সংরক্ষণ</button>
    </form>
</div>
<div class="panel" style="display:grid;gap:12px">
    @foreach($ticket->messages as $message)
        <article style="padding:14px;border:1px solid var(--border);border-radius:12px">
            <div class="meta-row" style="justify-content:space-between"><strong>{{ $message->sender?->name ?? 'Deleted account' }} · {{ $message->sender_role === 'staff' ? 'Support' : 'Student' }}</strong><small class="muted">{{ $message->created_at?->format('d M Y, h:i A') }}</small></div>
            <p style="white-space:pre-wrap;margin-bottom:0">{{ $message->body }}</p>
        </article>
    @endforeach
</div>
@if($ticket->status !== 'closed')
<div class="panel" style="margin-top:16px">
    <h2>উত্তর পাঠান</h2>
    <form method="POST" action="{{ route('plugins.support.admin.reply', $ticket->id) }}">@csrf
        <label>বার্তা<textarea name="message" rows="5" minlength="2" maxlength="5000" required>{{ old('message') }}</textarea></label>
        <button class="btn primary" type="submit">উত্তর পাঠান</button>
    </form>
</div>
@endif
@endsection
