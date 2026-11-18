@extends('layouts.app')
@section('title', 'My Support Tickets')
@section('content')
@include('public.partials.navbar')
<div class="mx-auto max-w-5xl px-4 py-8 space-y-6"><div><h1 class="text-3xl font-extrabold">Contact Support</h1><p class="mt-1 text-slate-500">Ask the academy for help and track replies.</p></div>
@if(session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
@if($errors->any())<div class="rounded-lg bg-rose-50 p-3 text-rose-700">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('plugins.support.student.store') }}" class="rounded-2xl border bg-white p-5 space-y-3">@csrf<h2 class="font-bold">Create a support request</h2><input name="subject" required minlength="5" maxlength="160" placeholder="Subject" class="w-full rounded border px-3 py-2"><textarea name="message" required minlength="5" maxlength="5000" rows="4" placeholder="Describe the issue..." class="w-full rounded border px-3 py-2"></textarea><button class="rounded bg-sky-600 px-5 py-2.5 font-bold text-white">Submit ticket</button></form>
<div class="space-y-3">@forelse($tickets as $ticket)<a href="{{ route('plugins.support.student.show',$ticket->id) }}" class="block rounded-xl border bg-white p-5"><div class="flex justify-between gap-2"><strong>#{{ $ticket->id }} · {{ $ticket->subject }}</strong><span class="text-xs">{{ ucfirst(str_replace('_',' ',$ticket->status)) }}</span></div><p class="mt-2 text-sm text-slate-500">{{ \Illuminate\Support\Str::limit($ticket->latestMessage?->body,140) }}</p><p class="mt-2 text-xs text-slate-400">{{ $ticket->last_message_at?->format('d M Y, h:i A') }}</p></a>@empty<p class="text-slate-500">No support tickets yet.</p>@endforelse{{ $tickets->links() }}</div></div>
@endsection
