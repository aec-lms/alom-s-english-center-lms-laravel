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


    public function test_student_can_view_and_submit_assignments_through_the_mobile_api(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['assignment-management']);
        Storage::fake('local');
        $teacher = $this->createUserWithRole('teacher');
        $student = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');

        $assignmentId = DB::table('assignment_tasks')->insertGetId([
            'created_by' => $teacher->id,
            'title' => 'Mobile Grammar Task',
            'instructions' => 'Explain the present perfect tense.',
            'max_score' => 10,
            'allow_late' => false,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentToken = $student->createToken('student-assignment-mobile')->plainTextToken;
        $this->getJson('/api/v1/my/assignments')->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->getJson('/api/v1/my/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Mobile Grammar Task')
            ->assertJsonPath('data.0.submission', null);

        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->getJson('/api/v1/my/assignments/' . $assignmentId)
            ->assertOk()
            ->assertJsonPath('data.instructions', 'Explain the present perfect tense.');

        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson('/api/v1/my/assignments/' . $assignmentId . '/submit', [
                'answer_text' => 'The present perfect connects past actions to the present.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.has_attachment', false);

        $submissionId = (int) DB::table('assignment_submissions')
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $student->id)
            ->value('id');

        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->getJson('/api/v1/my/assignments/' . $assignmentId)
            ->assertOk()
            ->assertJsonPath('data.submission.id', $submissionId)
            ->assertJsonPath('data.submission.status', 'submitted');

        $attachmentPath = 'assignment-submissions/'.$assignmentId.'/private-answer.pdf';
        Storage::disk('local')->put($attachmentPath, 'private attachment');
        DB::table('assignment_submissions')->where('id', $submissionId)->update(['attachment_path' => $attachmentPath]);

        $otherToken = $otherStudent->createToken('other-student-assignment-mobile')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->getJson('/api/v1/my/assignments/submissions/' . $submissionId . '/attachment')
            ->assertForbidden();
    }

    public function test_mobile_assignment_api_rejects_non_students_and_prevents_replacing_graded_work(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['assignment-management']);
        $teacher = $this->createUserWithRole('teacher');
        $student = $this->createUserWithRole('student');
        $assignmentId = DB::table('assignment_tasks')->insertGetId([
            'created_by' => $teacher->id,
            'title' => 'Graded Mobile Task',
            'instructions' => 'Already graded.',
            'max_score' => 10,
            'allow_late' => false,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assignment_submissions')->insert([
            'assignment_id' => $assignmentId,
            'student_id' => $student->id,
            'answer_text' => 'Completed answer.',
            'submitted_at' => now(),
            'status' => 'graded',
            'score' => 9,
            'feedback' => 'Excellent.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacherToken = $teacher->createToken('teacher-assignment-mobile')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $teacherToken)
            ->getJson('/api/v1/my/assignments')
            ->assertForbidden();

        $studentToken = $student->createToken('graded-assignment-mobile')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson('/api/v1/my/assignments/' . $assignmentId . '/submit', [
                'answer_text' => 'Try to replace graded work.',
            ])
            ->assertStatus(409);
    }

}
