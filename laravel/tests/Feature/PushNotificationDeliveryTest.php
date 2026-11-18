<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\NotificationCenter\Jobs\DeliverNotificationJob;
use Plugins\Modules\NotificationCenter\Models\LmsNotification;
use Plugins\Modules\PushNotifications\Services\FirebaseCloudMessagingService;
use App\Core\Plugins\PluginManager;
use Tests\TestCase;

class PushNotificationDeliveryTest extends TestCase
{
    private function configureFirebase(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey));

        app(PluginManager::class)->saveSettings('push-notifications', [
            'project_id' => 'aec-test-project',
            'client_email' => 'firebase-admin@aec-test-project.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ]);
        Cache::flush();
    }

    private function prepareModules(): array
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['push-notifications', 'mobile-app-api', 'notification-center']);
        $admin = $this->createUserWithRole('admin');
        $user = $this->createUserWithRole('student');
        $this->configureFirebase();

        return [$admin, $user];
    }

    private function addDevice(int $userId, string $token): int
    {
        return DB::table('mobile_device_tokens')->insertGetId([
            'user_id' => $userId,
            'platform' => 'android',
            'device_name' => 'Test Android',
            'device_id' => 'test-device-'.substr(hash('sha256', $token), 0, 12),
            'app_version' => '1.0.0',
            'push_token' => $token,
            'last_seen_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_push_delivery_uses_fcm_http_v1_and_does_not_resend_successful_devices_on_retry(): void
    {
        [, $user] = $this->prepareModules();
        $firstDevice = $this->addDevice($user->id, 'fcm-token-one');
        $secondDevice = $this->addDevice($user->id, 'fcm-token-two');
        $notification = LmsNotification::query()->create([
            'user_id' => $user->id,
            'branch_id' => null,
            'channel' => 'push',
            'title' => 'New English lesson',
            'message' => 'Your new lesson is ready.',
            'status' => 'queued',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-fcm-access-token', 'expires_in' => 3600], 200),
            'https://fcm.googleapis.com/v1/projects/aec-test-project/messages:send' => Http::response(['name' => 'projects/aec-test-project/messages/test'], 200),
        ]);

        $job = new DeliverNotificationJob((int) $notification->id);
        $job->handle(app(PluginManager::class));

        $this->assertDatabaseHas('lms_notifications', ['id' => $notification->id, 'status' => 'sent']);
        $this->assertDatabaseHas('push_notification_deliveries', ['notification_id' => $notification->id, 'device_token_id' => $firstDevice, 'status' => 'sent']);
        $this->assertDatabaseHas('push_notification_deliveries', ['notification_id' => $notification->id, 'device_token_id' => $secondDevice, 'status' => 'sent']);
        Http::assertSentCount(3); // One OAuth exchange plus two device sends.

        $service = app(FirebaseCloudMessagingService::class);
        $this->assertSame('sent', $service->deliver($notification->fresh()));
        Http::assertSentCount(3);
    }

    public function test_unregistered_fcm_device_is_deactivated_and_not_retried(): void
    {
        [, $user] = $this->prepareModules();
        $deviceId = $this->addDevice($user->id, 'expired-fcm-token');
        $notification = LmsNotification::query()->create([
            'user_id' => $user->id,
            'branch_id' => null,
            'channel' => 'push',
            'title' => 'Notice',
            'message' => 'Test',
            'status' => 'queued',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-fcm-access-token', 'expires_in' => 3600], 200),
            'https://fcm.googleapis.com/v1/projects/aec-test-project/messages:send' => Http::response([
                'error' => [
                    'code' => 404,
                    'status' => 'NOT_FOUND',
                    'details' => [['errorCode' => 'UNREGISTERED']],
                ],
            ], 404),
        ]);

        $service = app(FirebaseCloudMessagingService::class);
        $this->assertSame('failed', $service->deliver($notification));
        $this->assertDatabaseHas('mobile_device_tokens', ['id' => $deviceId, 'is_active' => false]);
        $this->assertDatabaseHas('push_notification_deliveries', ['notification_id' => $notification->id, 'device_token_id' => $deviceId, 'status' => 'failed']);
    }

    public function test_push_service_fails_closed_when_no_mobile_device_is_registered(): void
    {
        [, $user] = $this->prepareModules();
        $notification = LmsNotification::query()->create([
            'user_id' => $user->id,
            'branch_id' => null,
            'channel' => 'push',
            'title' => 'Notice',
            'message' => 'Test',
            'status' => 'queued',
        ]);

        $this->assertSame('no_devices', app(FirebaseCloudMessagingService::class)->deliver($notification));
        Http::assertNothingSent();
    }
}
