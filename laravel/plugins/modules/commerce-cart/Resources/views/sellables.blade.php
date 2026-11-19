@extends('layouts.admin')
@section('title', 'Unified Commerce Catalogue')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div><h1 class="text-2xl font-extrabold">Unified Paid Services Catalogue</h1><p class="text-sm text-slate-500">Create subscriptions, live-class passes and other paid services that can be purchased together with courses, eBooks and mock tests.</p></div>
    @if (session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('plugins.commerce_cart.admin.sellables.store') }}" class="grid gap-3 rounded-2xl border bg-white p-5 sm:grid-cols-2">
        @csrf
        <select name="type" required class="rounded-lg border px-3 py-2"><option value="subscription">Subscription / Membership</option><option value="live_class">Live Class Pass</option><option value="other">Other Paid Service</option></select>
        <input name="title" required maxlength="200" placeholder="Service title" class="rounded-lg border px-3 py-2">
        <input name="sku" maxlength="80" placeholder="SKU (optional)" class="rounded-lg border px-3 py-2">
        <input name="resource_id" type="number" min="1" placeholder="Linked Live Class ID (optional)" class="rounded-lg border px-3 py-2">
        <input name="price" required type="number" min="0.01" max="500000" step="0.01" placeholder="Price" class="rounded-lg border px-3 py-2">
        <select name="currency" class="rounded-lg border px-3 py-2"><option>BDT</option><option>USD</option></select>
        <input name="duration_days" type="number" min="1" max="3650" value="30" placeholder="Subscription duration (days)" class="rounded-lg border px-3 py-2">
        <textarea name="description" rows="2" placeholder="Description" class="rounded-lg border px-3 py-2 sm:col-span-2"></textarea>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" checked> Available for purchase</label>
        <button class="rounded-lg bg-slate-900 px-4 py-2 font-bold text-white">Add to Store Catalogue</button>
    </form>
    <div class="overflow-hidden rounded-2xl border bg-white">
        <div class="border-b p-4 font-bold">Paid Service Items</div>
        @forelse($sellables as $item)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4 last:border-0">
                <div><div class="font-bold">{{ $item->title }}</div><div class="text-xs text-slate-500">{{ strtoupper(str_replace('_',' ',$item->type)) }} · {{ $item->currency }} {{ number_format((float)$item->price,2) }} · {{ $item->sku ?: 'No SKU' }}</div></div>
                <form method="POST" action="{{ route('plugins.commerce_cart.admin.sellables.toggle', $item->id) }}">@csrf @method('PATCH')<button class="rounded-lg border px-3 py-2 text-sm font-semibold">{{ $item->is_active ? 'Disable' : 'Enable' }}</button></form>
            </div>
        @empty <p class="p-5 text-sm text-slate-500">No custom paid services configured.</p> @endforelse
        <div class="p-4">{{ $sellables->links() }}</div>
    </div>
</div>
@endsection
