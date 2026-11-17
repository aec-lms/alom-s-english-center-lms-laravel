@extends('layouts.admin')
@section('title', 'Site Identity & Branding — AEC LMS')
@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <header>
        <h1 class="text-2xl font-extrabold text-slate-900">Site Identity &amp; Branding</h1>
        <p class="mt-1 text-sm text-slate-500">Control your LMS logo, browser icon, tagline, contact details, footer text and login-page messaging without editing code.</p>
    </header>

    <form method="POST" action="{{ route('admin.site-identity.update') }}" enctype="multipart/form-data" class="space-y-5">
        @csrf
        <section class="grid gap-5 md:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
                <div>
                    <h2 class="font-extrabold text-slate-900">Brand Assets</h2>
                    <p class="mt-1 text-xs text-slate-500">Use clear PNG, JPG or WebP images. SVG uploads are disabled for safety.</p>
                </div>
                <label class="block text-sm font-semibold text-slate-700">Site Logo
                    @if ($identity['logo_url'])
                        <span class="mt-2 flex h-24 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <img src="{{ $identity['logo_url'] }}" alt="Current site logo" class="max-h-16 max-w-full object-contain">
                        </span>
                    @else
                        <span class="mt-2 flex h-24 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-xs text-slate-400">No custom logo uploaded</span>
                    @endif
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full rounded-lg border border-slate-300 p-2 text-xs">
                    <span class="mt-1 block text-[11px] font-normal text-slate-500">Maximum 2 MB. Leave empty to keep the current logo.</span>
                    @error('logo')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                </label>
                @if ($identity['logo_path'])
                    <label class="flex items-center gap-2 text-xs text-rose-700"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                @endif
                <label class="block text-sm font-semibold text-slate-700">Browser Favicon
                    @if ($identity['favicon_url'])
                        <span class="mt-2 flex h-16 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 p-2">
                            <img src="{{ $identity['favicon_url'] }}" alt="Current favicon" class="h-10 w-10 object-contain">
                        </span>
                    @else
                        <span class="mt-2 flex h-16 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-xs text-slate-400">No custom favicon uploaded</span>
                    @endif
                    <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp" class="mt-2 block w-full rounded-lg border border-slate-300 p-2 text-xs">
                    <span class="mt-1 block text-[11px] font-normal text-slate-500">Maximum 512 KB. Square images are recommended.</span>
                    @error('favicon')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror
                </label>
                @if ($identity['favicon_path'])
                    <label class="flex items-center gap-2 text-xs text-rose-700"><input type="checkbox" name="remove_favicon" value="1"> Remove current favicon</label>
                @endif
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
                <h2 class="font-extrabold text-slate-900">Site Information</h2>
                <label class="block text-sm font-semibold text-slate-700">Tagline
                    <input name="tagline" maxlength="180" value="{{ old('tagline', $identity['tagline']) }}" placeholder="Learn English with confidence" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Contact Email
                    <input type="email" name="contact_email" maxlength="180" value="{{ old('contact_email', $identity['contact_email']) }}" placeholder="info@example.com" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Contact Phone
                    <input name="contact_phone" maxlength="40" value="{{ old('contact_phone', $identity['contact_phone']) }}" placeholder="+880 1XXX-XXXXXX" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Address
                    <textarea name="address" rows="3" maxlength="255" placeholder="Institution address" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('address', $identity['address']) }}</textarea>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Footer / Copyright Text
                    <textarea name="footer_text" rows="3" maxlength="1000" placeholder="© {{ date('Y') }} Your Institution. All rights reserved." class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('footer_text', $identity['footer_text']) }}</textarea>
                </label>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
            <h2 class="font-extrabold text-slate-900">Login Page Branding</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm font-semibold text-slate-700">Login Heading
                    <input name="login_title" required maxlength="120" value="{{ old('login_title', $identity['login_title']) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Login Subtitle
                    <input name="login_message" maxlength="300" value="{{ old('login_message', $identity['login_message']) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </label>
            </div>
            <p class="text-xs text-slate-500">The login page uses the same site logo and favicon configured above.</p>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">Changes are saved as site-wide settings and recorded in the Admin audit log.</p>
            <button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">Save Site Branding</button>
        </div>
    </form>
</div>
@endsection
