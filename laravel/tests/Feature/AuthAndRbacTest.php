<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Auth\\Notifications\\ResetPassword;
use Illuminate\\Support\\Facades\\Notification;
use Tests\\TestCase;

class AuthAndRbacTest extends TestCase
{
    public function test_admin_can_login_and_access_dashboard(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin', null, 'admin@aec.test');

        $response = $this->post('/login', [
            'email' => 'admin@aec.test',
            'password' => 'StrongPassword!2026',
        ]);

        $response->assertRedirect('/admin');
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_student_is_denied_admin_plugin_center(): void
    {
        $this->markSystemInstalled();
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)
            ->get('/admin/plugins')
            ->assertForbidden();
    }

    public function test_rate_limiter_blocks_repeated_failed_logins(): void
    {
        $this->markSystemInstalled();
        $this->createUserWithRole('admin', null, 'locked@aec.test');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'locked@aec.test', 'password' => 'wrong-password']);
        }

        $response = $this->post('/login', ['email' => 'locked@aec.test', 'password' => 'wrong-password']);
        $response->assertSessionHasErrors('email');
    }

    public function test_password_recovery_sends_a_real_reset_notification_without_account_enumeration(): void
    {
        $this->markSystemInstalled();
        Notification::fake();
        $user = $this->createUserWithRole('student', null, 'learner@aec.test');

        $this->post('/forgot-password', ['email' => 'learner@aec.test'])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account matches that email, password recovery instructions will be sent shortly.');

        Notification::assertSentTo($user, ResetPassword::class);

        $this->post('/forgot-password', ['email' => 'unknown@aec.test'])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account matches that email, password recovery instructions will be sent shortly.');

        Notification::assertCount(1);
    }

    public function test_password_reset_token_changes_password_and_is_single_use(): void
    {
        $this->markSystemInstalled();
        Notification::fake();
        $user = $this->createUserWithRole('student', null, 'reset@aec.test');

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;
            return true;
        });
        $this->assertNotEmpty($token);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePassword!2026',
            'password_confirmation' => 'NewSecurePassword!2026',
        ];

        $this->post('/reset-password', $payload)
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Your password has been reset. You can now sign in.');

        $this->assertTrue(password_verify('NewSecurePassword!2026', $user->fresh()->password));

        $this->from('/reset-password/' . $token)
            ->post('/reset-password', $payload)
            ->assertSessionHasErrors('email');
    }
}
