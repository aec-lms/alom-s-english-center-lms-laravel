@extends('layouts.app')
@section('title', 'Support Ticket #' . $ticket->id)
@section('content')
@include('public.partials.navbar')
<div class="mx-auto max-w-4xl px-4 py-8 space-y-5"><a href="{{ route('plugins.support.student.index') }}" class="text-sm text-sky-700 underline">← My support tickets</a><div><h1 class="text-2xl font-extrabold">#{{ $ticket->id }} · {{ $ticket->subject }}</h1><p class="text-sm text-slate-500">{{ ucfirst(str_replace('_',' ',$ticket->status)) }}</p></div>
@if(session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
<section class="rounded-2xl border bg-white p-5 space-y-4">@foreach($ticket->messages as $message)<article class="rounded-xl border p-4"><div class="flex justify-between gap-2 text-xs text-slate-500"><strong>{{ $message->sender?->name ?? 'Academy support' }} · {{ $message->sender_role==='admin'?'Academy support':'You' }}</strong><span>{{ $message->created_at?->format('d M Y, h:i A') }}</span></div><p class="mt-2 whitespace-pre-wrap">{{ $message->body }}</p></article>@endforeach</section>
@if($ticket->status!=='closed')<form method="POST" action="{{ route('plugins.support.student.reply',$ticket->id) }}" class="rounded-2xl border bg-white p-5 space-y-3">@csrf<label class="block text-sm">Your reply<textarea name="message" required minlength="2" maxlength="5000" rows="4" class="mt-1 w-full rounded border px-3 py-2"></textarea></label><button class="rounded bg-sky-600 px-5 py-2.5 font-bold text-white">Send reply</button></form>@else<p class="rounded-lg bg-slate-100 p-4 text-sm text-slate-600">This ticket is closed. Create a new request if you need more help.</p>@endif
</div>
@endsection
