<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Core\Security\BranchAccess;
use App\Core\Security\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;
use Plugins\Modules\NotificationCenter\Jobs\DeliverNotificationJob;
use Plugins\Modules\PushNotifications\Services\FirebaseCloudMessagingService;

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
            $branchUserIds = $this->branchUserIds((int) $actor->branch_id);
            $notificationQuery->whereIn('user_id', $branchUserIds);
            $userQuery->whereIn('id', $branchUserIds);
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
        $recipientBranchId = app(BranchAccess::class)->contextBranchId($recipient);
        if ($actor && $actor->hasRole('branch_manager')) {
            abort_unless($recipientBranchId !== null && $recipientBranchId === (int) $actor->branch_id, 404);
        }

        $status = match ($validated['channel']) {
            'in_app' => 'sent',
            'email' => $this->emailDeliveryConfigured($recipient) ? 'queued' : 'not_configured',
            'sms' => $this->sendSms($recipient, $validated['message']),
            'push' => $this->plugins->isEnabled('push-notifications') && app(FirebaseCloudMessagingService::class)->canDeliverTo((int) $recipient->id) ? 'queued' : 'not_configured',
            default => 'provider_not_implemented',
        };

        $record = LmsNotification::query()->create([
            'user_id' => $recipient->id,
            'branch_id' => $recipientBranchId,
            'channel' => $validated['channel'],
            'title' => $validated['title'],
            'message' => $validated['message'],
            'status' => $status,
        ]);

        if (in_array($validated['channel'], ['email', 'push'], true) && $status === 'queued') {
            DeliverNotificationJob::dispatch((int) $record->id)->afterCommit();
        }

        $this->audit->log('notification.dispatched', 'lms_notification', (string) $record->id, [
            'channel' => $record->channel,
            'status' => $status,
        ]);

        $feedback = match ($status) {
            'sent' => $validated['channel'] === 'in_app'
                ? 'In-app notification added to the user inbox.'
                : 'Email accepted by the configured mail transport.',
            'queued' => $record->channel === 'push'
                ? 'Push notification queued for Firebase delivery. Final status updates after the worker processes it.'
                : 'Email queued for delivery. Final delivery status updates after the SMTP worker processes it.',
            'submitted' => 'SMS submitted to the provider; handset delivery is not confirmed.',
            'not_configured' => ucfirst($validated['channel']) . ' was not sent because its provider is not configured.',
            'provider_not_implemented' => 'No verified delivery adapter is configured for this channel. The notification was recorded but not sent.',
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

    private function branchUserIds(int $branchId)
    {
        $users = User::query()->where('branch_id', $branchId);

        if (app(PluginManager::class)->isEnabled('student-management')
            && Schema::hasTable('students')
            && Schema::hasColumn('students', 'user_id')
            && Schema::hasColumn('students', 'branch_id')) {
            $studentUserIds = DB::table('students')->whereNotNull('user_id')->whereNotNull('branch_id')->distinct()->pluck('user_id');
            $branchStudentUserIds = DB::table('students')
                ->where('branch_id', $branchId)
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id');

            return $users->whereNotIn('id', $studentUserIds)
                ->pluck('id')
                ->merge($branchStudentUserIds)
                ->unique()
                ->values();
        }

        return $users->pluck('id');
    }

    private function emailDeliveryConfigured(User $recipient): bool
    {
        if (!$this->plugins->isEnabled('email-notifications')
            || !filter_var((string) $recipient->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $host = trim((string) $this->plugins->getSetting('email-notifications', 'smtp_host', ''));
        $port = (int) $this->plugins->getSetting('email-notifications', 'smtp_port', '587');
        $username = trim((string) $this->plugins->getSetting('email-notifications', 'smtp_username', ''));
        $password = (string) $this->plugins->getSetting('email-notifications', 'smtp_password', '');
        $from = trim((string) $this->plugins->getSetting('email-notifications', 'from_address', config('mail.from.address', '')));

        return $host !== ''
            && $port >= 1 && $port <= 65535
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && (($username === '') === ($password === ''));
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
