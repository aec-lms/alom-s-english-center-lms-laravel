@extends('layouts.admin')
@section('title', 'Configure Plugin — ' . $manifest->name)
@section('content')
<div class="max-w-2xl space-y-6">
    <div>
        <a href="{{ route('admin.plugins.index') }}" class="text-xs text-sky-600 font-semibold">&larr; Back to Plugin Center</a>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-2">Configure {{ $manifest->name }}</h1>
        <p class="text-xs text-slate-500">Credentials are encrypted at rest. Secret values are never returned to the browser.</p>
    </div>

    @if (str_starts_with($manifest->slug, 'gateway-'))
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <strong>Payment safety:</strong> Keep Sandbox Mode enabled until a successful test payment and webhook verification are complete. Live payments require merchant credentials and a verified HTTPS callback URL.
        </div>
        <form method="POST" action="{{ route('admin.plugins.test-connection', $manifest->slug) }}">
            @csrf
            <button class="px-4 py-2 rounded-lg border border-sky-600 text-sky-700 text-sm font-bold hover:bg-sky-50">Test Gateway Connection</button>
        </form>
    @endif

    <form method="POST" action="{{ route('admin.plugins.configure.save', $manifest->slug) }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
        @csrf
        @foreach ($manifest->settingsSchema as $field)
            @php
                $key = (string) ($field['key'] ?? '');
                $isSecret = (bool) ($field['secret'] ?? false);
                $type = (string) ($field['type'] ?? 'text');
            @endphp
            <label class="block text-xs font-semibold text-slate-700">
                {{ $field['label'] ?? $key }}
                @if ($isSecret)
                    <span class="block text-[11px] font-normal text-slate-400">
                        {{ !empty($configuredSecrets[$key]) ? 'Saved and encrypted — leave blank to keep current credential' : 'Not configured — enter the credential supplied by your payment provider' }}
                    </span>
                @endif
                @if ($type === 'select')
                    <select name="settings[{{ $key }}]" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">
                        @foreach (($field['options'] ?? []) as $optionValue => $optionLabel)
                            <option value="{{ $optionValue }}" @selected(($values[$key] ?? ($field['default'] ?? '')) === (string) $optionValue)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                @else
                    <input
                        type="{{ $isSecret ? 'password' : ($type === 'password' ? 'password' : 'text') }}"
                        name="settings[{{ $key }}]"
                        value="{{ $isSecret ? '' : ($values[$key] ?? ($field['default'] ?? '')) }}"
                        placeholder="{{ $field['placeholder'] ?? '' }}"
                        autocomplete="{{ $isSecret ? 'new-password' : 'off' }}"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"
                    >
                @endif
                @if (!empty($field['help']))
                    <span class="block mt-1 text-[11px] font-normal text-slate-500">{{ $field['help'] }}</span>
                @endif
            </label>
        @endforeach
        <button class="px-5 py-2.5 rounded-lg bg-sky-600 text-white text-sm font-bold hover:bg-sky-700">Save Encrypted Configuration</button>
    </form>
</div>
@endsection
