<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SetupWizardController extends Controller
{
    private const STEPS = ['institution', 'branding', 'academic', 'payments', 'finish'];

    public function index(Request $request): View
    {
        $state = $this->state();
        $step = (string) $request->query('step', $state['current_step'] ?? 'institution');
        abort_unless(in_array($step, self::STEPS, true), 404);
        $assets = collect();
        if (Schema::hasTable('media_assets')) {
            $assets = DB::table('media_assets')->where('disk', 'public')->where('mime_type', 'like', 'image/%')
                ->latest()->limit(100)->get(['id', 'original_name', 'path']);
        }
        $logoLibraryUrl = $this->publicAssetUrl((string) ($state['data']['logo_asset_id'] ?? ''));
        $faviconLibraryUrl = $this->publicAssetUrl((string) ($state['data']['favicon_asset_id'] ?? ''));
        return view('admin.setup-wizard.index', compact('state', 'step', 'assets', 'logoLibraryUrl', 'faviconLibraryUrl'));
    }

    public function save(Request $request, string $step): RedirectResponse
    {
        abort_unless(in_array($step, self::STEPS, true), 404);
        if ($step === 'finish') {
            $state = $this->state();
            $required = ['institution.name', 'academic.name', 'academic.starts_at', 'academic.ends_at'];
            foreach ($required as $key) {
                if (!isset($state['data'][$key]) || $state['data'][$key] === '') {
                    throw ValidationException::withMessages(['setup' => 'প্রতিষ্ঠানের নাম ও শিক্ষাবর্ষের তথ্য সম্পূর্ণ করুন।']);
                }
            }
            SystemSetting::query()->updateOrCreate(['key' => 'setup_wizard.completed'], ['value' => '1', 'updated_by' => auth()->id()]);
            AuditLog::query()->create(['user_id' => auth()->id(), 'action' => 'setup.wizard.completed', 'entity_type' => 'setup', 'entity_id' => 'global', 'ip_address' => $request->ip(), 'metadata' => ['steps' => self::STEPS]]);
            return redirect()->route('admin.dashboard')->with('status', 'সিস্টেম সেটআপ সম্পন্ন হয়েছে।');
        }

        $validated = match ($step) {
            'institution' => $request->validate([
                'name' => ['required', 'string', 'max:160'],
                'website' => ['nullable', 'url', 'max:255'],
                'locale' => ['required', 'in:bn,en'],
                'timezone' => ['required', 'timezone'],
                'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            ]),
            'branding' => $request->validate([
                'logo_url' => ['nullable', 'url', 'max:2048'],
                'logo_asset_id' => ['nullable', 'integer'],
                'favicon_url' => ['nullable', 'url', 'max:2048'],
                'favicon_asset_id' => ['nullable', 'integer'],
                'primary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'secondary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/],
            ]),
            'academic' => $request->validate([
                'academic_name' => ['required', 'string', 'max:120'],
                'starts_at' => ['required', 'date'],
                'ends_at' => ['required', 'date', 'after:starts_at'],
            ]),
            'payments' => $request->validate([
                'bkash_enabled' => ['nullable', 'boolean'], 'bkash_number' => ['nullable', 'string', 'max:40'],
                'rocket_enabled' => ['nullable', 'boolean'], 'rocket_number' => ['nullable', 'string', 'max:40'],
                'bank_enabled' => ['nullable', 'boolean'], 'bank_name' => ['nullable', 'string', 'max:120'],
                'bank_account' => ['nullable', 'string', 'max:120'], 'manual_enabled' => ['nullable', 'boolean'],
                'payment_instructions' => ['nullable', 'string', 'max:2000'],
            ]),
        };

        $state = $this->state();
        $data = $state['data'];
        $mapping = match ($step) {
            'institution' => ['name' => 'institution.name', 'website' => 'institution.website', 'locale' => 'localization.locale', 'timezone' => 'localization.timezone', 'currency' => 'finance.default_currency'],
            'branding' => ['logo_url' => 'branding.logo_url', 'logo_asset_id' => 'branding.logo_asset_id', 'favicon_url' => 'branding.favicon_url', 'favicon_asset_id' => 'branding.favicon_asset_id', 'primary' => 'branding.primary', 'secondary' => 'branding.secondary', 'accent' => 'branding.accent'],
            'academic' => ['academic_name' => 'academic.name', 'starts_at' => 'academic.starts_at', 'ends_at' => 'academic.ends_at'],
            'payments' => ['bkash_enabled' => 'payments.bkash.enabled', 'bkash_number' => 'payments.bkash.number', 'rocket_enabled' => 'payments.rocket.enabled', 'rocket_number' => 'payments.rocket.number', 'bank_enabled' => 'payments.bank.enabled', 'bank_name' => 'payments.bank.name', 'bank_account' => 'payments.bank.account', 'manual_enabled' => 'payments.manual.enabled', 'payment_instructions' => 'payments.instructions'],
        };
        foreach ($mapping as $field => $key) {
            $value = $validated[$field] ?? (str_ends_with($field, '_enabled') ? false : '');
            $data[$key] = $value;
            SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'is_encrypted' => false, 'updated_by' => auth()->id()]);
        }
        if ($step === 'institution') {
            config(['app.name' => $validated['name'], 'app.locale' => $validated['locale'], 'app.timezone' => $validated['timezone'], 'lms.default_currency' => strtoupper($validated['currency'])]);
            date_default_timezone_set($validated['timezone']);
        }
        if ($step === 'branding') {
            foreach (['primary', 'secondary', 'accent'] as $token) {
                SystemSetting::query()->updateOrCreate(['key' => 'appearance.global.' . $token], ['value' => $validated[$token], 'updated_by' => auth()->id()]);
            }
        }
        $next = self::STEPS[array_search($step, self::STEPS, true) + 1];
        $state['data'] = $data;
        $state['completed_steps'] = array_values(array_unique([...$state['completed_steps'], $step]));
        $state['current_step'] = $next;
        SystemSetting::query()->updateOrCreate(['key' => 'setup_wizard.progress'], ['value' => json_encode($state, JSON_THROW_ON_ERROR), 'updated_by' => auth()->id()]);
        return redirect()->route('admin.setup.index', ['step' => $next])->with('status', 'এই ধাপের তথ্য সংরক্ষণ হয়েছে। পরে এখান থেকেই চালিয়ে যেতে পারবেন।');
    }

    private function state(): array
    {
        $raw = SystemSetting::query()->where('key', 'setup_wizard.progress')->value('value');
        $state = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($state) ? array_replace(['current_step' => 'institution', 'completed_steps' => [], 'data' => []], $state) : ['current_step' => 'institution', 'completed_steps' => [], 'data' => []];
    }

    private function publicAssetUrl(string $id): ?string
    {
        if ($id === '' || !Schema::hasTable('media_assets')) return null;
        $asset = DB::table('media_assets')->where('id', $id)->where('disk', 'public')->where('mime_type', 'like', 'image/%')->first();
        return $asset ? Storage::disk('public')->url($asset->path) : null;
    }
}
