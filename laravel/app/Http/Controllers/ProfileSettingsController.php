<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileSettingsController extends Controller
{
    private const SOCIAL_PLATFORMS = [
        'facebook', 'instagram', 'youtube', 'linkedin', 'tiktok',
        'x', 'whatsapp', 'telegram', 'github',
    ];

    public function showPublic(string $username): View
    {
        $user = \App\Models\User::query()
            ->where('username', strtolower($username))
            ->where('profile_is_public', true)
            ->firstOrFail();

        return view('profile.public', ['profile' => $user]);
    }

    public function edit(Request $request): View
    {
        return view('profile.settings', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        if (is_string($request->input('username'))) {
            $request->merge(['username' => strtolower(trim($request->input('username')))]);
        }

        $validated = $request->validate([
            'username' => [
                'nullable', 'string', 'min:3', 'max:40',
                'regex:/^[A-Za-z0-9_]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'display_name' => ['nullable', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:1200'],
            'location' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'regex:/^https?:\/\//i', 'max:2048'],
            'profile_is_public' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'social_links' => ['nullable', 'array:facebook,instagram,youtube,linkedin,tiktok,x,whatsapp,telegram,github'],
            'social_links.facebook' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'social_links.instagram' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'social_links.youtube' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'social_links.linkedin' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'social_links.tiktok' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'social_links.x' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'social_links.whatsapp' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'social_links.telegram' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'social_links.github' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
        ]);

        $user->username = isset($validated['username']) && $validated['username'] !== ''
            ? strtolower($validated['username'])
            : null;
        $user->display_name = $validated['display_name'] ?? null;
        $user->headline = $validated['headline'] ?? null;
        $user->bio = $validated['bio'] ?? null;
        $user->location = $validated['location'] ?? null;
        $user->website = $validated['website'] ?? null;
        $user->profile_is_public = $request->boolean('profile_is_public');

        $links = [];
        foreach (self::SOCIAL_PLATFORMS as $platform) {
            $url = trim((string) data_get($validated, 'social_links.' . $platform, ''));
            if ($url !== '') {
                $links[$platform] = $url;
            }
        }
        $user->social_links = $links;

        $replacedFiles = [];
        foreach (['avatar' => 'avatar_path', 'cover' => 'cover_path'] as $input => $column) {
            if (!$request->hasFile($input)) {
                continue;
            }

            $file = $request->file($input);
            abort_unless($file && $file->isValid(), 422, 'The uploaded image is invalid.');
            $newPath = $file->store('users/' . ($input === 'avatar' ? 'avatars' : 'covers') . '/' . $user->id, 'public');
            if (!$newPath) {
                return back()->withErrors([$input => 'Unable to store the image. Please try again.'])->withInput();
            }

            $oldPath = $user->{$column};
            $user->{$column} = $newPath;
            if (is_string($oldPath) && str_starts_with($oldPath, 'users/' . ($input === 'avatar' ? 'avatars' : 'covers') . '/' . $user->id . '/')) {
                $replacedFiles[] = $oldPath;
            }
        }

        $user->save();
        foreach ($replacedFiles as $oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return redirect()->route('profile.settings.edit')->with('status', 'Your profile has been updated successfully.');
    }
}
