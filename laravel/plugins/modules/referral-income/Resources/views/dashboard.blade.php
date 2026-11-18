@extends('layouts.app')
@section('title', 'My Referral Income')
@section('content')
<div class="mx-auto max-w-6xl space-y-6 px-4 py-8">
    <div><h1 class="text-2xl font-bold">My Referral Income</h1><p class="text-sm text-slate-500">Share your code and track referral earnings.</p></div>
    <div class="rounded-2xl border bg-white p-5">
        <p class="text-sm text-slate-500">Your referral code</p>
        <div class="mt-2 flex flex-wrap items-center gap-3"><strong class="font-mono text-2xl tracking-widest">{{ $code }}</strong><button type="button" onclick="navigator.clipboard?.writeText('{{ $code }}')" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Copy code</button></div>
        <p class="mt-2 text-xs text-slate-500">Share this code with a new learner or teacher. Referral attribution can only be applied once per account.</p>
    </div>
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border bg-white p-4"><p class="text-xs text-slate-500">Referrals</p><strong class="text-2xl">{{ $referralCount }}</strong></div>
        <div class="rounded-xl border bg-white p-4"><p class="text-xs text-slate-500">Pending commission</p><strong class="text-2xl">৳{{ number_format((float)($stats->pending ?? 0), 2) }}</strong></div>
        <div class="rounded-xl border bg-white p-4"><p class="text-xs text-slate-500">Paid income</p><strong class="text-2xl">৳{{ number_format((float)($stats->paid ?? 0), 2) }}</strong></div>
    </div>
    <form method="POST" action="{{ route('plugins.referral_income.member.apply') }}" class="rounded-xl border bg-white p-4">@csrf
        <h2 class="font-semibold">Apply a referral code</h2><div class="mt-2 flex flex-wrap gap-2"><input name="referral_code" required maxlength="32" placeholder="Enter referral code" class="rounded-lg border px-3 py-2"><button class="rounded-lg bg-blue-600 px-4 py-2 text-white">Apply code</button></div>
    </form>
    <form method="POST" action="{{ route('plugins.referral_income.member.payout') }}" class="rounded-xl border bg-white p-4 space-y-3">@csrf
        <h2 class="font-semibold">Request payout</h2><div class="grid gap-2 sm:grid-cols-3"><select name="method" required class="rounded-lg border px-3 py-2"><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="bank">Bank</option></select><input name="account_details" required minlength="6" maxlength="500" placeholder="Payout account / number" class="rounded-lg border px-3 py-2 sm:col-span-2"></div><button class="rounded-lg bg-emerald-600 px-4 py-2 text-white">Request approved balance payout</button>
    </form>
    <div class="rounded-xl border bg-white p-4"><h2 class="font-semibold">Commission history</h2><div class="mt-3 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="p-2">Date</th><th class="p-2">Order</th><th class="p-2">Eligible</th><th class="p-2">Commission</th><th class="p-2">Status</th></tr></thead><tbody>@forelse($commissions as $row)<tr class="border-b"><td class="p-2">{{ $row->created_at }}</td><td class="p-2">{{ $row->source_type }} #{{ $row->source_id }}</td><td class="p-2">৳{{ number_format((float)$row->eligible_amount,2) }}</td><td class="p-2">৳{{ number_format((float)$row->commission_amount,2) }}</td><td class="p-2">{{ str_replace('_',' ',$row->status) }}</td></tr>@empty<tr><td colspan="5" class="p-4 text-slate-500">No referral commissions yet.</td></tr>@endforelse</tbody></table></div>{{ $commissions->links() }}</div>
    <div class="rounded-xl border bg-white p-4"><h2 class="font-semibold">Payout requests</h2>@forelse($payouts as $payout)<p class="border-b py-2 text-sm">৳{{ number_format((float)$payout->amount,2) }} · {{ $payout->method }} · {{ $payout->status }}</p>@empty<p class="py-2 text-sm text-slate-500">No payout requests yet.</p>@endforelse</div>
</div>
@endsection