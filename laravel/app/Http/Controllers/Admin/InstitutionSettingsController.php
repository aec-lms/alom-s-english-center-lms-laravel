<?php
declare(strict_types=1);
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
class InstitutionSettingsController extends Controller
{
    private const KEYS = [
        'name' => 'institution.name', 'website' => 'institution.website',
        'country_code' => 'institution.country_code', 'locale' => 'localization.locale',
        'timezone' => 'localization.timezone', 'currency' => 'finance.default_currency',
    ];
    public function edit(): View
    {
        $values = [];
        foreach (self::KEYS as $field => $key) {
            $values[$field] = (string) (SystemSetting::query()->where('key', $key)->value('value') ?? '');
        }
        return view('admin.institution-settings.edit', compact('values'));
    }
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'website' => ['nullable', 'url', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'locale' => ['required', 'string', 'max:35', 'regex:/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/'],
            'timezone' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        if (!in_array($validated['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            && $validated['timezone'] !== 'UTC') {
            return back()->withInput()->withErrors(['timezone' => 'Select a valid IANA timezone.']);
        }
        $validated['country_code'] = strtoupper($validated['country_code']);
        $validated['locale'] = str_replace('_', '-', $validated['locale']);
        $validated['currency'] = strtoupper($validated['currency']);
        DB::transaction(function () use ($validated, $request): void {
            foreach (self::KEYS as $field => $key) {
                SystemSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $validated[$field] ?? '', 'is_encrypted' => false, 'updated_by' => auth()->id()]
                );
            }
            AuditLog::query()->create([
                'user_id' => auth()->id(), 'action' => 'institution.settings.updated',
                'entity_type' => 'institution', 'entity_id' => 'global',
                'ip_address' => $request->ip(),
                'metadata' => ['fields' => array_keys($validated)],
            ]);
        });
        config([
            'app.name' => $validated['name'],
            'app.url' => $validated['website'] ?: config('app.url'),
            'app.locale' => $validated['locale'],
            'lms.default_locale' => $validated['locale'],
            'app.timezone' => $validated['timezone'],
            'lms.default_currency' => $validated['currency'],
        ]);
        date_default_timezone_set($validated['timezone']);
        return redirect()->route('admin.institution-settings.edit')->with('status', 'Institution and regional settings saved.');
    }
}
