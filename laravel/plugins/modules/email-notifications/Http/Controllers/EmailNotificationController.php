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
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'template_key' => ['nullable', 'string', 'max:80'],
        ]);

        $smtpHost = (string) $this->plugins->getSetting('email-notifications', 'smtp_host', '');
        $status = 'queued';

        if ($smtpHost !== '') {
            try {
                Mail::raw($validated['message'], function ($mail) use ($validated): void {
                    $mail->to($validated['recipient_email'])->subject($validated['subject']);
                });
                $status = 'sent';
            } catch (\Throwable) {
                $status = 'failed';
            }
        }

        $log = EmailLog::query()->create([
            'recipient_email' => strtolower($validated['recipient_email']),
            'subject' => $validated['subject'],
            'body_excerpt' => mb_substr(strip_tags($validated['message']), 0, 240),
            'template_key' => $validated['template_key'] ?? null,
            'status' => $status,
            'dispatched_at' => now(),
        ]);

        $this->audit->log('email.dispatched', 'email_log', (string) $log->id, ['status' => $status]);

        return back()->with('status', "Email dispatch recorded ({$status}).");
    }
}
