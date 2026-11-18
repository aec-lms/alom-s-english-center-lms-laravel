<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DeviceSession;
use Tests\TestCase;

class DeviceSessionManagementTest extends TestCase
{
    public function test_admin_can_list_and_revoke_another_users_browser_session(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');

        $session = DeviceSession::query()->create([
            'user_id' => $student->id,
            'session_hash' => hash('sha256', 'student-browser-session'),
            'device_name' => 'Windows · Chrome',
            'user_agent' => 'Mozilla/5.0 Chrome/120 Windows',
            'ip_address' => '203.0.113.10',
            'logged_in_at' => now()->subHour(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.security.sessions.index'))
            ->assertOk()
            ->assertSee($student->email)
            ->assertSee('Windows · Chrome');

        $this->actingAs($admin)
            ->patch(route('admin.security.sessions.revoke', $session))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_non_admin_cannot_access_device_session_management(): void
    {
        $this->markSystemInstalled();
        $student = $this->createUserWithRole('student');
        $session = DeviceSession::query()->create([
            'user_id' => $student->id,
            'session_hash' => hash('sha256', 'student-browser-session'),
            'device_name' => 'Android · Chrome',
            'logged_in_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('admin.security.sessions.index'))
            ->assertForbidden();

        $this->actingAs($student)
            ->patch(route('admin.security.sessions.revoke', $session))
            ->assertForbidden();

        $this->assertNull($session->fresh()->revoked_at);
    }

    public function test_admin_cannot_revoke_the_browser_session_currently_in_use(): void
    {
        $this->markSystemInstalled();
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $hash = hash('sha256', session()->getId());
        session(['device_session_hash' => $hash]);

        $session = DeviceSession::query()->create([
            'user_id' => $admin->id,
            'session_hash' => $hash,
            'device_name' => 'Current browser',
            'logged_in_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->patch(route('admin.security.sessions.revoke', $session))
            ->assertStatus(422);

        $this->assertNull($session->fresh()->revoked_at);
    }
}
