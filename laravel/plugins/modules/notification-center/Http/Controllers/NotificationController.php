<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;

class NotificationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PluginManager $plugins,
    ) {}

    public function index(): View
    {
        $actor = request()->user();
        $this->ensureAssignedBranch($actor);

        $notificationQuery = LmsNotification::query()->with('user')->latest();
        $userQuery = User::query()->select('id', 'name', 'email', 'phone')->orderBy('name');

        if ($actor && $actor->hasRole('branch_manager')) {
            $notificationQuery->where('branch_id', $actor->branch_id);
            $userQuery->where('branch_id', $actor->branch_id);
        }

        $notifications = $notificationQuery->paginate(25);
        $users = $userQuery->limit(500)->get();

        return view('notification-center::index', compact('notifications', 'users'));
    }

    public function dispatch(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureAssignedBranch($actor);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'channel' => ['required', 'in:in_app,email,sms,push'],
            'title' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $recipient = User::query()->findOrFail((int) $validated['user_id']);
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless((int) $recipient->branch_id === (int) $actor->branch_id, 404);
        }

        $status = match ($validated['channel']) {
            'in_app' => 'sent',
            'email' => $this->sendEmail($recipient, $validated['title'], $validated['message']),
            'sms' => $this->sendSms($recipient, $validated['message']),
            default => 'provider_not_implemented',
        };

        $record = LmsNotification::query()->create([
            'user_id' => $recipient->id,
            'branch_id' => $recipient->branch_id,
            'channel' => $validated['channel'],
            'title' => $validated['title'],
            'message' => $validated['message'],
            'status' => $status,
        ]);

        $this->audit->log('notification.dispatched', 'lms_notification', (string) $record->id, [
            'channel' => $record->channel,
            'status' => $status,
        ]);

        $feedback = match ($status) {
            'sent' => $validated['channel'] === 'in_app'
                ? 'In-app notification added to the user inbox.'
                : 'Email accepted by the configured mail transport.',
            'submitted' => 'SMS submitted to the provider; handset delivery is not confirmed.',
            'not_configured' => ucfirst($validated['channel']) . ' was not sent because its provider is not configured.',
            'provider_not_implemented' => 'Push notifications are not connected yet. The notification was recorded but not sent.',
            'failed' => ucfirst($validated['channel']) . ' delivery failed. Check the provider settings and recipient details.',
            'delivery_unknown' => 'Provider delivery could not be confirmed. Check the provider portal before retrying.',
            default => 'Notification recorded with status: ' . $status,
        };

        return back()->with('status', $feedback);
    }

    public function markRead(Request $request, LmsNotification $notification): RedirectResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404);
        $notification->update(['read_at' => now()]);

        return back()->with('status', 'Notification marked as read.');
    }

    private function sendEmail(User $recipient, string $title, string $message): string
    {
        if (!$this->plugins->isEnabled('email-notifications') || empty($recipient->email)) {
            return 'not_configured';
        }

        $host = trim((string) $this->plugins->getSetting('email-notifications', 'smtp_host', ''));
        $port = (int) $this->plugins->getSetting('email-notifications', 'smtp_port', '587');
        $username = trim((string) $this->plugins->getSetting('email-notifications', 'smtp_username', ''));
        $password = (string) $this->plugins->getSetting('email-notifications', 'smtp_password', '');
        $from = trim((string) $this->plugins->getSetting('email-notifications', 'from_address', config('mail.from.address', '')));

        if ($host === '' || $port < 1 || $port > 65535 || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || (($username === '') !== ($password === ''))) {
            return 'not_configured';
        }

        try {
            $mailer = Mail::build([
                'transport' => 'smtp',
                'scheme' => $port === 465 ? 'smtps' : 'smtp',
                'host' => $host,
                'port' => $port,
                'username' => $username !== '' ? $username : null,
                'password' => $password !== '' ? $password : null,
                'timeout' => 10,
                'local_domain' => config('mail.mailers.smtp.local_domain'),
            ]);
            $mailer->html(nl2br(e($message)), function ($mail) use ($recipient, $title, $from): void {
                $mail->to($recipient->email)
                    ->from($from, (string) config('mail.from.name', config('app.name')))
                    ->subject($title);
            });

            return 'sent';
        } catch (\Throwable) {
            return 'failed';
        }
    }

    private function sendSms(User $recipient, string $message): string
    {
        if (!$this->plugins->isEnabled('sms-gateway')
            || $this->plugins->getSetting('sms-gateway', 'provider', 'greenweb') !== 'greenweb') {
            return 'not_configured';
        }

        $token = (string) $this->plugins->getSetting('sms-gateway', 'api_token', '');
        $digits = preg_replace('/\\D+/', '', (string) $recipient->phone) ?? '';
        $number = preg_match('/^01[3-9][0-9]{8}$/', $digits)
            ? '+88' . $digits
            : (preg_match('/^8801[3-9][0-9]{8}$/', $digits) ? '+' . $digits : null);

        if ($token === '' || $number === null) {
            return 'not_configured';
        }

        try {
            $response = Http::timeout(20)->asForm()->post('https://api.greenweb.com.bd/api.php', [
                'token' => $token,
                'to' => $number,
                'message' => $message,
            ]);
            $response->throw();
            $body = trim((string) $response->body());

            if (str_starts_with($body, 'Ok:')) {
                return 'submitted';
            }
            if (str_starts_with($body, 'Error:')) {
                return 'failed';
            }

            return 'delivery_unknown';
        } catch (\Throwable) {
            return 'delivery_unknown';
        }
    }

    private function ensureAssignedBranch($actor): void
    {
        if (!$actor || !$actor->hasRole('branch_manager')) {
            return;
        }

        if ($actor->branch_id === null
            || !$this->plugins->isEnabled('branch-management')
            || !Schema::hasTable('branches')
            || !app('db')->table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists()) {
            abort(403, 'Branch manager must be assigned to an active branch.');
        }
    }

}
