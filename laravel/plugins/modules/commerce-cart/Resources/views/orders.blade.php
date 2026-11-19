@extends('layouts.admin')
@section('title', 'Unified Commerce Orders')
@section('content')
<div class="mx-auto max-w-7xl space-y-5">
    <div><h1 class="text-2xl font-extrabold">Unified Commerce Orders</h1><p class="text-sm text-slate-500">One order can contain courses, eBooks, mock tests, subscriptions and live-class passes.</p></div>
    <div class="overflow-x-auto rounded-2xl border bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Order</th><th class="p-3">Customer</th><th class="p-3">Items</th><th class="p-3">Total</th><th class="p-3">Payment</th><th class="p-3">Created</th></tr></thead>
            <tbody>
                @forelse($orders as $order)
                    <tr class="border-t"><td class="p-3 font-bold">{{ $order->order_number }}</td><td class="p-3"><div class="font-semibold">{{ $order->customer_name }}</div><div class="text-xs text-slate-500">{{ $order->customer_email }}</div></td><td class="p-3"><div class="max-w-xs text-xs">{{ \Illuminate\Support\Str::limit(collect(DB::table('commerce_order_items')->where('order_id',$order->id)->pluck('title_snapshot'))->join(', '), 100) }}</div></td><td class="p-3 font-bold">{{ $order->currency }} {{ number_format((float)$order->grand_total,2) }}</td><td class="p-3"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold">{{ strtoupper(str_replace('_',' ',$order->payment_status)) }}</span></td><td class="p-3 text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($order->created_at)->format('d M Y, H:i') }}</td></tr>
                @empty<tr><td colspan="6" class="p-8 text-center text-slate-500">No orders yet.</td></tr>@endforelse
            </tbody>
        </table>
        <div class="p-4">{{ $orders->links() }}</div>
    </div>
</div>
@endsection
