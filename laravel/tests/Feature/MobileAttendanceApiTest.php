<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\\TestCase;

class MobileAttendanceApiTest extends TestCase
{
    public function test_mobile_qr_checkin_requires_a_sanctum_authenticated_student(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['attendance-management', 'student-management', 'branch-management']);

        $this->postJson('/api/v1/attendance/qr/check-in', [
            'token' => str_repeat('a', 64),
        ])->assertUnauthorized();

        $teacher = $this->createUserWithRole('teacher');
        $token = $teacher->createToken('mobile-instructor')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/attendance/qr/check-in', [
                'token' => str_repeat('a', 64),
            ])
            ->assertForbidden();
    }
}
