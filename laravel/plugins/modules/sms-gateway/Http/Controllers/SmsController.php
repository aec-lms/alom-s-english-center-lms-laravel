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

        if ($validated['audience'] === 'custom') {
            $rawNumbers = array_values(array_filter(array_map('trim', preg_split('/[\\s,;]+/', (string) ($validated['custom_numbers'] ?? '')) ?: [])));
            $numbers = [];
            $invalidNumbers = [];
            foreach ($rawNumbers as $rawNumber) {
                $normalized = $this->normalizeBangladeshNumber($rawNumber);
                if ($normalized === null) {
                    $invalidNumbers[] = $rawNumber;
                } else {
                    $numbers[] = $normalized;
                }
            }
            if ($invalidNumbers !== []) {
                return back()->withErrors([
                    'custom_numbers' => 'Invalid Bangladesh mobile number(s): ' . implode(', ', array_slice($invalidNumbers, 0, 5)),
                ])->withInput();
            }
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
            $numbers = $query->pluck('phone')
                ->map(fn ($phone) => $this->normalizeBangladeshNumber((string) $phone))
                ->filter()
                ->values()
                ->all();
        }

        $numbers = array_values(array_unique($numbers));
        $provider = (string) $this->plugins->getSetting('sms-gateway', 'provider', 'greenweb');
        $token = (string) $this->plugins->getSetting('sms-gateway', 'api_token', '');
        $status = 'not_configured';

        if ($numbers === []) {
            $status = 'no_valid_recipients';
        } elseif ($provider !== 'greenweb') {
            $status = 'provider_not_implemented';
        } elseif ($token === '') {
            $status = 'not_configured';
        } else {
            $sentCount = 0;
            $failedCount = 0;
            $unknownResponse = false;

            foreach (array_chunk($numbers, 100) as $batch) {
                try {
                    $response = Http::timeout(20)
                        ->asForm()
                        ->post('https://api.greenweb.com.bd/api.php', [
                            'token' => $token,
                            'to' => implode(',', $batch),
                            'message' => $validated['message'],
                        ]);
                    $response->throw();

                    $lines = preg_split('/\\r\\n|\\r|\\n/', trim((string) $response->body())) ?: [];
                    if (count($lines) !== count($batch)) {
                        $unknownResponse = true;
                        continue;
                    }

                    foreach ($lines as $line) {
                        if (str_starts_with(trim($line), 'Ok:')) {
                            $sentCount++;
                        } elseif (str_starts_with(trim($line), 'Error:')) {
                            $failedCount++;
                        } else {
                            $unknownResponse = true;
                        }
                    }
                } catch (\\Throwable) {
                    // A timeout can happen after provider acceptance; never auto-retry and risk duplicate SMS.
                    $unknownResponse = true;
                }
            }

            $status = $unknownResponse
                ? 'delivery_unknown'
                : ($sentCount === count($numbers)
                    ? 'sent'
                    : ($sentCount > 0 ? 'partially_sent' : 'failed'));
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
            'status' => $status,
        ]);

        $message = match ($status) {
            'sent' => 'Greenweb accepted all SMS messages.',
            'partially_sent' => 'Some SMS messages were accepted and some failed. Review the provider portal before retrying.',
            'failed' => 'Greenweb rejected all SMS messages.',
            'delivery_unknown' => 'Delivery could not be confirmed. Do not retry until you check the provider portal to avoid duplicate SMS.',
            'provider_not_implemented' => "The selected provider '{$provider}' is not connected yet. No SMS was sent.",
            'no_valid_recipients' => 'No valid Bangladesh mobile numbers were found. No SMS was sent.',
            default => 'SMS was not sent because Greenweb credentials are not configured.',
        };

        return back()->with('status', $message);
    }

    private function normalizeBangladeshNumber(string $number): ?string
    {
        $digits = preg_replace('/\\D+/', '', $number) ?? '';
        if (preg_match('/^01[3-9][0-9]{8}$/', $digits)) {
            return '+88' . $digits;
        }
        if (preg_match('/^8801[3-9][0-9]{8}$/', $digits)) {
            return '+' . $digits;
        }

        return null;
    }

    public function destroy(SmsCampaign $campaign): RedirectResponse
    {
        $campaign->delete();
        return back()->with('status', 'SMS campaign log deleted.');
    }
}
