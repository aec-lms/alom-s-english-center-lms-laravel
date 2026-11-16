<?php

declare(strict_types=1);

namespace Plugins\Modules\NotificationCenter\Jobs;

use App\Core\Plugins\PluginManager;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;
use Throwable;

class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PluginManager $plugins): void
    {
        $notification = LmsNotification::query()->find($this->notificationId);
        if (!$notification || in_array($notification->status, ['sent', 'failed', 'read'], true)) {
            return;
        }

        $notification->forceFill([
            'status' => 'processing',
            'attempt_count' => (int) $notification->attempt_count + 1,
            'last_error' => null,
        ])->save();

        if ($notification->channel !== 'email') {
            $notification->forceFill([
                'status' => 'failed',
                'last_error' => 'No verified delivery adapter is configured for this channel.',
                'processed_at' => now(),
            ])->save();
            return;
        }

        $recipient = User::query()->find($notification->user_id);
        if (!$recipient || !filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            $notification->forceFill([
                'status' => 'failed',
                'last_error' => 'Recipient has no valid email address.',
                'processed_at' => now(),
            ])->save();
            return;
        }

        $smtpHost = (string) $plugins->getSetting('email-notifications', 'smtp_host', '');
        if ($smtpHost === '') {
            $notification->forceFill([
                'status' => 'failed',
                'last_error' => 'Email SMTP is not configured in Admin Settings.',
                'processed_at' => now(),
            ])->save();
            return;
        }

        Mail::raw($notification->message, function ($mail) use ($recipient, $notification): void {
            $mail->to($recipient->email)->subject($notification->title);
        });

        $notification->forceFill([
            'status' => 'sent',
            'last_error' => null,
            'processed_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        LmsNotification::query()
            ->whereKey($this->notificationId)
            ->whereNotIn('status', ['sent', 'read'])
            ->update([
                'status' => 'failed',
                'last_error' => 'Mail transport failed after retry attempts. Check server mail logs.',
                'processed_at' => now(),
            ]);
    }
}
