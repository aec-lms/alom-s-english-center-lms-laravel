<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;
use App\Core\Plugins\PluginManager;
use Plugins\Modules\NotificationCenter\Jobs\DeliverNotificationJob;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    public function test_in_app_notification_is_saved_for_the_selected_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications', 'sms-gateway', 'notification-center']);
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

    public function test_notification_center_sends_sms_using_standalone_greenweb_credentials(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications', 'sms-gateway', 'notification-center']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        DB::table('users')->where('id', $student->id)->update(['phone' => '01712345678']);
        Http::fake([
            'api.greenweb.com.bd/*' => Http::response('Ok: SMS Sent Successfully To +8801712345678', 200),
        ]);

        $this->actingAs($admin)->post('/plugins/sms/settings', [
            'provider' => 'greenweb',
            'api_token' => 'shared-greenweb-token',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/notifications/dispatch', [
            'user_id' => $student->id,
            'channel' => 'sms',
            'title' => 'Class reminder',
            'message' => 'Your class starts at 5 PM.',
        ])->assertSessionHas('status', 'SMS submitted to the provider; handset delivery is not confirmed.');

        $this->assertDatabaseHas('lms_notifications', [
            'user_id' => $student->id,
            'channel' => 'sms',
            'status' => 'submitted',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.greenweb.com.bd/api.php'
            && $request['token'] === 'shared-greenweb-token'
            && $request['to'] === '+8801712345678');
    }

    public function test_user_portal_only_shows_notifications_belonging_to_signed_in_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications', 'sms-gateway', 'notification-center']);
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

    public function test_user_can_mark_only_their_own_notification_as_read(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications', 'sms-gateway', 'notification-center']);
        $student = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');

        $ownId = DB::table('lms_notifications')->insertGetId([
            'user_id' => $student->id,
            'channel' => 'in_app',
            'title' => 'Own notification',
            'message' => 'Read this.',
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherId = DB::table('lms_notifications')->insertGetId([
            'user_id' => $otherStudent->id,
            'channel' => 'in_app',
            'title' => 'Other notification',
            'message' => 'Private.',
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->post('/my-notifications/' . $ownId . '/read')
            ->assertSessionHas('status', 'Notification marked as read.');
        $this->assertNotNull(DB::table('lms_notifications')->where('id', $ownId)->value('read_at'));

        $this->actingAs($student)->post('/my-notifications/' . $otherId . '/read')->assertNotFound();
        $this->assertDatabaseHas('lms_notifications', ['id' => $otherId, 'read_at' => null]);
    }

    public function test_unconfigured_external_channels_are_not_reported_as_queued_or_sent(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications', 'sms-gateway', 'notification-center']);
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

    public function test_configured_email_notification_is_queued_until_smtp_worker_confirms_delivery(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications', 'notification-center']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        Queue::fake();

        app(PluginManager::class)->saveSettings('email-notifications', [
            'smtp_host' => 'smtp.aec.test',
            'smtp_port' => '587',
            'smtp_username' => 'smtp-user',
            'smtp_password' => 'smtp-secret',
            'from_address' => 'noreply@aec.test',
        ], ['smtp_password'], $admin->id);

        $this->actingAs($admin)->post('/admin/notifications/dispatch', [
            'user_id' => $student->id,
            'channel' => 'email',
            'title' => 'Welcome to AEC',
            'message' => 'Your course access is ready.',
        ])->assertSessionHas('status', 'Email queued for delivery. Final delivery status updates after the SMTP worker processes it.');

        $notification = DB::table('lms_notifications')
            ->where('user_id', $student->id)
            ->where('channel', 'email')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('queued', $notification->status);
        Queue::assertPushed(DeliverNotificationJob::class, fn ($job) => $job->notificationId === (int) $notification->id);
    }


    public function test_smtp_worker_marks_email_sent_only_after_transport_accepts_message(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['email-notifications', 'notification-center']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');

        app(PluginManager::class)->saveSettings('email-notifications', [
            'smtp_host' => 'smtp.aec.test',
            'smtp_port' => '587',
            'smtp_username' => 'smtp-user',
            'smtp_password' => 'smtp-secret',
            'from_address' => 'noreply@aec.test',
        ], ['smtp_password'], $admin->id);

        $notification = LmsNotification::query()->create([
            'user_id' => $student->id,
            'channel' => 'email',
            'title' => 'Course access',
            'message' => 'Your course access is ready.',
            'status' => 'queued',
        ]);

        $mailer = Mockery::mock();
        $mailer->shouldReceive('raw')->once()
            ->with('Your course access is ready.', Mockery::type('Closure'));
        Mail::shouldReceive('purge')->once()->with('smtp');
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);

        (new DeliverNotificationJob((int) $notification->id))->handle(app(PluginManager::class));

        $this->assertDatabaseHas('lms_notifications', [
            'id' => $notification->id,
            'status' => 'sent',
            'attempt_count' => 1,
            'last_error' => null,
        ]);
        $this->assertNotNull($notification->fresh()->processed_at);
    }

}
