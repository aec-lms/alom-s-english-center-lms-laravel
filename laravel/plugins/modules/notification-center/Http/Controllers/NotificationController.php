<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;

class NotificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $notifications = LmsNotification::query()->latest()->paginate(25);
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

        $record = LmsNotification::query()->create([
            'user_id' => (int) $validated['user_id'],
            'channel' => $validated['channel'],
            'title' => $validated['title'],
            'message' => $validated['message'],
            'status' => $validated['channel'] === 'in_app' ? 'sent' : 'queued',
        ]);

        $this->audit->log('notification.dispatched', 'lms_notification', (string) $record->id, ['channel' => $record->channel]);

        return back()->with('status', 'Notification queued/dispatched.');
    }
}
