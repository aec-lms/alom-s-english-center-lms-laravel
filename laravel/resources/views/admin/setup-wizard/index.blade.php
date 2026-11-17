@extends('layouts.admin')
@section('title', 'LMS Setup Wizard')
@section('content')
@php
$steps = ['institution' => 'প্রতিষ্ঠান', 'branding' => 'লোগো ও রং', 'academic' => 'শিক্ষাবর্ষ', 'payments' => 'পেমেন্ট', 'finish' => 'সম্পন্ন'];
$data = $state['data'];
@endphp
<div class="mx-auto max-w-4xl space-y-6">
    <header><h1 class="text-2xl font-extrabold">LMS Setup Wizard</h1><p class="text-sm text-slate-500">ধাপে ধাপে সেটআপ করুন। প্রতিটি ধাপ স্বয়ংক্রিয়ভাবে সংরক্ষিত হবে; পরে আবার চালিয়ে যেতে পারবেন।</p></header>
    <nav class="grid grid-cols-2 gap-2 sm:grid-cols-5">@foreach($steps as $key => $label)<a href="{{ route('admin.setup.index', ['step' => $key]) }}" class="rounded-xl border p-3 text-center text-sm {{ $step === $key ? 'border-blue-600 bg-blue-50 font-bold text-blue-700' : (in_array($key, $state['completed_steps'], true) ? 'border-emerald-300 bg-emerald-50' : 'bg-white') }}">{{ $loop->iteration }}. {{ $label }} @if(in_array($key, $state['completed_steps'], true)) ✓ @endif</a>@endforeach</nav>
    @if(session('status'))<div role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    <form method="POST" action="{{ route('admin.setup.save', ['step' => $step]) }}" class="space-y-5 rounded-2xl border bg-white p-6">@csrf
    @if($step === 'institution')
        <h2 class="text-lg font-bold">প্রতিষ্ঠানের তথ্য</h2>
        <label class="block text-sm">প্রতিষ্ঠানের নাম<input required name="name" value="{{ old('name', $data['institution.name'] ?? config('app.name')) }}" class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="block text-sm">ওয়েবসাইট URL<input type="url" name="website" value="{{ old('website', $data['institution.website'] ?? config('app.url')) }}" class="mt-1 w-full rounded-lg border p-2"></label>
        <div class="grid gap-4 sm:grid-cols-3">
        <label class="text-sm">ভাষা<select name="locale" class="mt-1 w-full rounded-lg border p-2"><option value="bn" @selected(($data['localization.locale'] ?? 'bn') === 'bn')>বাংলা</option><option value="en" @selected(($data['localization.locale'] ?? '') === 'en')>English</option></select></label>
        <label class="text-sm">Timezone<input required name="timezone" value="{{ old('timezone', $data['localization.timezone'] ?? 'Asia/Dhaka') }}" class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="text-sm">Currency<input required name="currency" maxlength="3" value="{{ old('currency', $data['finance.default_currency'] ?? 'BDT') }}" class="mt-1 w-full rounded-lg border p-2"></label></div>
    @elseif($step === 'branding')
        <h2 class="text-lg font-bold">লোগো, ফ্যাভিকন ও রং</h2>
        <p class="text-xs text-slate-500">লোগো/ফ্যাভিকন URL দিতে পারেন, অথবা Media Library থেকে আপলোড করা ছবি বেছে নিতে পারেন।</p>
        <label class="block text-sm">Logo URL<input type="url" name="logo_url" value="{{ old('logo_url', $data['branding.logo_url'] ?? '') }}" placeholder="https://..." class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="block text-sm">Media Library থেকে Logo<select name="logo_asset_id" class="mt-1 w-full rounded-lg border p-2"><option value="">URL ব্যবহার / নির্বাচন করুন</option>@foreach($assets as $asset)<option value="{{ $asset->id }}" @selected((string)($data['branding.logo_asset_id'] ?? '') === (string)$asset->id)>{{ $asset->original_name }}</option>@endforeach</select></label>
        @if($logoLibraryUrl)<img src="{{ $logoLibraryUrl }}" alt="Selected logo" class="h-16 max-w-48 object-contain">@endif
        <label class="block text-sm">Favicon URL<input type="url" name="favicon_url" value="{{ old('favicon_url', $data['branding.favicon_url'] ?? '') }}" placeholder="https://..." class="mt-1 w-full rounded-lg border p-2"></label>
        <label class="block text-sm">Media Library থেকে Favicon<select name="favicon_asset_id" class="mt-1 w-full rounded-lg border p-2"><option value="">URL ব্যবহার / নির্বাচন করুন</option>@foreach($assets as $asset)<option value="{{ $asset->id }}" @selected((string)($data['branding.favicon_asset_id'] ?? '') === (string)$asset->id)>{{ $asset->original_name }}</option>@endforeach</select></label>
        <div class="grid gap-4 sm:grid-cols-3">@foreach(['primary'=>'প্রধান রং','secondary'=>'দ্বিতীয় রং','accent'=>'অ্যাকসেন্ট রং'] as $key=>$label)<label class="text-sm">{{ $label }}<input type="color" name="{{ $key }}" value="{{ old($key, $data['branding.'.$key] ?? ['primary'=>'#0284C7','secondary'=>'#022D41','accent'=>'#F59E0B'][$key]) }}" class="mt-1 h-11 w-full"></label>@endforeach</div>
    @elseif($step === 'academic')
        <h2 class="text-lg font-bold">শিক্ষাবর্ষ সেটআপ</h2>
        <label class="block text-sm">শিক্ষাবর্ষের নাম<input required name="academic_name" value="{{ old('academic_name', $data['academic.name'] ?? date('Y').'-'.(date('Y')+1)) }}" class="mt-1 w-full rounded-lg border p-2" placeholder="২০২৬-২০২৭"></label>
        <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm">শুরুর তারিখ<input required type="date" name="starts_at" value="{{ old('starts_at', $data['academic.starts_at'] ?? '') }}" class="mt-1 w-full rounded-lg border p-2"></label><label class="text-sm">শেষের তারিখ<input required type="date" name="ends_at" value="{{ old('ends_at', $data['academic.ends_at'] ?? '') }}" class="mt-1 w-full rounded-lg border p-2"></label></div>
    @elseif($step === 'payments')
        <h2 class="text-lg font-bold">পেমেন্ট পদ্ধতি</h2>
        <p class="text-sm text-slate-500">এখানে পেমেন্ট অপশন ও গ্রহণের নির্দেশনা সংরক্ষণ হবে। API merchant credentials পরে সংশ্লিষ্ট gateway configuration-এ নিরাপদে যোগ করতে হবে।</p>
        <div class="space-y-4">
        <div class="rounded-xl border p-4"><label class="flex gap-2"><input type="checkbox" name="bkash_enabled" value="1" @checked(($data['payments.bkash.enabled'] ?? '0') === '1')> bKash চালু</label><input name="bkash_number" value="{{ old('bkash_number', $data['payments.bkash.number'] ?? '') }}" placeholder="bKash মার্চেন্ট/পেমেন্ট নম্বর" class="mt-2 w-full rounded border p-2"></div>
        <div class="rounded-xl border p-4"><label class="flex gap-2"><input type="checkbox" name="rocket_enabled" value="1" @checked(($data['payments.rocket.enabled'] ?? '0') === '1')> Rocket চালু</label><input name="rocket_number" value="{{ old('rocket_number', $data['payments.rocket.number'] ?? '') }}" placeholder="Rocket মার্চেন্ট/পেমেন্ট নম্বর" class="mt-2 w-full rounded border p-2"></div>
        <div class="rounded-xl border p-4"><label class="flex gap-2"><input type="checkbox" name="bank_enabled" value="1" @checked(($data['payments.bank.enabled'] ?? '0') === '1')> Bank Transfer চালু</label><input name="bank_name" value="{{ old('bank_name', $data['payments.bank.name'] ?? '') }}" placeholder="ব্যাংকের নাম" class="mt-2 w-full rounded border p-2"><input name="bank_account" value="{{ old('bank_account', $data['payments.bank.account'] ?? '') }}" placeholder="অ্যাকাউন্ট নম্বর/নাম" class="mt-2 w-full rounded border p-2"></div>
        <div class="rounded-xl border p-4"><label class="flex gap-2"><input type="checkbox" name="manual_enabled" value="1" @checked(($data['payments.manual.enabled'] ?? '1') === '1')> Manual Payment চালু</label><textarea name="payment_instructions" rows="3" placeholder="পেমেন্ট নির্দেশনা" class="mt-2 w-full rounded border p-2">{{ old('payment_instructions', $data['payments.instructions'] ?? '') }}</textarea></div></div>
    @else
        <h2 class="text-lg font-bold">সেটআপ পর্যালোচনা ও সম্পন্ন করুন</h2><p class="text-sm text-slate-600">প্রতিষ্ঠান, ব্র্যান্ডিং, শিক্ষাবর্ষ এবং পেমেন্ট ধাপের তথ্য সংরক্ষণ হয়েছে। সম্পন্ন করলে Dashboard-এর Setup Reminder বন্ধ হবে।</p>
        <ul class="list-disc space-y-2 pl-5 text-sm">@foreach(array_slice($steps,0,4,true) as $key=>$label)<li>{{ $label }}: {{ in_array($key, $state['completed_steps'], true) ? 'সংরক্ষিত' : 'এখনো সংরক্ষণ হয়নি' }}</li>@endforeach</ul>
    @endif
    <div class="flex flex-wrap justify-between gap-3 border-t pt-4"><a href="{{ route('admin.dashboard') }}" class="rounded-lg border px-4 py-2 text-sm">পরে করব</a><button class="rounded-lg bg-blue-600 px-5 py-2 font-bold text-white">{{ $step === 'finish' ? 'সেটআপ সম্পন্ন করুন' : 'সংরক্ষণ করে পরবর্তী ধাপ' }}</button></div>
    </form>
</div>
@endsection
