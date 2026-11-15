@extends('layouts.admin')
@section('title', 'Configure Plugin — ' . $manifest->name)
@section('content')
<div class="max-w-2xl space-y-6">
    <div>
        <a href="{{ route('admin.plugins.index') }}" class="text-xs text-sky-600 font-semibold">&larr; Back to Plugin Center</a>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-2">Configure {{ $manifest->name }}</h1>
        <p class="text-xs text-slate-500">Secret fields are encrypted at rest with Laravel Crypt and never exposed back to the browser.</p>
    </div>

    <form method="POST" action="{{ route('admin.plugins.configure.save', $manifest->slug) }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
        @csrf
        @foreach ($manifest->settingsSchema as $field)
            @php
                $key = (string) ($field['key'] ?? '');
                $isSecret = (bool) ($field['secret'] ?? false);
            @endphp
            <label class="block text-xs font-semibold text-slate-700">
                {{ $field['label'] ?? $key }}
                @if ($isSecret)
                    <span class="text-[11px] font-normal text-slate-400">(Encrypted secret — leave blank to keep existing value)</span>
                @endif
                <input
                    type="{{ $isSecret ? 'password' : 'text' }}"
                    name="settings[{{ $key }}]"
                    value="{{ $isSecret ? '' : ($values[$key] ?? '') }}"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal"
                >
            </label>
        @endforeach
        <button class="px-5 py-2.5 rounded-lg bg-sky-600 text-white text-sm font-bold hover:bg-sky-700">Save Encrypted Configuration</button>
    </form>
</div>
@endsection
