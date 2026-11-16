<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Plugins\Modules\NotificationCenter\Jobs\DeliverNotificationJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;

class NotificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $user = request()->user();
        $query = LmsNotification::query()->latest();
        if ($user?->hasRole('branch_manager')) {
            abort_if($user->branch_id === null || !app('db')->table('branches')->where('id', $user->branch_id)->where('is_active', true)->exists(), 403, 'Branch manager must be assigned to an active branch.');
            $query->where('branch_id', $user->branch_id);
        }
        $notifications = $query->paginate(25);
        return view('notification-center::index', compact('notifications'));
    }

    public function dispatch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'channel' => ['required', 'in:in_app,email,sms,push'],
            'title' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string'],
        ]);

        $actor = $request->user();
        $recipient = User::query()->findOrFail((int) $validated['user_id']);
        if ($actor?->hasRole('branch_manager')) {
            abort_if($actor->branch_id === null || !app('db')->table('branches')->where('id', $actor->branch_id)->where('is_active', true)->exists(), 403, 'Branch manager must be assigned to an active branch.');
            abort_unless((int) $recipient->branch_id === (int) $actor->branch_id, 404);
        }

        $record = LmsNotification::query()->create([
            'user_id' => (int) $recipient->id,
            'branch_id' => $recipient->branch_id,
            'channel' => $validated['channel'],
            'title' => $validated['title'],
            'message' => $validated['message'],
            'status' => $validated['channel'] === 'in_app' ? 'sent' : 'queued',
        ]);

        if ($record->channel !== 'in_app') {
            DeliverNotificationJob::dispatch($record->id)->afterCommit();
            $record->refresh();
        }

        $this->audit->log('notification.dispatched', 'lms_notification', (string) $record->id, [
            'channel' => $record->channel,
            'status' => $record->status,
        ]);

        $message = match ($record->status) {
            'sent' => 'Notification delivered to the configured mail transport.',
            'queued' => 'Notification queued; delivery is not yet confirmed.',
            'failed' => 'Notification failed. Check the delivery status and channel configuration.',
            default => 'Notification status: ' . $record->status,
        };

        return back()->with('status', $record->channel === 'in_app' ? 'In-app notification created.' : $message);
    }
}
