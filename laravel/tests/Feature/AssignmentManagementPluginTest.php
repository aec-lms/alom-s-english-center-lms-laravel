<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AssignmentManagementPluginTest extends TestCase
{
    public function test_assignment_plugin_installs_without_course_or_student_management_plugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['assignment-management']);

        $admin = $this->createUserWithRole('admin');
        $this->assertTrue(app(PluginManager::class)->isEnabled('assignment-management'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('course-management'));
        $this->assertFalse(app(PluginManager::class)->isInstalled('student-management'));
        $this->assertTrue(Schema::hasTable('assignment_tasks'));
        $this->assertTrue(Schema::hasTable('assignment_submissions'));

        $this->actingAs($admin)->post('/admin/assignments', [
            'title' => 'Standalone Writing Task',
            'instructions' => 'Write a short paragraph.',
            'max_score' => 20,
            'status' => 'published',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('assignment_tasks', [
            'title' => 'Standalone Writing Task',
            'course_id' => null,
            'status' => 'published',
        ]);
    }

    public function test_student_can_submit_and_teacher_can_grade_assignment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['assignment-management']);
        $teacher = $this->createUserWithRole('teacher');
        $student = $this->createUserWithRole('student');

        $assignmentId = DB::table('assignment_tasks')->insertGetId([
            'created_by' => $teacher->id,
            'title' => 'Grammar Homework',
            'instructions' => 'Explain the present perfect tense.',
            'max_score' => 10,
            'allow_late' => false,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->post("/student/assignments/{$assignmentId}/submit", [
            'answer_text' => 'The present perfect connects past actions to the present.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $submissionId = DB::table('assignment_submissions')->where('assignment_id', $assignmentId)->value('id');
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submissionId,
            'student_id' => $student->id,
            'status' => 'submitted',
        ]);

        $this->actingAs($teacher)->post("/admin/assignments/submissions/{$submissionId}/grade", [
            'score' => 8,
            'feedback' => 'Good explanation.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submissionId,
            'score' => 8,
            'status' => 'graded',
            'graded_by' => $teacher->id,
        ]);
    }

    public function test_student_cannot_read_another_students_submission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['assignment-management']);
        $teacher = $this->createUserWithRole('teacher');
        $student = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');
        $assignmentId = DB::table('assignment_tasks')->insertGetId([
            'created_by' => $teacher->id,
            'title' => 'Private Task',
            'instructions' => 'Private.',
            'max_score' => 10,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $submissionId = DB::table('assignment_submissions')->insertGetId([
            'assignment_id' => $assignmentId,
            'student_id' => $otherStudent->id,
            'answer_text' => 'Private student answer.',
            'submitted_at' => now(),
            'status' => 'submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->get("/student/assignments/{$assignmentId}")->assertOk()->assertDontSee('Private student answer.');
        $this->actingAs($student)->get("/student/assignments/submissions/{$submissionId}/attachment")->assertForbidden();
    }

    public function test_submission_attachments_are_private_and_only_the_owner_can_download_them(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['assignment-management']);
        Storage::fake('local');

        $teacher = $this->createUserWithRole('teacher');
        $student = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');
        $assignmentId = DB::table('assignment_tasks')->insertGetId([
            'created_by' => $teacher->id,
            'title' => 'Private Attachment Task',
            'instructions' => 'Upload your answer.',
            'max_score' => 10,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = UploadedFile::fake()->create('answer.pdf', 100, 'application/pdf');
        $this->actingAs($student)->post("/student/assignments/{$assignmentId}/submit", [
            'answer_text' => 'My answer.',
            'attachment' => $file,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $submission = DB::table('assignment_submissions')->where('assignment_id', $assignmentId)->first();
        $this->assertNotNull($submission->attachment_path);
        Storage::disk('local')->assertExists($submission->attachment_path);
        Storage::disk('public')->assertMissing($submission->attachment_path);

        $this->actingAs($otherStudent)
            ->get("/student/assignments/submissions/{$submission->id}/attachment")
            ->assertForbidden();

        $this->actingAs($student)
            ->get("/student/assignments/submissions/{$submission->id}/attachment")
            ->assertOk();
    }

}
