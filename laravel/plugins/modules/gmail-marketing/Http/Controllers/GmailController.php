<?php

declare(strict_types=1);

namespace Plugins\Modules\GmailMarketing\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\GmailMarketing\Models\EmailCampaign;

class GmailController extends Controller
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $config = [
            'gmail_address' => $this->plugins->getSetting('gmail-marketing', 'gmail_address', ''),
            'sender_name' => $this->plugins->getSetting('gmail-marketing', 'sender_name', "Alom's English Center"),
            'smtp_host' => $this->plugins->getSetting('gmail-marketing', 'smtp_host', 'smtp.gmail.com'),
            'smtp_port' => $this->plugins->getSetting('gmail-marketing', 'smtp_port', '587'),
            'has_secret' => (string) $this->plugins->getSetting('gmail-marketing', 'app_password', '') !== '',
        ];
        $campaigns = Schema::hasTable('email_campaigns') ? EmailCampaign::query()->latest()->limit(30)->get() : collect();

        return view('gmail-marketing::index', compact('config', 'campaigns'));
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gmail_address' => ['required', 'email', 'max:180'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'smtp_host' => ['required', 'string', 'max:120'],
            'smtp_port' => ['required', 'string', 'max:10'],
            'app_password' => ['nullable', 'string', 'max:255'],
        ]);

        $values = [
            'gmail_address' => trim($validated['gmail_address']),
            'sender_name' => trim((string) ($validated['sender_name'] ?? "Alom's English Center")),
            'smtp_host' => trim($validated['smtp_host']),
            'smtp_port' => trim($validated['smtp_port']),
        ];
        $secrets = [];
        if (!empty($validated['app_password'])) {
            $values['app_password'] = trim((string) $validated['app_password']);
            $secrets[] = 'app_password';
        }

        $this->plugins->saveSettings('gmail-marketing', $values, $secrets, auth()->id());

        return back()->with('status', 'Gmail Marketing SMTP settings saved securely.');
    }

    public function sendCampaign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'audience' => ['required', 'in:students,teachers,guardians,all_users,custom'],
            'custom_emails' => ['nullable', 'string', 'max:4000'],
            'body_html' => ['required', 'string'],
        ]);

        $emails = [];
        if ($validated['audience'] === 'custom') {
            $emails = array_values(array_filter(
                array_map('trim', preg_split('/[\s,;]+/', (string) ($validated['custom_emails'] ?? '')) ?: []),
                fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
            ));
        } else {
            $query = User::query()->whereNotNull('email')->where('email', '!=', '');
            if ($validated['audience'] !== 'all_users') {
                $roleSlug = match ($validated['audience']) {
                    'students' => 'student',
                    'teachers' => 'teacher',
                    'guardians' => 'guardian',
                    default => 'student',
                };
                $query->whereHas('roles', fn ($q) => $q->where('slug', $roleSlug));
            }
            $emails = $query->pluck('email')->all();
        }

        $gmail = (string) $this->plugins->getSetting('gmail-marketing', 'gmail_address', '');
        $appPass = (string) $this->plugins->getSetting('gmail-marketing', 'app_password', '');
        $host = (string) $this->plugins->getSetting('gmail-marketing', 'smtp_host', 'smtp.gmail.com');
        $port = (int) $this->plugins->getSetting('gmail-marketing', 'smtp_port', '587');
        $senderName = (string) $this->plugins->getSetting('gmail-marketing', 'sender_name', "Alom's English Center");
        $status = 'queued';

        if ($gmail !== '' && $appPass !== '' && count($emails) > 0) {
            try {
                Config::set('mail.mailers.gmail_marketing', [
                    'transport' => 'smtp',
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $port === 465 ? 'ssl' : 'tls',
                    'username' => $gmail,
                    'password' => $appPass,
                ]);
                foreach (array_slice($emails, 0, 100) as $recipient) {
                    Mail::mailer('gmail_marketing')->html($validated['body_html'], function ($msg) use ($recipient, $gmail, $senderName, $validated): void {
                        $msg->from($gmail, $senderName)->to($recipient)->subject($validated['subject']);
                    });
                }
                $status = 'sent';
            } catch (\Throwable) {
                $status = 'queued_retry';
            }
        }

        $campaign = EmailCampaign::query()->create([
            'subject' => $validated['subject'],
            'audience' => $validated['audience'],
            'body_html' => $validated['body_html'],
            'recipient_count' => count($emails),
            'status' => $status,
            'created_by' => auth()->id(),
        ]);

        $this->audit->log('gmail.campaign_created', 'email_campaign', (string) $campaign->id, [
            'audience' => $campaign->audience,
            'recipients' => $campaign->recipient_count,
        ]);

        return back()->with('status', "Email campaign '{$campaign->subject}' processed for {$campaign->recipient_count} recipient(s) [{$status}].");
    }

    public function destroy(EmailCampaign $campaign): RedirectResponse
    {
        $campaign->delete();
        return back()->with('status', 'Email campaign deleted.');
    }
}
