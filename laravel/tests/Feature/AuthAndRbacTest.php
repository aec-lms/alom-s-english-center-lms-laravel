<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

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

    public function test_email_otp_password_recovery_works_for_students_managers_and_guardians(): void
    {
        $this->markSystemInstalled();
        Notification::fake();

        foreach (['student', 'branch_manager', 'guardian'] as $role) {
            $user = $this->createUserWithRole($role, null, $role . '@otp.aec.test');

            $this->post('/forgot-password/otp', ['email' => $user->email])
                ->assertRedirect()
                ->assertSessionHas('otp_status');

            $code = null;
            Notification::assertSentTo($user, \App\Notifications\PasswordRecoveryOtp::class, function ($notification) use (&$code): bool {
                $code = $notification->code;
                return true;
            });
            $this->assertMatchesRegularExpression('/^[0-9]{6}$/', (string) $code);

            $newPassword = 'OtpResetPassword!2026';
            $this->post('/forgot-password/otp/verify', [
                'email' => $user->email,
                'otp' => $code,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])->assertRedirect('/login')->assertSessionHas('status');

            $this->assertTrue(password_verify($newPassword, $user->fresh()->password));
            $this->assertDatabaseMissing('password_recovery_otps', ['user_id' => $user->id]);
        }
    }

    public function test_email_recovery_otp_is_single_use_and_expires_after_ten_minutes(): void
    {
        $this->markSystemInstalled();
        Notification::fake();
        $user = $this->createUserWithRole('guardian', null, 'guardian-expiry@aec.test');

        $this->post('/forgot-password/otp', ['email' => $user->email])->assertRedirect();

        $code = null;
        Notification::assertSentTo($user, \App\Notifications\PasswordRecoveryOtp::class, function ($notification) use (&$code): bool {
            $code = $notification->code;
            return true;
        });

        $this->travel(11)->minutes();
        $this->from('/forgot-password')->post('/forgot-password/otp/verify', [
            'email' => $user->email,
            'otp' => $code,
            'password' => 'OtpResetPassword!2026',
            'password_confirmation' => 'OtpResetPassword!2026',
        ])->assertSessionHasErrors('otp');

        $this->assertTrue(password_verify('StrongPassword!2026', $user->fresh()->password));
    }

    public function test_email_otp_request_does_not_disclose_unknown_accounts(): void
    {
        $this->markSystemInstalled();
        Notification::fake();

        $this->post('/forgot-password/otp', ['email' => 'not-registered@aec.test'])
            ->assertRedirect()
            ->assertSessionHas('otp_status', 'If an active account matches that email, a recovery code will be sent. The code expires in 10 minutes.');
        Notification::assertNothingSent();
    }

    public function test_student_lands_on_student_portal_and_unassigned_branch_manager_is_blocked(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management']);

        $student = $this->createUserWithRole('student', null, 'portal.student@aec.test');
        $this->actingAs($student)->get('/dashboard')->assertOk()->assertSee('Student Portal');

        $unassignedManager = $this->createUserWithRole('branch_manager');
        $this->actingAs($unassignedManager)->get('/dashboard')->assertForbidden();
    }

}
