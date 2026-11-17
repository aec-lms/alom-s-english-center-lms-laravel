<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ExamStandaloneTest extends TestCase
{
    public function test_general_exam_can_be_created_without_course_or_branch_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/exams', [
            'title' => 'General Grammar Assessment',
            'duration_minutes' => 30,
            'pass_marks' => 20,
            'is_published' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('exams', [
            'title' => 'General Grammar Assessment',
            'course_id' => null,
            'branch_id' => null,
            'is_published' => true,
        ]);
    }
}
