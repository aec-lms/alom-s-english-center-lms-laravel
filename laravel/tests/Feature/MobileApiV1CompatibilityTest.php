<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MobileApiV1CompatibilityTest extends TestCase
{
    public function test_versioned_mobile_authentication_returns_access_token_and_supports_session_restore(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['mobile-app-api', 'course-management', 'student-management']);
        $student = $this->createUserWithRole('student', email: 'mobile-v1-student@aec.test');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'StrongPassword!2026',
            'device_name' => 'AEC Student Android',
            'device_id' => str_repeat('a', 64),
            'platform' => 'android',
            'app_version' => '1.0.0',
        ]);

        $login->assertOk()->assertJsonStructure(['access_token', 'token', 'user' => ['id', 'name', 'email', 'branch_id']]);
        $this->assertSame($login->json('token'), $login->json('access_token'));

        $this->withHeader('Authorization', 'Bearer ' . $login->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $student->id)
            ->assertJsonPath('user.email', $student->email);
    }

    public function test_versioned_mobile_public_catalog_and_protected_lesson_routes_keep_existing_access_rules(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['mobile-app-api', 'course-management']);
        $this->getJson('/api/v1/config')->assertOk();
        $this->getJson('/api/v1/courses')->assertOk();
        $this->getJson('/api/v1/home')->assertOk();

        $student = $this->createUserWithRole('student');
        $token = $student->createToken('mobile-v1-test')->plainTextToken;
        $this->getJson('/api/v1/courses/not-a-real-course/lessons')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/courses/not-a-real-course/lessons')
            ->assertNotFound();
    }

    public function test_versioned_logout_revokes_the_current_mobile_token(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['mobile-app-api']);
        $student = $this->createUserWithRole('student');
        $token = $student->createToken('mobile-v1-logout')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $student->id]);
    }
}
