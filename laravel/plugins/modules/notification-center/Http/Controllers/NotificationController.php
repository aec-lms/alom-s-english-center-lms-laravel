<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
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
            abort_if($user->branch_id === null, 403, 'Your account is not assigned to a branch.');
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
            abort_if($actor->branch_id === null, 403, 'Your account is not assigned to a branch.');
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

        $this->audit->log('notification.dispatched', 'lms_notification', (string) $record->id, ['channel' => $record->channel]);

        return back()->with('status', $record->channel === 'in_app' ? 'In-app notification created.' : 'Notification queued. Delivery will be reported only after a channel worker confirms the provider response.');
    }
}
