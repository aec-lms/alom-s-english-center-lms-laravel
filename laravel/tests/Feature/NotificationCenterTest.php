<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    public function test_in_app_notification_is_saved_for_the_selected_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['notification-center', 'email-notifications', 'sms-gateway']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');

        $this->actingAs($admin)->post('/admin/notifications/dispatch', [
            'user_id' => $student->id,
            'channel' => 'in_app',
            'title' => 'Attendance update',
            'message' => 'Your attendance was recorded.',
        ])->assertSessionHas('status', 'In-app notification added to the user inbox.');

        $this->assertDatabaseHas('lms_notifications', [
            'user_id' => $student->id,
            'channel' => 'in_app',
            'status' => 'sent',
            'title' => 'Attendance update',
        ]);
    }

    public function test_user_portal_only_shows_notifications_belonging_to_signed_in_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['notification-center']);
        $student = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');

        DB::table('lms_notifications')->insert([
            [
                'user_id' => $student->id,
                'channel' => 'in_app',
                'title' => 'My private notice',
                'message' => 'Your attendance is updated.',
                'status' => 'sent',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $otherStudent->id,
                'channel' => 'in_app',
                'title' => 'Another student private notice',
                'message' => 'This must not be visible to another student.',
                'status' => 'sent',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($student)->get('/dashboard')
            ->assertOk()
            ->assertSee('My private notice')
            ->assertDontSee('Another student private notice');
    }

    public function test_unconfigured_external_channels_are_not_reported_as_queued_or_sent(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['notification-center', 'email-notifications', 'sms-gateway']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        Http::fake();

        $this->actingAs($admin)->post('/admin/notifications/dispatch', [
            'user_id' => $student->id,
            'channel' => 'email',
            'title' => 'Welcome',
            'message' => 'Welcome to AEC.',
        ])->assertSessionHas('status', 'Email was not sent because its provider is not configured.');

        $this->actingAs($admin)->post('/admin/notifications/dispatch', [
            'user_id' => $student->id,
            'channel' => 'sms',
            'title' => 'Reminder',
            'message' => 'Your class starts soon.',
        ])->assertSessionHas('status', 'Sms was not sent because its provider is not configured.');

        $this->actingAs($admin)->post('/admin/notifications/dispatch', [
            'user_id' => $student->id,
            'channel' => 'push',
            'title' => 'New lesson',
            'message' => 'A new lesson is available.',
        ])->assertSessionHas('status', 'Push notifications are not connected yet. The notification was recorded but not sent.');

        $this->assertSame(3, DB::table('lms_notifications')->count());
        $this->assertDatabaseHas('lms_notifications', ['channel' => 'email', 'status' => 'not_configured']);
        $this->assertDatabaseHas('lms_notifications', ['channel' => 'sms', 'status' => 'not_configured']);
        $this->assertDatabaseHas('lms_notifications', ['channel' => 'push', 'status' => 'provider_not_implemented']);
        Http::assertNothingSent();
    }
}
