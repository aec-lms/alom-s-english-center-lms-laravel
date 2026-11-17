@extends('layouts.admin')
@section('title', 'Institution & Regional Settings')
@section('content')
<div class="max-w-4xl space-y-6">
    <header>
        <h1 class="text-2xl font-extrabold text-slate-900">Institution &amp; Regional Settings</h1>
        <p class="mt-1 text-sm text-slate-500">Configure your institution identity, country, language, timezone and default currency without editing code.</p>
    </header>
    @if (session('status'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif
    <form method="POST" action="{{ route('admin.institution-settings.update') }}" class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5">
        @csrf
        <section class="space-y-4">
            <h2 class="font-bold text-slate-900">Institution Identity</h2>
            <label class="block"><span class="text-sm font-semibold text-slate-700">Institution / Product Name</span>
                <input name="name" required maxlength="160" value="{{ old('name', $values['name'] ?: config('app.name')) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="block"><span class="text-sm font-semibold text-slate-700">Website URL</span>
                <input name="website" type="url" value="{{ old('website', $values['website'] ?: config('app.url')) }}" placeholder="https://example.com" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
        </section>
        <section class="space-y-4 border-t border-slate-100 pt-5">
            <h2 class="font-bold text-slate-900">Country &amp; Localization</h2>
            <div class="grid sm:grid-cols-2 gap-4">
                <label class="block"><span class="text-sm font-semibold text-slate-700">Country Code (ISO 3166-1 alpha-2)</span>
                    <input name="country_code" required minlength="2" maxlength="2" pattern="[A-Za-z]{2}" value="{{ old('country_code', $values['country_code'] ?: 'US') }}" placeholder="US, GB, BD" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="block"><span class="text-sm font-semibold text-slate-700">Language / Locale</span>
                    <input name="locale" required maxlength="35" value="{{ old('locale', $values['locale'] ?: 'en') }}" placeholder="en, en-US, bn-BD, ar-SA" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="block"><span class="text-sm font-semibold text-slate-700">Timezone (IANA)</span>
                    <input name="timezone" required list="institution-timezones" value="{{ old('timezone', $values['timezone'] ?: 'UTC') }}" placeholder="Europe/London" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    <datalist id="institution-timezones">
                        @foreach (DateTimeZone::listIdentifiers() as $timezone)<option value="{{ $timezone }}">@endforeach
                    </datalist>
                </label>
                <label class="block"><span class="text-sm font-semibold text-slate-700">Default Currency (ISO 4217)</span>
                    <input name="currency" required minlength="3" maxlength="3" pattern="[A-Za-z]{3}" value="{{ old('currency', $values['currency'] ?: 'USD') }}" placeholder="USD, EUR, BDT, GBP" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
            </div>
            <p class="text-xs text-slate-500">Currency changes apply as the default for new transactions. Existing financial records must retain their recorded transaction currency.</p>
        </section>
        <div class="flex justify-end border-t border-slate-100 pt-4">
            <button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white hover:bg-blue-700">Save Institution Settings</button>
        </div>
    </form>
</div>
@endsection
