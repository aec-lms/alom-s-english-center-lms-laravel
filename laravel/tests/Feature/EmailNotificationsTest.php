<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    public function test_email_template_is_saved_and_not_reported_as_sent_without_smtp_configuration(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/email-notifications/templates', [
            'template_key' => 'admission_welcome',
            'subject' => 'Welcome to AEC',
            'body_html' => '<p>Welcome to the course.</p>',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('email_templates', [
            'template_key' => 'admission_welcome',
            'subject' => 'Welcome to AEC',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/admin/email-notifications/send', [
            'recipient_email' => 'learner@example.test',
            'template_key' => 'admission_welcome',
        ])->assertSessionHas('status', 'Email was not sent because SMTP is not fully configured.');

        $this->assertDatabaseHas('email_logs', [
            'recipient_email' => 'learner@example.test',
            'subject' => 'Welcome to AEC',
            'template_key' => 'admission_welcome',
            'status' => 'not_configured',
            'dispatched_at' => null,
        ]);
    }

    public function test_custom_email_requires_subject_and_message(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/email-notifications/send', [
            'recipient_email' => 'learner@example.test',
        ])->assertSessionHasErrors(['subject', 'message']);

        $this->assertSame(0, DB::table('email_logs')->count());
    }
}
