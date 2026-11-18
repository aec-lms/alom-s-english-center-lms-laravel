@extends('layouts.app')
@section('title', 'My Bundle Orders')
@section('content')
@include('public.partials.navbar')
<div class="mx-auto max-w-5xl px-4 py-8 space-y-5"><h1 class="text-3xl font-extrabold">My Bundle Orders</h1>@forelse($orders as $order)<a href="{{ route('course-bundles.orders.show', $order->id) }}" class="block rounded-xl border border-slate-200 bg-white p-5"><div class="flex flex-wrap justify-between gap-3"><strong>{{ $order->bundle_title }}</strong><span class="font-bold">৳{{ number_format((float)$order->amount, 2) }}</span></div><p class="mt-2 text-xs text-slate-500">{{ $order->status }} · {{ $order->created_at->format('d M Y') }} · {{ $order->items->count() }} courses</p></a>@empty<p class="text-slate-500">You have no bundle orders yet.</p>@endforelse{{ $orders->links() }}</div>
@endsection
