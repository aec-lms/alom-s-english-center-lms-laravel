@extends('layouts.app')

@section('title', 'Profile Settings — Alom’s English Center')

@section('content')
@php
    $profileChecks = [
        filled($user->username), filled($user->display_name), filled($user->headline),
        filled($user->bio), filled($user->location), filled($user->website),
        filled($user->avatar_path), filled($user->cover_path),
        is_array($user->social_links) && count($user->social_links) > 0,
    ];
    $profileCompletion = (int) round((count(array_filter($profileChecks)) / count($profileChecks)) * 100);
@endphp
<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
    <header class="rounded-3xl bg-gradient-to-br from-indigo-700 via-violet-700 to-fuchsia-700 p-6 text-white shadow-lg sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-widest text-indigo-100">Your account · Your identity</p>
        <h1 class="mt-2 text-3xl font-black">Profile Studio</h1>
        <p class="mt-2 max-w-2xl text-sm text-indigo-100">Make your learning and teaching profile yours. Update your photo, public name, personal details and social links.</p>
        <div class="mt-5 max-w-xl rounded-2xl border border-white/20 bg-white/10 p-4" aria-label="Profile completion">
            <div class="flex items-center justify-between gap-3 text-sm"><span class="font-semibold">Profile completion</span><strong>{{ $profileCompletion }}%</strong></div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/20" role="progressbar" aria-valuenow="{{ $profileCompletion }}" aria-valuemin="0" aria-valuemax="100" aria-label="Profile completion percentage">
                <div class="h-full rounded-full bg-white" style="width: {{ $profileCompletion }}%"></div>
            </div>
        </div>
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
                    <img src="{{ \Illuminate\Support\Facades\route('profile.settings.media', ['type' => 'cover']) }}" alt="Profile cover" data-profile-preview="cover" class="h-full w-full object-cover">
                @else
                    <img src="" alt="Cover photo preview" data-profile-preview="cover" class="hidden h-full w-full object-cover">
                @endif
            </div>
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-end">
                <div class="-mt-14 flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-3xl border-4 border-white bg-slate-100 text-3xl font-black text-indigo-700 shadow">
                    @if ($user->avatar_path)
                        <img src="{{ \Illuminate\Support\Facades\route('profile.settings.media', ['type' => 'avatar']) }}" alt="Your profile picture" data-profile-preview="avatar" class="h-full w-full object-cover">
                    @else
                        <img src="" alt="Profile picture preview" data-profile-preview="avatar" class="hidden h-full w-full object-cover">
                        <span data-profile-avatar-placeholder>{{ strtoupper(substr($user->display_name ?: $user->name, 0, 1)) }}</span>
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-xl font-extrabold text-slate-900">{{ $user->display_name ?: $user->name }}</h2>
                    <p class="text-sm text-slate-500">{{ $user->email }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="block text-xs font-semibold text-slate-600">Profile picture <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-profile-file="avatar" class="mt-1 block w-full text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:font-bold file:text-indigo-700"><span class="mt-1 block font-normal text-slate-500">Live preview · JPG, PNG or WebP · max 3 MB</span>
                        <div class="mt-3 space-y-2 rounded-xl bg-slate-50 p-3">
                            <label class="block text-[11px] font-semibold text-slate-600">Zoom <input type="range" min="1" max="3" step="0.05" value="1" data-crop-control="zoom" data-crop-kind="avatar" disabled class="mt-1 w-full accent-indigo-600"></label>
                            <label class="block text-[11px] font-semibold text-slate-600">Horizontal position <input type="range" min="0" max="100" value="50" data-crop-control="x" data-crop-kind="avatar" disabled class="mt-1 w-full accent-indigo-600"></label>
                            <label class="block text-[11px] font-semibold text-slate-600">Vertical position <input type="range" min="0" max="100" value="50" data-crop-control="y" data-crop-kind="avatar" disabled class="mt-1 w-full accent-indigo-600"></label>
                        </div></div>
                    <div class="block text-xs font-semibold text-slate-600">Cover photo <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" data-profile-file="cover" class="mt-1 block w-full text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:font-bold file:text-indigo-700"><span class="mt-1 block font-normal text-slate-500">Live preview · JPG, PNG or WebP · max 5 MB</span>
                        <div class="mt-3 space-y-2 rounded-xl bg-slate-50 p-3">
                            <label class="block text-[11px] font-semibold text-slate-600">Zoom <input type="range" min="1" max="3" step="0.05" value="1" data-crop-control="zoom" data-crop-kind="cover" disabled class="mt-1 w-full accent-indigo-600"></label>
                            <label class="block text-[11px] font-semibold text-slate-600">Horizontal position <input type="range" min="0" max="100" value="50" data-crop-control="x" data-crop-kind="cover" disabled class="mt-1 w-full accent-indigo-600"></label>
                            <label class="block text-[11px] font-semibold text-slate-600">Vertical position <input type="range" min="0" max="100" value="50" data-crop-control="y" data-crop-kind="cover" disabled class="mt-1 w-full accent-indigo-600"></label>
                        </div></label>
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
            <input type="hidden" name="profile_is_public" value="0">
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

        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-extrabold text-slate-900">Password &amp; sessions</h2>
            <p class="mt-1 text-sm text-slate-600">Use your current password to set a new one. A password change signs out your other registered browser sessions.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-semibold text-slate-700">Current password
                    <input type="password" name="current_password" autocomplete="current-password" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 font-normal">
                </label>
                <label class="text-sm font-semibold text-slate-700">New password
                    <input type="password" name="new_password" minlength="12" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 font-normal">
                    <span class="mt-1 block text-xs font-normal text-slate-500">At least 12 characters.</span>
                </label>
                <label class="text-sm font-semibold text-slate-700 sm:col-span-2">Confirm new password
                    <input type="password" name="new_password_confirmation" minlength="12" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 font-normal">
                </label>
            </div>
            <a href="{{ route('profile.settings.activity') }}" class="mt-4 inline-flex text-sm font-bold text-indigo-700 underline">View profile activity history</a>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('portal.dashboard') }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700">Back to dashboard</a>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-7 py-3 text-sm font-extrabold text-white shadow-lg shadow-indigo-200 transition hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus:ring-4 focus:ring-indigo-200">Save profile changes</button>
        </div>
    </form>
</div>
<script>
(() => {
    const previewUrls = new Map();
    const sourceUrls = new Map();
    const selectedFiles = new Map();
    const renderVersions = new Map();

    const controlsFor = (kind) => Array.from(document.querySelectorAll('[data-crop-kind="' + kind + '"]'));
    const setting = (kind, name, fallback) => {
        const control = document.querySelector('[data-crop-kind="' + kind + '"][data-crop-control="' + name + '"]');
        return control ? Number(control.value) : fallback;
    };

    const renderCrop = async (kind) => {
        const version = (renderVersions.get(kind) || 0) + 1;
        renderVersions.set(kind, version);
        const file = selectedFiles.get(kind);
        const sourceUrl = sourceUrls.get(kind);
        const preview = document.querySelector('[data-profile-preview="' + kind + '"]');
        const input = document.querySelector('[data-profile-file="' + kind + '"]');
        if (!file || !sourceUrl || !preview || !input) return;

        const image = new Image();
        image.src = sourceUrl;
        try { await image.decode(); } catch (_) { return; }

        const aspect = kind === 'avatar' ? 1 : (8 / 3);
        const baseWidth = Math.min(image.naturalWidth, image.naturalHeight * aspect);
        const baseHeight = baseWidth / aspect;
        const zoom = Math.max(1, Math.min(3, setting(kind, 'zoom', 1)));
        const cropWidth = baseWidth / zoom;
        const cropHeight = baseHeight / zoom;
        const maxX = Math.max(0, image.naturalWidth - cropWidth);
        const maxY = Math.max(0, image.naturalHeight - cropHeight);
        const sx = maxX * (setting(kind, 'x', 50) / 100);
        const sy = maxY * (setting(kind, 'y', 50) / 100);
        const canvas = document.createElement('canvas');
        canvas.width = kind === 'avatar' ? 600 : 1600;
        canvas.height = 600;
        const context = canvas.getContext('2d');
        if (!context) return;
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.drawImage(image, sx, sy, cropWidth, cropHeight, 0, 0, canvas.width, canvas.height);

        const croppedBlob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        if (!croppedBlob || renderVersions.get(kind) !== version) return;
        const croppedFile = new File([croppedBlob], kind + '-cropped.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        const transfer = new DataTransfer();
        transfer.items.add(croppedFile);
        input.files = transfer.files;

        const oldPreview = previewUrls.get(kind);
        if (oldPreview) URL.revokeObjectURL(oldPreview);
        const nextPreview = URL.createObjectURL(croppedBlob);
        previewUrls.set(kind, nextPreview);
        preview.src = nextPreview;
        preview.classList.remove('hidden');
        preview.classList.add('block');
    };

    document.querySelectorAll('[data-profile-file]').forEach((input) => {
        input.addEventListener('change', async () => {
            const file = input.files && input.files[0];
            if (!file) return;
            const kind = input.dataset.profileFile;
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return;

            const previousSource = sourceUrls.get(kind);
            if (previousSource) URL.revokeObjectURL(previousSource);
            sourceUrls.set(kind, URL.createObjectURL(file));
            selectedFiles.set(kind, file);
            controlsFor(kind).forEach((control) => { control.disabled = false; });
            controlsFor(kind).forEach((control) => {
                if (control.dataset.cropControl === 'zoom') control.value = '1';
                if (control.dataset.cropControl === 'x' || control.dataset.cropControl === 'y') control.value = '50';
            });
            await renderCrop(kind);
            const placeholder = document.querySelector('[data-profile-' + kind + '-placeholder]');
            if (placeholder) placeholder.classList.add('hidden');
        });
    });

    document.querySelectorAll('[data-crop-control]').forEach((control) => {
        control.addEventListener('input', () => renderCrop(control.dataset.cropKind));
    });

    window.addEventListener('pagehide', () => {
        previewUrls.forEach((url) => URL.revokeObjectURL(url));
        sourceUrls.forEach((url) => URL.revokeObjectURL(url));
        previewUrls.clear();
        sourceUrls.clear();
        selectedFiles.clear();
    });
})();
</script>
@endsection
