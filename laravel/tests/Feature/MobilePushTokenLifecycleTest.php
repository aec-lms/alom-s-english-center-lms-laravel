<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobilePushTokenLifecycleTest extends TestCase
{
    public function test_login_refresh_preserves_an_existing_firebase_push_token_for_the_same_device(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['mobile-app-api']);
        $student = $this->createUserWithRole('student');

        $initialToken = $student->createToken('mobile-device')->plainTextToken;
        $this->withToken($initialToken)->postJson('/api/v1/push-token', [
            'platform' => 'android',
            'device_name' => 'Alom Student App',
            'device_id' => 'student-device-001',
            'push_token' => 'firebase-registration-token',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'StrongPassword!2026',
            'device_name' => 'Alom Student App',
            'device_id' => 'student-device-001',
            'platform' => 'android',
            'app_version' => '1.0.0',
        ])->assertOk();

        $this->assertDatabaseHas('mobile_device_tokens', [
            'user_id' => $student->id,
            'device_id' => 'student-device-001',
            'push_token' => 'firebase-registration-token',
            'is_active' => true,
        ]);
    }

    public function test_logout_revokes_only_the_current_device_push_registration(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['mobile-app-api']);
        $student = $this->createUserWithRole('student');

        $firstDevice = DB::table('mobile_device_tokens')->insertGetId([
            'user_id' => $student->id, 'platform' => 'android', 'device_name' => 'Student Phone',
            'device_id' => 'student-device-one', 'app_version' => '1.0.0',
            'push_token' => 'fcm-token-one', 'last_seen_at' => now(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $secondDevice = DB::table('mobile_device_tokens')->insertGetId([
            'user_id' => $student->id, 'platform' => 'ios', 'device_name' => 'Student Tablet',
            'device_id' => 'student-device-two', 'app_version' => '1.0.0',
            'push_token' => 'fcm-token-two', 'last_seen_at' => now(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $plainToken = $student->createToken('student-phone')->plainTextToken;
        $tokenId = (int) explode('|', $plainToken, 2)[0];

        $this->withToken($plainToken)->postJson('/api/v1/auth/logout', [
            'device_id' => 'student-device-one',
        ])->assertOk();

        $this->assertDatabaseHas('mobile_device_tokens', ['id' => $firstDevice, 'is_active' => false]);
        $this->assertDatabaseHas('mobile_device_tokens', ['id' => $secondDevice, 'is_active' => true]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }
}
