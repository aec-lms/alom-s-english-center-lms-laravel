<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Appearance\SiteIdentityManager;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class SiteIdentityController extends Controller
{
    private const FIELDS = [
        'tagline' => 'branding.tagline',
        'footer_text' => 'branding.footer_text',
        'contact_email' => 'branding.contact_email',
        'contact_phone' => 'branding.contact_phone',
        'address' => 'branding.address',
        'login_title' => 'branding.login_title',
        'login_message' => 'branding.login_message',
    ];

    public function edit(SiteIdentityManager $identity): View
    {
        return view('admin.site-identity.index', ['identity' => $identity->values()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tagline' => ['nullable', 'string', 'max:180'],
            'footer_text' => ['nullable', 'string', 'max:1000'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'login_title' => ['required', 'string', 'max:120'],
            'login_message' => ['nullable', 'string', 'max:300'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:max_width=4000,max_height=4000', 'max:2048'],
            'favicon' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:ratio=1/1,max_width=512,max_height=512', 'max:512'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ]);

        $directory = public_path('uploads/site-branding');
        File::ensureDirectoryExists($directory);

        $old = app(SiteIdentityManager::class)->values();
        $newFiles = [];
        $deleteAfterCommit = [];
        $values = [];

        foreach (self::FIELDS as $field => $key) {
            $values[$key] = trim((string) ($validated[$field] ?? ''));
        }

        // An uploaded/replaced file takes precedence over a previously configured URL.
        foreach (['logo', 'favicon'] as $field) {
            if ($request->hasFile($field) || $request->boolean('remove_' . $field)) {
                $values['branding.' . $field . '_url'] = '';
            }
        }

        foreach (['logo', 'favicon'] as $field) {
            $pathKey = 'branding.' . $field . '_path';
            $oldPath = (string) ($old[$field . '_path'] ?? '');

            if ($request->boolean('remove_' . $field)) {
                $values[$pathKey] = '';
                if ($oldPath !== '') {
                    $deleteAfterCommit[] = $oldPath;
                }
                continue;
            }

            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $extension = strtolower((string) $file->guessExtension());
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    return back()->withInput()->withErrors([$field => 'Only JPG, PNG and WebP images are allowed.']);
                }

                $filename = $field . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
                $file->move($directory, $filename);
                $relativePath = '/uploads/site-branding/' . $filename;
                $newFiles[] = public_path(ltrim($relativePath, '/'));
                $values[$pathKey] = $relativePath;

                if ($oldPath !== '') {
                    $deleteAfterCommit[] = $oldPath;
                }
            }
        }

        try {
            DB::transaction(function () use ($values, $request): void {
                foreach ($values as $key => $value) {
                    SystemSetting::query()->updateOrCreate(
                        ['key' => $key],
                        ['value' => $value, 'is_encrypted' => false, 'updated_by' => auth()->id()]
                    );
                }

                AuditLog::query()->create([
                    'user_id' => auth()->id(),
                    'action' => 'site.identity.updated',
                    'entity_type' => 'site_identity',
                    'entity_id' => 'global',
                    'ip_address' => $request->ip(),
                    'metadata' => [
                        'fields' => array_keys($values),
                        'logo_uploaded' => $request->hasFile('logo'),
                        'favicon_uploaded' => $request->hasFile('favicon'),
                        'logo_removed' => $request->boolean('remove_logo'),
                        'favicon_removed' => $request->boolean('remove_favicon'),
                    ],
                ]);
            });
        } catch (\Throwable $exception) {
            foreach ($newFiles as $path) {
                if (File::exists($path)) {
                    File::delete($path);
                }
            }
            throw $exception;
        }

        foreach ($deleteAfterCommit as $relativePath) {
            if (!preg_match('~^/uploads/site-branding/[A-Za-z0-9._-]+$~', $relativePath)) {
                continue;
            }
            $path = public_path(ltrim($relativePath, '/'));
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        return redirect()->route('admin.site-identity.edit')->with('status', 'Site identity and branding settings saved.');
    }
}
