@extends('layouts.app')
@section('title', $record->bundle_title)
@section('content')
@include('public.partials.navbar')
<div class="mx-auto max-w-3xl px-4 py-8 space-y-5"><a href="{{ route('course-bundles.orders') }}" class="text-sm text-sky-700 underline">← My bundle orders</a><section class="rounded-2xl border border-slate-200 bg-white p-6"><h1 class="text-2xl font-extrabold">{{ $record->bundle_title }}</h1><p class="mt-2 text-lg font-bold">৳{{ number_format((float)$record->amount, 2) }}</p><h2 class="mt-5 font-bold">Purchased course snapshot</h2><ul class="mt-2 list-disc pl-5 text-sm">@foreach($record->items as $item)<li>{{ $item->course_title }}</li>@endforeach</ul>
@if($record->amount <= 0)<p class="mt-5 rounded-lg bg-emerald-50 p-3 text-emerald-800">Free bundle activated. All courses are enrolled.</p>
@elseif($invoice && $invoice->status === 'paid')<p class="mt-5 rounded-lg bg-emerald-50 p-3 text-emerald-800">Payment completed. Course enrollments are active.</p>
@else<p class="mt-5 rounded-lg bg-amber-50 p-3 text-amber-900">Payment required. Invoice {{ $invoice->invoice_number ?? 'not available' }} · Status: {{ $invoice->status ?? 'unavailable' }}.</p>
@if($paymentReady && $invoice)<form method="POST" action="{{ route('plugins.universal_payment.checkout') }}" class="mt-4 space-y-3">@csrf<input type="hidden" name="fee_invoice_id" value="{{ $invoice->id }}"><input type="hidden" name="gateway" value="bank_cash"><input type="hidden" name="item_title" value="{{ $record->bundle_title }}"><input type="hidden" name="payer_name" value="{{ auth()->user()->name }}"><input type="hidden" name="payer_contact" value="{{ auth()->user()->phone ?: auth()->user()->email }}"><label class="block text-sm">Bank transaction ID / cash receipt reference<input name="external_trx_id" required maxlength="120" class="mt-1 w-full rounded-lg border px-3 py-2"></label><button class="rounded-lg bg-sky-600 px-5 py-2.5 font-bold text-white">Submit payment receipt</button></form>@elseif($paymentPluginAvailable)<p class="mt-3 text-sm text-slate-500">Manual bank/cash receipt payments are currently disabled. Contact the administrator to enable the bank_cash gateway and offline payments.</p>@else<p class="mt-3 text-sm text-slate-500">Enable Universal Payment to submit your bank/cash payment receipt.</p>@endif
@endif
</section></div>
@endsection
