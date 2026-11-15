<?php

declare(strict_types=1);

namespace Plugins\Modules\SmsGateway\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\SmsGateway\Models\SmsCampaign;

class SmsController extends Controller
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $config = [
            'provider' => $this->plugins->getSetting('sms-gateway', 'provider', 'greenweb'),
            'sender_id' => $this->plugins->getSetting('sms-gateway', 'sender_id', 'AECLMS'),
            'api_url' => $this->plugins->getSetting('sms-gateway', 'api_url', ''),
            'has_token' => (string) $this->plugins->getSetting('sms-gateway', 'api_token', '') !== '',
        ];
        $campaigns = Schema::hasTable('sms_campaigns') ? SmsCampaign::query()->latest()->limit(30)->get() : collect();

        return view('sms-gateway::index', compact('config', 'campaigns'));
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:greenweb,twilio,sslwireless,custom'],
            'sender_id' => ['nullable', 'string', 'max:40'],
            'api_url' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:255'],
        ]);

        $values = [
            'provider' => $validated['provider'],
            'sender_id' => trim((string) ($validated['sender_id'] ?? 'AECLMS')),
            'api_url' => trim((string) ($validated['api_url'] ?? '')),
        ];
        $secrets = [];
        if (!empty($validated['api_token'])) {
            $values['api_token'] = trim((string) $validated['api_token']);
            $secrets[] = 'api_token';
        }

        $this->plugins->saveSettings('sms-gateway', $values, $secrets, auth()->id());

        return back()->with('status', 'SMS Gateway provider settings saved securely.');
    }

    public function sendCampaign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'audience' => ['required', 'in:students,teachers,guardians,all_users,custom'],
            'custom_numbers' => ['nullable', 'string', 'max:2000'],
            'message' => ['required', 'string', 'max:640'],
        ]);

        $numbers = [];
        if ($validated['audience'] === 'custom') {
            $numbers = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($validated['custom_numbers'] ?? '')) ?: [])));
        } else {
            $query = User::query()->whereNotNull('phone')->where('phone', '!=', '');
            if ($validated['audience'] !== 'all_users') {
                $roleSlug = match ($validated['audience']) {
                    'students' => 'student',
                    'teachers' => 'teacher',
                    'guardians' => 'guardian',
                    default => 'student',
                };
                $query->whereHas('roles', fn ($q) => $q->where('slug', $roleSlug));
            }
            $numbers = $query->pluck('phone')->all();
        }

        $provider = (string) $this->plugins->getSetting('sms-gateway', 'provider', 'greenweb');
        $token = (string) $this->plugins->getSetting('sms-gateway', 'api_token', '');
        $status = 'queued';

        if ($token !== '' && count($numbers) > 0 && $provider === 'greenweb') {
            try {
                Http::timeout(10)->asForm()->post('https://api.greenweb.com.bd/api.php', [
                    'token' => $token,
                    'to' => implode(',', array_slice($numbers, 0, 100)),
                    'message' => $validated['message'],
                ]);
                $status = 'sent';
            } catch (\Throwable) {
                $status = 'queued_retry';
            }
        }

        $campaign = SmsCampaign::query()->create([
            'title' => $validated['title'],
            'audience' => $validated['audience'],
            'message' => $validated['message'],
            'recipient_count' => count($numbers),
            'provider' => $provider,
            'status' => $status,
            'created_by' => auth()->id(),
        ]);

        $this->audit->log('sms.campaign_created', 'sms_campaign', (string) $campaign->id, [
            'audience' => $campaign->audience,
            'recipients' => $campaign->recipient_count,
        ]);

        return back()->with('status', "SMS Campaign '{$campaign->title}' processed for {$campaign->recipient_count} recipient(s) [{$status}].");
    }

    public function destroy(SmsCampaign $campaign): RedirectResponse
    {
        $campaign->delete();
        return back()->with('status', 'SMS campaign log deleted.');
    }
}
