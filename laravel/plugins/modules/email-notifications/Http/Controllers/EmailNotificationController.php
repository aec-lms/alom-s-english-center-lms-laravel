<?php

declare(strict_types=1);

namespace Plugins\Modules\EmailNotifications\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Plugins\Modules\EmailNotifications\Models\EmailLog;
use Plugins\Modules\EmailNotifications\Models\EmailTemplate;

class EmailNotificationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PluginManager $plugins,
    ) {}

    public function index(): View
    {
        $templates = Schema::hasTable('email_templates')
            ? EmailTemplate::query()->orderBy('template_key')->get()
            : collect();

        $logs = Schema::hasTable('email_logs')
            ? EmailLog::query()->orderByDesc('id')->limit(40)->get()
            : collect();

        return view('email-notifications::index', compact('templates', 'logs'));
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'template_key' => ['required', 'string', 'max:80', 'alpha_dash'],
            'subject' => ['required', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:10000'],
        ]);

        $template = EmailTemplate::query()->updateOrCreate(
            ['template_key' => $validated['template_key']],
            [
                'subject' => $validated['subject'],
                'body_html' => $validated['body_html'],
                'is_active' => true,
            ]
        );

        $this->audit->log('email.template_saved', 'email_template', (string) $template->id);

        return back()->with('status', "Email template '{$template->template_key}' saved.");
    }

    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'recipient_email' => ['required', 'email', 'max:160'],
            'template_key' => ['nullable', 'string', 'max:80', 'exists:email_templates,template_key'],
            'subject' => ['nullable', 'required_without:template_key', 'string', 'max:200'],
            'message' => ['nullable', 'required_without:template_key', 'string', 'max:10000'],
        ]);

        $template = !empty($validated['template_key'])
            ? EmailTemplate::query()->where('template_key', $validated['template_key'])->where('is_active', true)->firstOrFail()
            : null;
        $subject = $template?->subject ?? (string) ($validated['subject'] ?? '');
        $html = $template?->body_html ?? nl2br(e((string) ($validated['message'] ?? '')));

        $smtpHost = trim((string) $this->plugins->getSetting('email-notifications', 'smtp_host', ''));
        $smtpPort = (int) $this->plugins->getSetting('email-notifications', 'smtp_port', '587');
        $smtpUsername = trim((string) $this->plugins->getSetting('email-notifications', 'smtp_username', ''));
        $smtpPassword = (string) $this->plugins->getSetting('email-notifications', 'smtp_password', '');
        $fromAddress = trim((string) $this->plugins->getSetting('email-notifications', 'from_address', config('mail.from.address', '')));
        $status = 'not_configured';
        $dispatchedAt = null;

        $credentialsAreConsistent = ($smtpUsername === '') === ($smtpPassword === '');
        if ($smtpHost !== '' && $smtpPort >= 1 && $smtpPort <= 65535
            && filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
            && $credentialsAreConsistent) {
            try {
                $mailer = Mail::build([
                    'transport' => 'smtp',
                    'scheme' => $smtpPort === 465 ? 'smtps' : 'smtp',
                    'host' => $smtpHost,
                    'port' => $smtpPort,
                    'username' => $smtpUsername !== '' ? $smtpUsername : null,
                    'password' => $smtpPassword !== '' ? $smtpPassword : null,
                    'timeout' => 10,
                    'local_domain' => config('mail.mailers.smtp.local_domain'),
                ]);

                $mailer->html($html, function ($mail) use ($validated, $subject, $fromAddress): void {
                    $mail->to(strtolower($validated['recipient_email']))
                        ->from($fromAddress, (string) config('mail.from.name', config('app.name')))
                        ->subject($subject);
                });
                $status = 'sent';
                $dispatchedAt = now();
            } catch (\Throwable) {
                $status = 'failed';
            }
        }

        $log = EmailLog::query()->create([
            'recipient_email' => strtolower($validated['recipient_email']),
            'subject' => $subject,
            'body_excerpt' => mb_substr(strip_tags($html), 0, 240),
            'template_key' => $template?->template_key,
            'status' => $status,
            'dispatched_at' => $dispatchedAt,
        ]);

        $this->audit->log('email.dispatched', 'email_log', (string) $log->id, ['status' => $status]);

        $message = match ($status) {
            'sent' => 'Email accepted by the configured SMTP server.',
            'failed' => 'SMTP delivery failed. Check the provider settings and delivery logs.',
            default => 'Email was not sent because SMTP is not fully configured.',
        };

        return back()->with('status', $message);
    }
}
