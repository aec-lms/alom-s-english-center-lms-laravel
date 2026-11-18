<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Models\AuditLog;
use App\Models\DeviceSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileSettingsController extends Controller
{
    private const SOCIAL_PLATFORMS = [
        'facebook', 'instagram', 'youtube', 'linkedin', 'tiktok',
        'x', 'whatsapp', 'telegram', 'github',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function showPublic(string $username): View
    {
        $user = User::query()->where('username', strtolower($username))
            ->where('profile_is_public', true)->firstOrFail();

        return view('profile.public', ['profile' => $user]);
    }

    public function activity(Request $request): View
    {
        $activities = AuditLog::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->whereIn('action', ['profile.updated', 'security.password_changed'])
            ->latest()->paginate(20);

        return view('profile.activity', compact('activities'));
    }

    public function media(Request $request, string $username, string $type): StreamedResponse
    {
        $user = User::query()->where('username', strtolower($username))->firstOrFail();
        abort_unless($user->profile_is_public || $request->user()?->is($user), 404);

        return $this->streamProfileImage($user, $type, (bool) $user->profile_is_public);
    }

    public function myMedia(Request $request, string $type): StreamedResponse
    {
        return $this->streamProfileImage($request->user(), $type, false);
    }

    private function streamProfileImage(User $user, string $type, bool $isPublic): StreamedResponse
    {
        $column = match ($type) {
            'avatar' => 'avatar_path',
            'cover' => 'cover_path',
            default => abort(404),
        };
        $path = $user->{$column};
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, basename($path), [
            'Cache-Control' => $isPublic ? 'public, max-age=300' : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function edit(Request $request): View
    {
        return view('profile.settings', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user, 401);

        if (is_string($request->input('username'))) {
            $request->merge(['username' => strtolower(trim($request->input('username')))]);
        }

        $validated = $request->validate([
            'username' => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'display_name' => ['nullable', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:1200'],
            'location' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'],
            'profile_is_public' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'social_links' => ['nullable', 'array:facebook,instagram,youtube,linkedin,tiktok,x,whatsapp,telegram,github'],
            'social_links.facebook' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'social_links.instagram' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'social_links.youtube' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'social_links.linkedin' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'social_links.tiktok' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'social_links.x' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'social_links.whatsapp' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'social_links.telegram' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'social_links.github' => ['nullable', 'url', 'starts_with:https://,http://', 'max:2048'],
            'current_password' => ['nullable', 'required_with:new_password', 'current_password'],
            'new_password' => ['nullable', 'required_with:current_password', 'string', 'min:12', 'confirmed'],
        ]);

        $changedFields = [];
        foreach (['username', 'display_name', 'headline', 'bio', 'location', 'website'] as $field) {
            $value = $validated[$field] ?? null;
            if ($user->{$field} !== $value) {
                $changedFields[] = $field;
            }
            $user->{$field} = $value;
        }

        $publicVisibility = $request->boolean('profile_is_public');
        if ((bool) $user->profile_is_public !== $publicVisibility) {
            $changedFields[] = 'profile_is_public';
        }
        $user->profile_is_public = $publicVisibility;

        $links = [];
        foreach (self::SOCIAL_PLATFORMS as $platform) {
            $url = trim((string) data_get($validated, 'social_links.' . $platform, ''));
            if ($url !== '') {
                $links[$platform] = $url;
            }
        }
        if (($user->social_links ?? []) !== $links) {
            $changedFields[] = 'social_links';
        }
        $user->social_links = $links;

        $replacedFiles = [];
        foreach (['avatar' => 'avatar_path', 'cover' => 'cover_path'] as $input => $column) {
            if (!$request->hasFile($input)) {
                continue;
            }

            $file = $request->file($input);
            abort_unless($file && $file->isValid(), 422, 'The uploaded image is invalid.');
            $directory = 'users/' . ($input === 'avatar' ? 'avatars' : 'covers') . '/' . $user->id;
            $newPath = $file->store($directory, 'local');
            if (!$newPath) {
                return back()->withErrors([$input => 'Unable to store the image. Please try again.'])->withInput();
            }

            $oldPath = $user->{$column};
            $user->{$column} = $newPath;
            $changedFields[] = $input;
            if (is_string($oldPath) && str_starts_with($oldPath, $directory . '/')) {
                $replacedFiles[] = $oldPath;
            }
        }

        $passwordChanged = filled($validated['new_password'] ?? null);
        if ($passwordChanged) {
            $user->password = $validated['new_password'];
        }

        $user->save();

        foreach ($replacedFiles as $oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        if ($changedFields !== []) {
            $this->audit->log('profile.updated', 'user', (string) $user->id, [
                'changed_fields' => array_values(array_unique($changedFields)),
            ], (int) $user->id);
        }

        if ($passwordChanged) {
            $otherSessionsRevoked = false;
            if (Schema::hasTable('device_sessions')) {
                DeviceSession::query()
                    ->where('user_id', $user->id)
                    ->where('session_hash', '!=', DeviceSession::currentHash($request))
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                        'revoke_reason' => 'Password changed by account owner',
                        'updated_at' => now(),
                    ]);
                $otherSessionsRevoked = true;
            }

            $this->audit->log('security.password_changed', 'user', (string) $user->id, [
                'other_sessions_revoked' => $otherSessionsRevoked,
            ], (int) $user->id);
        }

        return redirect()->route('profile.settings.edit')->with('status', 'Your profile has been updated successfully.');
    }
}
