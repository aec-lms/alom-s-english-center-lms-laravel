<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugins\Modules\TeacherManagement\Models\Teacher;
use Tests\TestCase;

final class TeacherProfileValidationTest extends TestCase
{
    public function test_teacher_update_rejects_an_email_already_owned_by_another_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');
        $other = $this->createUserWithRole('student');
        $other->update(['email' => 'already.used@aec.test']);

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Validation Teacher',
            'email' => 'validation.teacher@aec.test',
            'employee_code' => 'VALIDATE-TEACH-001',
            'specialization' => 'English Grammar',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = Teacher::query()->where('employee_code', 'VALIDATE-TEACH-001')->firstOrFail();

        $this->actingAs($admin)->put('/admin/teachers/' . $teacher->id, [
            'name' => 'Validation Teacher',
            'email' => 'already.used@aec.test',
            'employee_code' => 'VALIDATE-TEACH-001',
            'specialization' => 'English Grammar',
            'is_active' => '1',
        ])->assertSessionHasErrors('email');

        $this->assertSame('validation.teacher@aec.test', $teacher->fresh()->user->email);
    }

    public function test_teacher_social_links_must_be_valid_urls(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['teacher-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Unsafe Social Link Teacher',
            'email' => 'unsafe.links@aec.test',
            'employee_code' => 'VALIDATE-TEACH-002',
            'specialization' => 'English Grammar',
            'youtube_url' => 'javascript:alert(1)',
            'facebook_url' => 'not a URL',
            'is_active' => '1',
        ])->assertSessionHasErrors(['youtube_url', 'facebook_url']);

        $this->assertDatabaseMissing('teachers', ['employee_code' => 'VALIDATE-TEACH-002']);
    }
}
