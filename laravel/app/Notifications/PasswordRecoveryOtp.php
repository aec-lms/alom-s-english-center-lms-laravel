<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordRecoveryOtp extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your Alom’s English Center password recovery code')
            ->greeting('Password recovery request')
            ->line('Use this one-time code to reset your account password:')
            ->line('**' . $this->code . '**')
            ->line('This code expires in 10 minutes and can only be used once.')
            ->line('If you did not request a password reset, you can safely ignore this email.');
    }
}
