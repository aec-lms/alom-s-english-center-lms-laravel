<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuardianPortalPluginTest extends TestCase
{
    public function test_guardian_portal_installs_standalone_and_handles_missing_optional_integrations(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['guardian-portal']);
        $guardian = $this->createUserWithRole('guardian');

        $this->assertDatabaseHas('plugins', ['slug' => 'guardian-portal', 'is_installed' => true, 'is_enabled' => true]);
        $this->actingAs($guardian)->get('/guardian-portal')
            ->assertOk()
            ->assertSee('Guardian Management and Student Management are not both enabled yet');
    }

    public function test_guardian_can_switch_between_only_verified_linked_children_and_see_read_only_summaries(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'guardian-portal', 'guardian-management', 'student-management',
            'student-tracking', 'course-management', 'attendance-management',
            'exam-management', 'fees-management', 'notice-board',
        ]);

        $guardianUser = $this->createUserWithRole('guardian');
        $guardianId = DB::table('guardians')->insertGetId([
            'user_id' => $guardianUser->id,
            'branch_id' => null,
            'relationship' => 'Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentUserA = $this->createUserWithRole('student');
        $studentUserB = $this->createUserWithRole('student');
        $otherStudentUser = $this->createUserWithRole('student');
        $studentA = $this->createStudent($studentUserA->id, 'GP-CHILD-A');
        $studentB = $this->createStudent($studentUserB->id, 'GP-CHILD-B');
        $otherStudent = $this->createStudent($otherStudentUser->id, 'GP-OTHER');

        DB::table('guardian_student')->insert([
            ['guardian_id' => $guardianId, 'student_id' => $studentA, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
            ['guardian_id' => $guardianId, 'student_id' => $studentB, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $courseId = DB::table('courses')->insertGetId([
            'branch_id' => null, 'code' => 'GP-COURSE', 'title' => 'Guardian Portal English',
            'slug' => 'guardian-portal-english', 'description' => null, 'price' => 0,
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $studentUserA->id, 'course_id' => $courseId, 'branch_id' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('student_activity_events')->insert([
            'user_id' => $studentUserA->id, 'actor_id' => null, 'event_type' => 'lesson_completed',
            'subject_type' => 'lesson', 'subject_id' => '101', 'duration_seconds' => 600,
            'metadata' => json_encode(['lesson_title' => 'Tense Practice']),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $mappingId = DB::table('attendance_batch_mappings')->insertGetId([
            'source_type' => 'course_batch', 'source_id' => 9001, 'branch_id' => 1,
            'display_name' => 'Guardian Test Batch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attendance_entries')->insert([
            'attendance_batch_mapping_id' => $mappingId, 'student_id' => $studentA, 'branch_id' => 1,
            'attendance_date' => today(), 'status' => 'present', 'marked_by' => null,
            'sync_uuid' => null, 'source' => 'online', 'edited_reason' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $examId = DB::table('exams')->insertGetId([
            'course_id' => $courseId, 'branch_id' => null, 'title' => 'Guardian Test Exam',
            'duration_minutes' => 30, 'pass_marks' => 1, 'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(), 'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_attempts')->insert([
            'exam_id' => $examId, 'user_id' => $studentUserA->id,
            'submitted_answers' => json_encode([]), 'score_obtained' => 8,
            'total_marks' => 10, 'passed' => true, 'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('fee_invoices')->insert([
            'invoice_number' => 'GP-INV-001', 'student_id' => $studentA, 'branch_id' => null,
            'fee_category_id' => null, 'subtotal_amount' => 1000, 'discount_amount' => 0,
            'net_amount' => 1000, 'paid_amount' => 400, 'currency' => 'BDT',
            'status' => 'partial', 'due_date' => today()->addDays(7),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('notices')->insert([
            'branch_id' => null, 'title' => 'Guardian Notice', 'body' => 'Parent meeting next week.',
            'audience_role' => 'guardian', 'is_pinned' => false, 'published_at' => now(),
            'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($guardianUser)->get('/guardian-portal')
            ->assertOk()
            ->assertSee('GP-CHILD-A')
            ->assertSee('GP-CHILD-B')
            ->assertDontSee('GP-OTHER');

        $this->actingAs($guardianUser)->get('/guardian-portal?student='.$studentA)
            ->assertOk()
            ->assertSee('Guardian Portal English')
            ->assertSee('Tense Practice')
            ->assertSee('Guardian Test Exam')
            ->assertSee('GP-INV-001')
            ->assertSee('Guardian Notice')
            ->assertSee('৳600.00');

        $this->actingAs($guardianUser)->get('/guardian-portal?student='.$studentB)
            ->assertOk()
            ->assertSee('GP-CHILD-B')
            ->assertDontSee('Guardian Portal English')
            ->assertDontSee('GP-INV-001');

        $this->actingAs($guardianUser)->get('/guardian-portal?student='.$otherStudent)
            ->assertNotFound();
    }

    public function test_guardian_portal_rejects_non_guardian_users_and_hides_cross_branch_links(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['guardian-portal', 'guardian-management', 'student-management']);
        $studentUser = $this->createUserWithRole('student');
        $this->actingAs($studentUser)->get('/guardian-portal')->assertForbidden();

        $guardianUser = $this->createUserWithRole('guardian');
        $guardianId = DB::table('guardians')->insertGetId([
            'user_id' => $guardianUser->id,
            'branch_id' => 21,
            'relationship' => 'Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignStudentUser = $this->createUserWithRole('student');
        $foreignStudent = $this->createStudent($foreignStudentUser->id, 'GP-FOREIGN', 22);
        DB::table('guardian_student')->insert([
            'guardian_id' => $guardianId, 'student_id' => $foreignStudent,
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($guardianUser)->get('/guardian-portal')
            ->assertOk()
            ->assertSee('No linked students yet');
        $this->actingAs($guardianUser)->get('/guardian-portal?student='.$foreignStudent)
            ->assertNotFound();
    }

    public function test_shared_guardian_dashboard_uses_the_same_branch_safe_child_scope_as_full_portal(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'guardian-portal', 'guardian-management', 'student-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'GSH-A-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Guardian Shared Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'GSH-B-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Guardian Shared Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guardianUser = $this->createUserWithRole('guardian');
        $guardianId = DB::table('guardians')->insertGetId([
            'user_id' => $guardianUser->id,
            'branch_id' => $branchA,
            'relationship' => 'Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sameBranchUser = $this->createUserWithRole('student');
        $globalStudentUser = $this->createUserWithRole('student');
        $foreignBranchUser = $this->createUserWithRole('student');
        $sameBranchStudent = $this->createStudent($sameBranchUser->id, 'GSH-SAME', $branchA);
        $globalStudent = $this->createStudent($globalStudentUser->id, 'GSH-GLOBAL', null);
        $foreignBranchStudent = $this->createStudent($foreignBranchUser->id, 'GSH-FOREIGN', $branchB);

        DB::table('guardian_student')->insert([
            ['guardian_id' => $guardianId, 'student_id' => $sameBranchStudent, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
            ['guardian_id' => $guardianId, 'student_id' => $globalStudent, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
            ['guardian_id' => $guardianId, 'student_id' => $foreignBranchStudent, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($guardianUser)->get('/dashboard')
            ->assertOk()
            ->assertSee('GSH-SAME')
            ->assertSee('GSH-GLOBAL')
            ->assertDontSee('GSH-FOREIGN')
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['linked_students'] === 2);

        $this->actingAs($guardianUser)->get('/guardian-portal')
            ->assertOk()
            ->assertSee('GSH-SAME')
            ->assertSee('GSH-GLOBAL')
            ->assertDontSee('GSH-FOREIGN');
    }

    private function createStudent(int $userId, string $code, ?int $branchId = null): int
    {
        return DB::table('students')->insertGetId([
            'user_id' => $userId,
            'branch_id' => $branchId,
            'student_code' => $code,
            'admission_date' => today(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
