@extends('layouts.app')

@section('title', 'Profile Activity — Alom’s English Center')

@section('content')
<div class="mx-auto max-w-4xl space-y-5 px-4 py-8 sm:px-6">
    <header class="rounded-3xl bg-gradient-to-br from-slate-900 via-indigo-900 to-violet-800 p-6 text-white sm:p-8">
        <p class="text-xs font-bold uppercase tracking-widest text-indigo-200">Account security</p>
        <h1 class="mt-2 text-3xl font-black">Profile activity</h1>
        <p class="mt-2 text-sm text-indigo-100">A private history of profile changes and password security events.</p>
    </header>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        @forelse ($activities as $activity)
            <article class="flex flex-col gap-2 border-b border-slate-100 p-5 last:border-0 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="font-bold text-slate-900">
                        {{ $activity->action === 'security.password_changed' ? 'Password changed' : 'Profile updated' }}
                    </h2>
                    @if ($activity->action === 'profile.updated')
                        <p class="mt-1 text-sm text-slate-600">
                            Updated: {{ collect(data_get($activity->metadata, 'changed_fields', []))->map(fn ($field) => str($field)->replace('_', ' ')->title())->join(', ') ?: 'Profile details' }}
                        </p>
                    @else
                        <p class="mt-1 text-sm text-slate-600">Other registered browser sessions were signed out when available.</p>
                    @endif
                </div>
                <time class="shrink-0 text-xs font-medium text-slate-500" datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->format('d M Y, h:i A') }}</time>
            </article>
        @empty
            <p class="p-8 text-center text-sm text-slate-500">No profile activity recorded yet.</p>
        @endforelse
    </section>

    {{ $activities->links() }}
    <a href="{{ route('profile.settings.edit') }}" class="inline-flex rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700">Back to profile settings</a>
</div>
@endsection
