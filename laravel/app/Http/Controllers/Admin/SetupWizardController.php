<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Admin;

use App\\Http\\Controllers\\Controller;
use App\\Models\\AuditLog;
use App\\Models\\SystemSetting;
use DateTimeZone;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Crypt;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\View\\View;

class SetupWizardController extends Controller
{
    private const STEPS = ['institution', 'branding', 'academic', 'payments', 'review'];

    public function index(Request $request): View|RedirectResponse
    {
        $step = (string) $request->query('step', 'institution');
        abort_unless(in_array($step, self::STEPS, true), 404);
        $settings = SystemSetting::query()->pluck('value', 'key')->all();
        $values = [];
        foreach ($settings as $key => $value) {
            if (str_starts_with($key, 'setup.payment.') && str_contains($key, '.secret')) {
                try { $value = Crypt::decryptString((string) $value); } catch (\\Throwable) { $value = ''; }
            }
            $values[$key] = $value;
        }
        $mediaAssets = collect();
        if (Schema::hasTable('media_assets')) {
            $mediaAssets = DB::table('media_assets')->where('mime_type', 'like', 'image/%')
                ->where('mime_type', '<>', 'image/svg+xml')->latest()->limit(100)->get();
            $mediaAssets = $mediaAssets->map(function ($asset) {
                $asset->url = $asset->disk === 'public' ? \\Illuminate\\Support\\Facades\\Storage::disk('public')->url($asset->path) : null;
                return $asset;
            })->filter(fn ($asset) => filled($asset->url));
        }
        $completed = (bool) SystemSetting::query()->where('key', 'setup.wizard.completed')->value('value');
        return view('admin.setup-wizard.index', compact('step', 'values', 'mediaAssets', 'completed'));
    }

    public function save(Request $request, string $step): RedirectResponse
    {
        abort_unless(in_array($step, self::STEPS, true) && $step !== 'review', 404);
        $rules = match ($step) {
            'institution' => [
                'institution_name' => ['required', 'string', 'max:160'],
                'institution_website' => ['nullable', 'url', 'max:255'],
                'institution_country' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
                'institution_locale' => ['required', 'in:bn,bn-BD,en,en-US'],
                'institution_timezone' => ['required', 'timezone'],
                'institution_currency' => ['required', 'in:BDT,USD,GBP,EUR'],
            ],
            'branding' => [
                'logo_url' => ['nullable', 'url', 'max:2048'], 'logo_media_id' => ['nullable', 'integer'],
                'favicon_url' => ['nullable', 'url', 'max:2048'], 'favicon_media_id' => ['nullable', 'integer'],
                'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            ],
            'academic' => [
                'academic_year_name' => ['required', 'string', 'max:100'],
                'academic_year_start' => ['required', 'date'],
                'academic_year_end' => ['required', 'date', 'after:academic_year_start'],
            ],
            'payments' => [
                'bkash_enabled' => ['nullable', 'boolean'], 'bkash_number' => ['nullable', 'string', 'max:40'],
                'bkash_secret' => ['nullable', 'string', 'max:255'],
                'rocket_enabled' => ['nullable', 'boolean'], 'rocket_number' => ['nullable', 'string', 'max:40'],
                'rocket_secret' => ['nullable', 'string', 'max:255'],
                'bank_enabled' => ['nullable', 'boolean'], 'bank_name' => ['nullable', 'string', 'max:160'],
                'bank_account_name' => ['nullable', 'string', 'max:160'], 'bank_account_number' => ['nullable', 'string', 'max:80'],
                'bank_branch' => ['nullable', 'string', 'max:120'], 'manual_enabled' => ['nullable', 'boolean'],
                'manual_instructions' => ['nullable', 'string', 'max:2000'],
            ],
        };
        $data = $request->validate($rules);
        if ($step === 'branding') {
            foreach (['logo', 'favicon'] as $kind) {
                $id = $data[$kind.'_media_id'] ?? null;
                if ($id) {
                    abort_unless(Schema::hasTable('media_assets'), 422, 'Media Library is not available.');
                    $asset = DB::table('media_assets')->where('id', $id)->where('mime_type', 'like', 'image/%')->where('mime_type', '<>', 'image/svg+xml')->first();
                    abort_unless($asset && $asset->disk === 'public', 422, 'Select a public image from LMS Media Library.');
                    $data[$kind.'_url'] = \\Illuminate\\Support\\Facades\\Storage::disk('public')->url($asset->path);
                }
            }
        }
        if ($step === 'payments') {
            foreach (['bkash_enabled', 'rocket_enabled', 'bank_enabled', 'manual_enabled'] as $flag) $data[$flag] = $request->boolean($flag) ? '1' : '0';
        }
        DB::transaction(function () use ($data, $step, $request): void {
            foreach ($data as $field => $value) {
                if (str_ends_with($field, '_media_id')) continue;
                $key = match ($field) {
                    'institution_name' => 'institution.name', 'institution_website' => 'institution.website',
                    'institution_country' => 'institution.country_code', 'institution_locale' => 'localization.locale',
                    'institution_timezone' => 'localization.timezone', 'institution_currency' => 'finance.default_currency',
                    'logo_url' => 'branding.logo_url', 'favicon_url' => 'branding.favicon_url',
                    'primary_color' => 'branding.primary_color', 'secondary_color' => 'branding.secondary_color', 'accent_color' => 'branding.accent_color',
                    'academic_year_name' => 'academic.current_year_name', 'academic_year_start' => 'academic.current_year_start',
                    'academic_year_end' => 'academic.current_year_end',
                    default => 'setup.payment.'.str_replace('_', '.', $field),
                };
                $secret = in_array($field, ['bkash_secret', 'rocket_secret'], true);
                $stored = $secret && filled($value) ? Crypt::encryptString((string) $value) : (string) ($value ?? '');
                SystemSetting::query()->updateOrCreate(['key' => $key], [
                    'value' => $stored, 'is_encrypted' => $secret, 'updated_by' => $request->user()->id,
                ]);
            }
            SystemSetting::query()->updateOrCreate(['key' => 'setup.wizard.step.'.$step], [
                'value' => 'completed', 'is_encrypted' => false, 'updated_by' => $request->user()->id,
            ]);
            AuditLog::query()->create([
                'user_id' => $request->user()->id, 'action' => 'setup.wizard.step.saved',
                'entity_type' => 'setup_wizard', 'entity_id' => $step,
                'ip_address' => $request->ip(), 'metadata' => ['step' => $step, 'fields' => array_keys($data)],
            ]);
        });
        if ($step === 'institution') {
            config(['app.name' => $data['institution_name'], 'app.locale' => $data['institution_locale'], 'app.timezone' => $data['institution_timezone'], 'lms.default_currency' => $data['institution_currency']]);
        }
        $next = self::STEPS[array_search($step, self::STEPS, true) + 1];
        return redirect()->route('admin.setup-wizard.index', ['step' => $next])->with('status', 'ধাপের তথ্য সংরক্ষণ হয়েছে। পরে আবার এখান থেকে চালিয়ে যেতে পারবেন।');
    }

    public function complete(Request $request): RedirectResponse
    {
        $required = ['institution.name', 'academic.current_year_name', 'academic.current_year_start', 'academic.current_year_end'];
        $missing = SystemSetting::query()->whereIn('key', $required)->pluck('key')->all();
        if (count($missing) !== count($required)) {
            return redirect()->route('admin.setup-wizard.index')->withErrors(['setup' => 'প্রতিষ্ঠান ও শিক্ষাবর্ষের তথ্য আগে সম্পন্ন করুন।']);
        }
        SystemSetting::query()->updateOrCreate(['key' => 'setup.wizard.completed'], [
            'value' => '1', 'is_encrypted' => false, 'updated_by' => $request->user()->id,
        ]);
        app(\\App\\Core\\Security\\AuditLogger::class)->log('setup.wizard.completed', 'setup_wizard', 'global', []);
        return redirect()->route('admin.dashboard')->with('status', 'LMS Setup Wizard সম্পন্ন হয়েছে।');
    }
}
