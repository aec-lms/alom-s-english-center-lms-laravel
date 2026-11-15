<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
