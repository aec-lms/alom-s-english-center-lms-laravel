<?php

declare(strict_types=1);

namespace Plugins\\Modules\\CourseManagement\\Jobs;

use App\\Core\\Plugins\\PluginManager;
use Illuminate\\Bus\\Queueable;
use Illuminate\\Contracts\\Queue\\ShouldBeUnique;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Foundation\\Bus\\Dispatchable;
use Illuminate\\Queue\\InteractsWithQueue;
use Illuminate\\Queue\\SerializesModels;
use Illuminate\\Support\\Facades\\Config;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Mail;
use Illuminate\\Support\\Facades\\Schema;
use RuntimeException;
use Throwable;

class SendGiftRecipientEmailJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $giftType,
        public readonly int $giftId
    ) {}

    public function uniqueId(): string
    {
        return $this->giftType . ':' . $this->giftId;
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(PluginManager $plugins): void
    {
        $table = match ($this->giftType) {
            'gift_course' => 'course_gifts',
            'gift_voucher' => 'course_gift_vouchers',
            default => throw new RuntimeException('Unsupported gift delivery type.'),
        };

        if (!Schema::hasTable($table)
            || !Schema::hasColumn($table, 'notification_delivery_status')
            || !Schema::hasColumn($table, 'notification_attempt_count')
            || !Schema::hasColumn($table, 'notification_last_error')) {
            throw new RuntimeException('Gift recipient delivery tracking is not installed.');
        }

        $gift = DB::table($table)->where('id', $this->giftId)->first();
        if (!$gift || $gift->status !== 'paid' || $gift->notification_sent_at !== null) {
            return;
        }

        $email = strtolower(trim((string) $gift->recipient_email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Gift recipient email address is invalid.');
        }

        $token = decrypt((string) $gift->redeem_token_encrypted);
        if (!is_string($token) || preg_match('/^[A-Za-z0-9]{40,80}$/', $token) !== 1
            || !hash_equals((string) $gift->redeem_token_hash, hash('sha256', $token))) {
            throw new RuntimeException('Gift redemption token failed integrity validation.');
        }

        if (!$plugins->isEnabled('email-notifications')) {
            throw new RuntimeException('Email Notifications integration is disabled.');
        }

        $smtpHost = trim((string) $plugins->getSetting('email-notifications', 'smtp_host', ''));
        $smtpPort = (int) $plugins->getSetting('email-notifications', 'smtp_port', '587');
        $smtpUsername = (string) $plugins->getSetting('email-notifications', 'smtp_username', '');
        $smtpPassword = (string) $plugins->getSetting('email-notifications', 'smtp_password', '');
        $fromAddress = trim((string) $plugins->getSetting(
            'email-notifications',
            'from_address',
            config('mail.from.address', '')
        ));

        if ($smtpHost === '' || $smtpPort < 1 || $smtpPort > 65535
            || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
            || (($smtpUsername === '') !== ($smtpPassword === ''))) {
            throw new RuntimeException('Gift email SMTP settings are incomplete or invalid.');
        }

        $giftTitle = $this->giftType === 'gift_course'
            ? (string) DB::table('courses')->where('id', $gift->course_id)->value('title')
            : 'Gift voucher';
        if ($giftTitle === '') {
            throw new RuntimeException('The gifted course is no longer available for notification.');
        }

        $senderName = (string) DB::table('users')->where('id', $gift->sender_user_id)->value('name');
        $redeemUrl = $this->giftType === 'gift_course'
            ? route('course-commerce.gifts.redeem-form', ['token' => $token])
            : route('course-commerce.vouchers.redeem-form', ['token' => $token]);

        DB::table($table)->where('id', $this->giftId)->update([
            'notification_delivery_status' => 'processing',
            'notification_attempt_count' => DB::raw('notification_attempt_count + 1'),
            'notification_last_error' => null,
            'updated_at' => now(),
        ]);

        Config::set([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $smtpHost,
            'mail.mailers.smtp.port' => $smtpPort,
            'mail.mailers.smtp.username' => $smtpUsername !== '' ? $smtpUsername : null,
            'mail.mailers.smtp.password' => $smtpPassword !== '' ? $smtpPassword : null,
            'mail.mailers.smtp.scheme' => $smtpPort === 465 ? 'smtps' : 'smtp',
            'mail.mailers.smtp.timeout' => 20,
            'mail.from.address' => $fromAddress,
            'mail.from.name' => config('app.name', 'Alom\'s English Center'),
        ]);
        Mail::purge('smtp');

        $subject = $this->giftType === 'gift_course'
            ? 'You received a course gift'
            : 'You received a gift voucher';
        $personalMessage = trim((string) ($gift->message ?? ''));
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:28px;color:#172033">'
            . '<h1 style="color:#4338ca">A gift of learning</h1>'
            . '<p>Hello' . ($gift->recipient_name ? ' ' . e($gift->recipient_name) : '') . ',</p>'
            . '<p>' . e($senderName !== '' ? $senderName : 'Someone special') . ' sent you a gift: <strong>' . e($giftTitle) . '</strong>.</p>'
            . ($personalMessage !== '' ? '<blockquote style="padding:16px;background:#f4f4f5;border-radius:10px">' . nl2br(e($personalMessage)) . '</blockquote>' : '')
            . '<p><a href="' . e($redeemUrl) . '" style="display:inline-block;background:#4f46e5;color:#fff;padding:13px 20px;border-radius:8px;text-decoration:none">View and redeem your gift</a></p>'
            . '<p>Sign in or create an account using this email address to redeem it. The link is personal; do not forward it.</p>'
            . '<p style="font-size:12px;color:#64748b">If you were not expecting this gift, you can ignore this email.</p>'
            . '</div>';

        Mail::mailer('smtp')->html($html, function ($message) use ($email, $subject): void {
            $message->to($email)->subject($subject);
        });

        $updated = DB::table($table)->where('id', $this->giftId)
            ->where('status', 'paid')
            ->whereNull('notification_sent_at')
            ->update([
                'notification_delivery_status' => 'sent',
                'notification_sent_at' => now(),
                'notification_last_error' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('Gift email was sent but its delivery state could not be reconciled; support review is required.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $table = match ($this->giftType) {
            'gift_course' => 'course_gifts',
            'gift_voucher' => 'course_gift_vouchers',
            default => null,
        };

        if ($table === null || !Schema::hasTable($table)
            || !Schema::hasColumn($table, 'notification_delivery_status')) {
            return;
        }

        DB::table($table)->where('id', $this->giftId)
            ->whereNull('notification_sent_at')
            ->update([
                'notification_delivery_status' => 'failed',
                'notification_last_error' => 'Recipient email delivery failed after automatic retries. Check SMTP settings and retry from gift support.',
                'updated_at' => now(),
            ]);
    }
}
