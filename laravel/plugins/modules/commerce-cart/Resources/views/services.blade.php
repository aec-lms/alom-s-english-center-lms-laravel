@extends('layouts.app')
@section('title', "Learning Services — Alom's English Center")
@section('content')
<div class="min-h-screen bg-slate-50">
    @include('public.partials.navbar')
    <main class="mx-auto max-w-6xl space-y-7 px-4 py-12">
        <div class="text-center"><h1 class="text-3xl font-extrabold text-slate-900">Subscriptions &amp; Learning Services</h1><p class="mt-2 text-sm text-slate-500">Add memberships, live-class passes and special learning services to the same shopping cart as your courses and eBooks.</p></div>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($services as $service)
                <article class="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div><span class="rounded-full bg-sky-50 px-3 py-1 text-[10px] font-bold uppercase text-sky-700">{{ str_replace('_',' ',$service->type) }}</span><h2 class="mt-4 text-lg font-extrabold text-slate-900">{{ $service->title }}</h2><p class="mt-2 text-sm text-slate-500">{{ $service->description }}</p></div>
                    <div class="mt-6 flex items-center justify-between gap-3"><strong class="text-xl">৳{{ number_format((float)$service->price,2) }}</strong>
                        @auth<form method="POST" action="{{ route('plugins.commerce_cart.add') }}">@csrf<input type="hidden" name="item_type" value="{{ $service->type }}"><input type="hidden" name="item_id" value="{{ $service->id }}"><button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white">Add to Cart</button></form>
                        @else<a href="{{ route('login') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white">Sign in to Buy</a>@endauth
                    </div>
                </article>
            @empty<div class="col-span-full rounded-xl border border-dashed p-10 text-center text-sm text-slate-500">No additional paid services are available right now.</div>@endforelse
        </div>
        <div class="text-center"><a href="{{ route('plugins.commerce_cart.index') }}" class="font-bold text-sky-700">View Shopping Cart →</a></div>
    </main>
    @include('public.partials.footer')
</div>
@endsection
