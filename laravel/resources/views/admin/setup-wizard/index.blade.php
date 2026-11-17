@extends('layouts.admin')
@section('title', 'LMS Setup Wizard')
@section('content')
@php
    $steps = ['institution' => 'প্রতিষ্ঠান', 'branding' => 'লোগো ও রং', 'academic' => 'শিক্ষাবর্ষ', 'payments' => 'পেমেন্ট', 'review' => 'সম্পন্ন'];
    $labels = ['institution' => 'প্রতিষ্ঠানের পরিচিতি', 'branding' => 'ব্র্যান্ডিং ও ডিজাইন', 'academic' => 'শিক্ষাবর্ষ সেটআপ', 'payments' => 'পেমেন্ট পদ্ধতি'];
@endphp
<div class="mx-auto max-w-5xl space-y-6" lang="bn">
    <header class="space-y-1"><h1 class="text-2xl font-extrabold">LMS Setup Wizard</h1><p class="text-sm text-slate-500">প্রতিটি ধাপ আলাদাভাবে সংরক্ষিত হয়। যেকোনো সময় বের হয়ে পরে আবার চালিয়ে যেতে পারবেন।</p></header>
    <nav class="grid grid-cols-2 gap-2 sm:grid-cols-5" aria-label="Setup progress">
        @foreach($steps as $key => $label)
            <a href="{{ route('admin.setup-wizard.index', ['step' => $key]) }}" class="rounded-xl border p-3 text-center text-sm font-bold {{ $step === $key ? 'border-blue-500 bg-blue-50 text-blue-800' : 'border-slate-200 bg-white text-slate-600' }}">{{ $loop->iteration }}. {{ $label }}</a>
        @endforeach
    </nav>
    @if($step === 'review')
        <section class="rounded-2xl border bg-white p-6 space-y-4">
            <h2 class="text-xl font-bold">সেটআপ পর্যালোচনা</h2>
            <p class="text-sm text-slate-600">সংরক্ষিত তথ্যগুলো পর্যালোচনা করে সেটআপ সম্পন্ন করুন। অসম্পূর্ণ থাকলে সংশ্লিষ্ট ধাপে ফিরে যান।</p>
            <dl class="grid gap-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-slate-500">প্রতিষ্ঠান</dt><dd class="font-semibold">{{ $values['institution.name'] ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">ভাষা</dt><dd class="font-semibold">{{ $values['localization.locale'] ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">শিক্ষাবর্ষ</dt><dd class="font-semibold">{{ $values['academic.current_year_name'] ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">তারিখ</dt><dd class="font-semibold">{{ $values['academic.current_year_start'] ?? '—' }} — {{ $values['academic.current_year_end'] ?? '—' }}</dd></div>
            </dl>
            <form method="POST" action="{{ route('admin.setup-wizard.complete') }}">@csrf<button class="rounded-xl bg-blue-600 px-5 py-3 font-bold text-white">সেটআপ সম্পন্ন করুন</button></form>
        </section>
    @else
        <form method="POST" action="{{ route('admin.setup-wizard.save', $step) }}" class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5">
            @csrf
            <h2 class="text-xl font-bold">{{ $labels[$step] }}</h2>
            @if($step === 'institution')
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm">প্রতিষ্ঠানের নাম<input name="institution_name" required maxlength="160" value="{{ old('institution_name', $values['institution.name'] ?? '') }}" class="mt-1 w-full rounded-lg border p-3"></label>
                    <label class="block text-sm">ওয়েবসাইট URL<input name="institution_website" type="url" value="{{ old('institution_website', $values['institution.website'] ?? '') }}" placeholder="https://example.com" class="mt-1 w-full rounded-lg border p-3"></label>
                    <label class="block text-sm">দেশের কোড<input name="institution_country" required maxlength="2" value="{{ old('institution_country', $values['institution.country_code'] ?? 'BD') }}" class="mt-1 w-full rounded-lg border p-3"></label>
                    <label class="block text-sm">ভাষা<select name="institution_locale" class="mt-1 w-full rounded-lg border p-3"><option value="bn" @selected(($values['localization.locale'] ?? 'bn') === 'bn')>বাংলা</option><option value="bn-BD" @selected(($values['localization.locale'] ?? '') === 'bn-BD')>বাংলা (বাংলাদেশ)</option><option value="en" @selected(($values['localization.locale'] ?? '') === 'en')>English</option><option value="en-US" @selected(($values['localization.locale'] ?? '') === 'en-US')>English (US)</option></select></label>
                    <label class="block text-sm">টাইমজোন<input name="institution_timezone" required value="{{ old('institution_timezone', $values['localization.timezone'] ?? 'Asia/Dhaka') }}" class="mt-1 w-full rounded-lg border p-3"></label>
                    <label class="block text-sm">মুদ্রা<select name="institution_currency" class="mt-1 w-full rounded-lg border p-3">@foreach(['BDT','USD','GBP','EUR'] as $currency)<option value="{{ $currency }}" @selected(($values['finance.default_currency'] ?? 'BDT') === $currency)>{{ $currency }}</option>@endforeach</select></label>
                </div>
            @elseif($step === 'branding')
                <p class="text-sm text-slate-600">লোগো ও ফ্যাভিকন URL দিতে পারেন অথবা LMS Media Library থেকে আপলোড করা ছবি নির্বাচন করতে পারেন।</p>
                @foreach(['logo' => 'লোগো', 'favicon' => 'ফ্যাভিকন'] as $kind => $label)
                    <section class="rounded-xl border p-4 space-y-3"><h3 class="font-bold">{{ $label }}</h3>
                        <label class="block text-sm">URL<input type="url" name="{{ $kind }}_url" value="{{ old($kind.'_url', $values['branding.'.$kind.'_url'] ?? '') }}" placeholder="https://example.com/{{ $kind }}.png" class="mt-1 w-full rounded-lg border p-3"></label>
                        <fieldset class="space-y-2"><legend class="text-sm font-semibold">LMS Media Library থেকে ছবি নির্বাচন</legend>
                            @if(Route::has('plugins.media.index'))<a class="inline-flex rounded-lg border px-3 py-2 text-sm font-semibold text-blue-700" href="{{ route('plugins.media.index') }}" target="_blank" rel="noopener">Media Library খুলুন / ছবি আপলোড করুন</a>@endif
                            @if($mediaAssets->isEmpty())
                                <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">লাইব্রেরিতে কোনো Public image নেই। Admin → Media Library থেকে ছবি আপলোড করুন।</p>
                            @else
                                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    @foreach($mediaAssets as $asset)
                                        <label class="cursor-pointer rounded-xl border p-2 hover:border-blue-500">
                                            <input type="radio" name="{{ $kind }}_media_id" value="{{ $asset->id }}" class="mb-2" @checked((string) old($kind.'_media_id') === (string) $asset->id || ($values['branding.'.$kind.'_url'] ?? '') === $asset->url)>
                                            <img src="{{ $asset->url }}" alt="{{ $asset->original_name }}" class="h-20 w-full rounded-md object-contain bg-slate-50">
                                            <span class="mt-1 block truncate text-xs" title="{{ $asset->original_name }}">{{ $asset->original_name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </fieldset>
                    </section>
                @endforeach
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach(['primary' => 'প্রধান রং', 'secondary' => 'দ্বিতীয় রং', 'accent' => 'অ্যাকসেন্ট রং'] as $key => $label)
                        <label class="text-sm">{{ $label }}<input type="color" name="{{ $key }}_color" value="{{ old($key.'_color', $values['branding.'.$key.'_color'] ?? '#2563eb') }}" class="mt-1 h-12 w-full rounded-lg border p-1"></label>
                    @endforeach
                </div>
            @elseif($step === 'academic')
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm sm:col-span-2">শিক্ষাবর্ষের নাম<input name="academic_year_name" required value="{{ old('academic_year_name', $values['academic.current_year_name'] ?? '') }}" placeholder="২০২৬ শিক্ষাবর্ষ" class="mt-1 w-full rounded-lg border p-3"></label>
                    <label class="block text-sm">শুরুর তারিখ<input type="date" name="academic_year_start" required value="{{ old('academic_year_start', $values['academic.current_year_start'] ?? '') }}" class="mt-1 w-full rounded-lg border p-3"></label>
                    <label class="block text-sm">শেষের তারিখ<input type="date" name="academic_year_end" required value="{{ old('academic_year_end', $values['academic.current_year_end'] ?? '') }}" class="mt-1 w-full rounded-lg border p-3"></label>
                </div>
            @elseif($step === 'payments')
                <p class="text-sm text-slate-600">এখানে পেমেন্ট পদ্ধতির নির্দেশনা ও মার্চেন্ট তথ্য সংরক্ষণ হবে। API credentials এনক্রিপ্ট করে রাখা হয়; প্রকৃত gateway callback/merchant onboarding আলাদাভাবে সম্পন্ন করতে হবে।</p>
                @foreach(['bkash' => 'বিকাশ', 'nagad' => 'নগদ', 'rocket' => 'রকেট'] as $provider => $label)
                    <section class="rounded-xl border p-4 space-y-3"><label class="flex items-center gap-2 font-bold"><input type="checkbox" name="{{ $provider }}_enabled" value="1" @checked(($values['setup.payment.'. $provider .'.enabled'] ?? '0') === '1')> {{ $label }} চালু</label>
                        <label class="block text-sm">মার্চেন্ট/পেমেন্ট নম্বর<input name="{{ $provider }}_number" value="{{ $values['setup.payment.'. $provider .'.number'] ?? '' }}" class="mt-1 w-full rounded-lg border p-3"></label>
                        <label class="block text-sm">Secret / API key (ঐচ্ছিক)<input type="password" name="{{ $provider }}_secret" autocomplete="new-password" class="mt-1 w-full rounded-lg border p-3" placeholder="নতুন secret দিলে সংরক্ষণ হবে"></label>
                        @if(($values['setup.payment.'.$provider.'.secret.configured'] ?? false))<p class="text-xs text-emerald-700">আগের secret নিরাপদে সংরক্ষিত আছে। পরিবর্তন না করলে খালি রাখুন।</p>@endif
                    </section>
                @endforeach
                <section class="rounded-xl border p-4 space-y-3"><label class="flex items-center gap-2 font-bold"><input type="checkbox" name="bank_enabled" value="1" @checked(($values['setup.payment.bank.enabled'] ?? '0') === '1')> ব্যাংক ট্রান্সফার চালু</label>
                    <div class="grid gap-3 sm:grid-cols-2"><input name="bank_name" value="{{ $values['setup.payment.bank.name'] ?? '' }}" placeholder="ব্যাংকের নাম" class="rounded-lg border p-3"><input name="bank_account_name" value="{{ $values['setup.payment.bank.account.name'] ?? '' }}" placeholder="হিসাবের নাম" class="rounded-lg border p-3"><input name="bank_account_number" value="{{ $values['setup.payment.bank.account.number'] ?? '' }}" placeholder="হিসাব নম্বর" class="rounded-lg border p-3"><input name="bank_branch" value="{{ $values['setup.payment.bank.branch'] ?? '' }}" placeholder="শাখা" class="rounded-lg border p-3"></div>
                </section>
                <section class="rounded-xl border p-4 space-y-3"><label class="flex items-center gap-2 font-bold"><input type="checkbox" name="manual_enabled" value="1" @checked(($values['setup.payment.manual.enabled'] ?? '0') === '1')> ম্যানুয়াল পেমেন্ট চালু</label><textarea name="manual_instructions" rows="3" placeholder="পেমেন্টের নির্দেশনা লিখুন" class="w-full rounded-lg border p-3">{{ $values['setup.payment.manual.instructions'] ?? '' }}</textarea></section>
            @endif
            <div class="flex flex-wrap justify-between gap-3 border-t pt-4">
                @if($step !== 'institution')<a href="{{ route('admin.setup-wizard.index', ['step' => array_keys($steps)[array_search($step, array_keys($steps), true)-1]]) }}" class="rounded-lg border px-4 py-2 text-sm font-semibold">পেছনে</a>@else<span></span>@endif
                <button type="submit" class="rounded-lg bg-blue-600 px-5 py-3 text-sm font-bold text-white">সংরক্ষণ করে পরের ধাপে যান</button>
            </div>
        </form>
    @endif
</div>
@endsection
