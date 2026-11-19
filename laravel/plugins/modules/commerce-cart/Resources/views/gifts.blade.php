@extends('layouts.app')
@section('title', "Send a Gift — Alom's English Center")
@section('content')
<div class="mx-auto max-w-5xl space-y-6 px-4 py-10">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Send a Course Gift or Voucher</h1>
            <p class="mt-1 text-sm text-slate-500">Create a recipient-specific gift draft. It will be paid together with your other cart items.</p>
        </div>
        <a href="{{ route('plugins.commerce_cart.index') }}" class="rounded-lg border px-4 py-2 text-sm font-bold">View Cart</a>
    </div>

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif
    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('plugins.commerce_cart.gifts.course') }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            @csrf
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">Gift a Course</h2>
                <p class="mt-1 text-xs text-slate-500">The recipient receives access only after verified payment and claims the gift using their matching email address.</p>
            </div>
            <label class="block text-sm font-semibold text-slate-700">Published course *
                <select name="course_id" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">Choose a course</option>
                    @foreach ($courses as $course)
                        <option value="{{ $course->id }}" @selected((string) old('course_id') === (string) $course->id)>{{ $course->title }} — {{ strtoupper((string) config('lms.default_currency', 'BDT')) }} {{ number_format((float) $course->price, 2) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold text-slate-700">Recipient email *
                <input type="email" name="recipient_email" required maxlength="190" value="{{ old('recipient_email') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="friend@example.com">
            </label>
            <label class="block text-sm font-semibold text-slate-700">Recipient name
                <input name="recipient_name" maxlength="160" value="{{ old('recipient_name') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="block text-sm font-semibold text-slate-700">Personal message
                <textarea name="message" maxlength="1000" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('message') }}</textarea>
            </label>
            <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-extrabold text-white" @disabled($courses->isEmpty())>Add Course Gift to Cart</button>
            @if ($courses->isEmpty())<p class="text-xs text-amber-700">No published paid courses are available for your branch.</p>@endif
        </form>

        <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">Gift a Voucher</h2>
                <p class="mt-1 text-xs text-slate-500">The recipient can claim the voucher into their course wallet after payment is verified.</p>
            </div>
            @if ($voucherSettings['enabled'])
                <form method="POST" action="{{ route('plugins.commerce_cart.gifts.voucher') }}" class="space-y-4">
                    @csrf
                    <label class="block text-sm font-semibold text-slate-700">Recipient email *
                        <input type="email" name="recipient_email" required maxlength="190" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="friend@example.com">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">Recipient name
                        <input name="recipient_name" maxlength="160" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    @if ($voucherSettings['allow_personal_message'])
                        <label class="block text-sm font-semibold text-slate-700">Personal message
                            <textarea name="message" maxlength="1000" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                        </label>
                    @endif
                    <label class="block text-sm font-semibold text-slate-700">Voucher amount ({{ strtoupper((string) config('lms.default_currency', 'BDT')) }}) *
                        <input type="number" name="amount" required min="{{ $voucherSettings['minimum_amount'] }}" max="{{ $voucherSettings['maximum_amount'] }}" step="0.01" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="{{ $voucherSettings['minimum_amount'] }}">
                    </label>
                    <p class="text-xs text-slate-500">Allowed amount: {{ number_format((float) $voucherSettings['minimum_amount'], 2) }}–{{ number_format((float) $voucherSettings['maximum_amount'], 2) }}. Validity: {{ (int) $voucherSettings['validity_days'] }} days.</p>
                    <button type="submit" class="w-full rounded-lg bg-indigo-700 px-4 py-3 text-sm font-extrabold text-white">Add Voucher to Cart</button>
                </form>
            @else
                <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">Gift voucher purchases are temporarily disabled by the institution.</p>
            @endif
        </div>
    </div>
</div>
@endsection
