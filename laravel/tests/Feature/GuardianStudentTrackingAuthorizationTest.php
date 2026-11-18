<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class GuardianStudentTrackingAuthorizationTest extends TestCase
{
    public function test_non_guardian_cannot_open_guardian_activity_page(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking', 'guardian-management', 'student-management']);

        $student = $this->createUserWithRole('student');
        $this->actingAs($student)->get('/student-tracking/guardian')->assertForbidden();
    }

    public function test_guardian_without_a_guardian_profile_cannot_open_activity_page(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-tracking', 'guardian-management', 'student-management']);

        $guardian = $this->createUserWithRole('guardian');
        $this->actingAs($guardian)->get('/student-tracking/guardian')->assertNotFound();
    }
}
