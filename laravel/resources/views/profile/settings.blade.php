@extends('layouts.app')

@section('title', 'Profile Settings — Alom’s English Center')

@section('content')
<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
    <header class="rounded-3xl bg-gradient-to-br from-indigo-700 via-violet-700 to-fuchsia-700 p-6 text-white shadow-lg sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-widest text-indigo-100">Your account · Your identity</p>
        <h1 class="mt-2 text-3xl font-black">Profile Studio</h1>
        <p class="mt-2 max-w-2xl text-sm text-indigo-100">Make your learning and teaching profile yours. Update your photo, public name, personal details and social links.</p>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <p class="font-bold">Please check the highlighted fields.</p>
            <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('profile.settings.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="relative h-36 bg-gradient-to-r from-sky-500 via-indigo-500 to-fuchsia-500 sm:h-48">
                @if ($user->cover_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($user->cover_path) }}" alt="Profile cover" class="h-full w-full object-cover">
                @endif
            </div>
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-end">
                <div class="-mt-14 flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-3xl border-4 border-white bg-slate-100 text-3xl font-black text-indigo-700 shadow">
                    @if ($user->avatar_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($user->avatar_path) }}" alt="Your profile picture" class="h-full w-full object-cover">
                    @else
                        {{ strtoupper(substr($user->display_name ?: $user->name, 0, 1)) }}
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-xl font-extrabold text-slate-900">{{ $user->display_name ?: $user->name }}</h2>
                    <p class="text-sm text-slate-500">{{ $user->email }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-xs font-semibold text-slate-600">Profile picture <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:font-bold file:text-indigo-700"></label>
                    <label class="block text-xs font-semibold text-slate-600">Cover photo <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:font-bold file:text-indigo-700"></label>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-extrabold text-slate-900">Personal information</h2>
            <p class="mt-1 text-sm text-slate-500">Your login email, role and branch assignment are protected and cannot be changed here.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-semibold text-slate-700">Username
                    <input name="username" value="{{ old('username', $user->username) }}" minlength="3" maxlength="40" pattern="[A-Za-z0-9_]+" placeholder="your_unique_username" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-normal" autocomplete="username">
                    <span class="mt-1 block text-xs font-normal text-slate-500">Unique; letters, numbers and underscores only.</span>
                </label>
                <label class="text-sm font-semibold text-slate-700">Display name
                    <input name="display_name" value="{{ old('display_name', $user->display_name) }}" maxlength="120" placeholder="How people see your name" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-normal">
                </label>
                <label class="text-sm font-semibold text-slate-700">Headline
                    <input name="headline" value="{{ old('headline', $user->headline) }}" maxlength="160" placeholder="English learner · Teacher · Mentor" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-normal">
                </label>
                <label class="text-sm font-semibold text-slate-700">Location
                    <input name="location" value="{{ old('location', $user->location) }}" maxlength="120" placeholder="City, country" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-normal">
                </label>
                <label class="text-sm font-semibold text-slate-700 sm:col-span-2">Website
                    <input type="url" name="website" value="{{ old('website', $user->website) }}" maxlength="2048" placeholder="https://example.com" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-normal">
                </label>
                <label class="text-sm font-semibold text-slate-700 sm:col-span-2">About me
                    <textarea name="bio" rows="4" maxlength="1200" placeholder="Tell people a little about yourself..." class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-normal">{{ old('bio', $user->bio) }}</textarea>
                </label>
            </div>
            <label class="mt-5 flex items-center gap-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-700">
                <input type="checkbox" name="profile_is_public" value="1" @checked(old('profile_is_public', $user->profile_is_public ?? false)) class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                <span><strong class="block">Public profile</strong><span class="text-xs text-slate-500">Allow your profile details and social links to be shown on your public profile.</span></span>
            </label>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-extrabold text-slate-900">Social media links</h2>
            <p class="mt-1 text-sm text-slate-500">Add only the accounts you want to share. All links are optional.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'x' => 'X', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'github' => 'GitHub'] as $key => $label)
                    <label class="text-sm font-semibold text-slate-700">{{ $label }}
                        <input type="url" name="social_links[{{ $key }}]" value="{{ old('social_links.'.$key, data_get($user->social_links, $key)) }}" maxlength="2048" placeholder="https://" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-normal">
                    </label>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('portal.dashboard') }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700">Back to dashboard</a>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-7 py-3 text-sm font-extrabold text-white shadow-lg shadow-indigo-200 transition hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus:ring-4 focus:ring-indigo-200">Save profile changes</button>
        </div>
    </form>
</div>
@endsection
