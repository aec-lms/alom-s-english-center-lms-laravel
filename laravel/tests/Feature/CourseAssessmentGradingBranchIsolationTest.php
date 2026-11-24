<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CourseAssessmentGradingBranchIsolationTest extends TestCase
{
    private function setupAssessmentPlugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'teacher-management', 'course-management', 'course-assessments']);
        $roleId = DB::table('roles')->where('slug', 'teacher')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'course_assessments.grade')->value('id');
        $this->assertNotNull($roleId);
        $this->assertNotNull($permissionId);
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
        $branchManagerRoleId = DB::table('roles')->where('slug', 'branch_manager')->value('id');
        if ($branchManagerRoleId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $branchManagerRoleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    private function createBranch(string $suffix): int
    {
        return (int) DB::table('branches')->insertGetId([
            'code' => 'AG-' . strtoupper(bin2hex(random_bytes(5))),
            'name' => 'Assessment Grading ' . $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTeacher(?int $userBranchId, ?int $profileBranchId): object
    {
        $teacher = $this->createUserWithRole('teacher', $userBranchId);
        DB::table('teachers')->insert([
            'user_id' => $teacher->id,
            'branch_id' => $profileBranchId,
            'employee_code' => 'ASSESS-TEACHER-' . strtoupper(bin2hex(random_bytes(5))),
            'specialization' => 'English',
            'is_active' => true,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $teacher;
    }

    private function createCourse(?int $branchId): int
    {
        return (int) DB::table('courses')->insertGetId([
            'branch_id' => $branchId,
            'code' => 'ASSESS-COURSE-' . strtoupper(bin2hex(random_bytes(4))),
            'title' => 'Branch Assessment Course',
            'slug' => 'branch-assessment-' . strtolower(bin2hex(random_bytes(4))),
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPendingAttempt(int $courseId, ?int $branchId, string $title): int
    {
        $setId = (int) DB::table('course_assessment_sets')->insertGetId([
            'course_id' => $courseId,
            'branch_id' => $branchId,
            'title' => $title,
            'description' => null,
            'status' => 'published',
            'time_limit_minutes' => 30,
            'max_attempts' => 1,
            'questions_per_attempt' => null,
            'randomize_questions' => false,
            'available_from' => null,
            'available_until' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', $branchId);
        $attemptId = (int) DB::table('course_assessment_attempts')->insertGetId([
            'assessment_set_id' => $setId,
            'course_id' => $courseId,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'pending_review',
            'started_at' => now()->subMinutes(10),
            'deadline_at' => null,
            'submitted_at' => now(),
            'auto_score' => 0,
            'manual_score' => 0,
            'max_score' => 5,
            'final_score' => null,
            'graded_by' => null,
            'graded_at' => null,
            'teacher_feedback' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_assessment_attempt_questions')->insert([
            'attempt_id' => $attemptId,
            'source_question_id' => null,
            'type' => 'written',
            'question' => 'Write a complete sentence.',
            'options' => null,
            'correct_option' => null,
            'correct_answer' => 'A complete sentence.',
            'explanation' => null,
            'marks' => 5,
            'position' => 10,
            'selected_option' => null,
            'answer_text' => 'This is a complete sentence.',
            'is_correct' => null,
            'marks_awarded' => 0,
            'manual_feedback' => null,
            'graded_by' => null,
            'graded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $attemptId;
    }

    private function assignTeacher(int $teacherId, int $courseId): void
    {
        DB::table('course_teacher_assignments')->insert([
            'course_id' => $courseId,
            'teacher_user_id' => $teacherId,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_branch_a_teacher_cannot_list_or_grade_branch_b_assessment_even_with_stale_assignment(): void
    {
        $this->setupAssessmentPlugins();
        $branchA = $this->createBranch('A');
        $branchB = $this->createBranch('B');
        $teacher = $this->createTeacher($branchA, $branchA);
        $courseB = $this->createCourse($branchB);
        $attemptId = $this->createPendingAttempt($courseB, $branchB, 'Secret Branch B Assessment');
        $this->assignTeacher((int) $teacher->id, $courseB);

        $this->actingAs($teacher)
            ->get(route('plugins.course-assessments.grading.index'))
            ->assertOk()
            ->assertDontSee('Secret Branch B Assessment');

        $questionId = (int) DB::table('course_assessment_attempt_questions')->where('attempt_id', $attemptId)->value('id');
        $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
            'scores' => [$questionId => 4],
            'feedback' => [$questionId => 'Unauthorized'],
        ])->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptId,
            'status' => 'pending_review',
            'manual_score' => 0,
            'graded_by' => null,
        ]);

        $mismatchedTeacher = $this->createTeacher($branchA, $branchB);
        $mismatchedAttempt = $this->createPendingAttempt($courseB, $branchB, 'Mismatched Teacher Profile Assessment');
        $this->assignTeacher((int) $mismatchedTeacher->id, $courseB);
        $this->actingAs($mismatchedTeacher)
            ->get(route('plugins.course-assessments.grading.index'))
            ->assertForbidden();
        $mismatchedQuestion = (int) DB::table('course_assessment_attempt_questions')->where('attempt_id', $mismatchedAttempt)->value('id');
        $this->actingAs($mismatchedTeacher)->patch(route('plugins.course-assessments.grading.update', $mismatchedAttempt), [
            'scores' => [$mismatchedQuestion => 3],
        ])->assertForbidden();
    }

    public function test_grading_rechecks_locked_account_branch_active_state_and_current_role(): void
    {
        $this->setupAssessmentPlugins();
        $branchA = $this->createBranch('CURRENT-A');
        $branchB = $this->createBranch('CURRENT-B');
        $courseA = $this->createCourse($branchA);

        // The authenticated User instance is deliberately kept stale after the
        // persisted account branch changes. The grading guard must read the DB.
        $movedTeacher = $this->createTeacher($branchA, $branchA);
        $movedAttempt = $this->createPendingAttempt($courseA, $branchA, 'Current Account Branch Check');
        $this->assignTeacher((int) $movedTeacher->id, $courseA);
        $movedQuestion = (int) DB::table('course_assessment_attempt_questions')
            ->where('attempt_id', $movedAttempt)
            ->value('id');
        DB::table('users')->where('id', $movedTeacher->id)->update([
            'branch_id' => $branchB,
            'updated_at' => now(),
        ]);

        $this->actingAs($movedTeacher)->patch(route('plugins.course-assessments.grading.update', $movedAttempt), [
            'scores' => [$movedQuestion => 4],
            'feedback' => [$movedQuestion => 'Must not be saved after account branch move.'],
            'teacher_feedback' => 'Must remain unchanged.',
        ])->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $movedAttempt,
            'status' => 'pending_review',
            'manual_score' => 0,
            'final_score' => null,
            'graded_by' => null,
            'teacher_feedback' => null,
        ]);
        $this->assertDatabaseHas('course_assessment_attempt_questions', [
            'id' => $movedQuestion,
            'marks_awarded' => 0,
            'manual_feedback' => null,
            'graded_by' => null,
        ]);

        // Account deactivation after authentication must be read under the
        // grading transaction, not from the cached User model.
        $inactiveTeacher = $this->createTeacher($branchA, $branchA);
        $inactiveAttempt = $this->createPendingAttempt($courseA, $branchA, 'Current Account Active Check');
        $this->assignTeacher((int) $inactiveTeacher->id, $courseA);
        $inactiveQuestion = (int) DB::table('course_assessment_attempt_questions')
            ->where('attempt_id', $inactiveAttempt)
            ->value('id');
        DB::table('users')->where('id', $inactiveTeacher->id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $this->actingAs($inactiveTeacher)->patch(route('plugins.course-assessments.grading.update', $inactiveAttempt), [
            'scores' => [$inactiveQuestion => 3],
        ])->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $inactiveAttempt,
            'status' => 'pending_review',
            'manual_score' => 0,
            'graded_by' => null,
        ]);

        // Even when the authenticated model has already loaded its role
        // relation, a revoked persisted teacher pivot must be denied.
        $revokedTeacher = $this->createTeacher($branchA, $branchA);
        $revokedTeacher->load('roles');
        $this->assertTrue($revokedTeacher->hasRole('teacher'));
        $revokedAttempt = $this->createPendingAttempt($courseA, $branchA, 'Current Role Check');
        $this->assignTeacher((int) $revokedTeacher->id, $courseA);
        $revokedQuestion = (int) DB::table('course_assessment_attempt_questions')
            ->where('attempt_id', $revokedAttempt)
            ->value('id');
        DB::table('role_user')
            ->where('user_id', $revokedTeacher->id)
            ->where('role_id', DB::table('roles')->where('slug', 'teacher')->value('id'))
            ->delete();

        $this->actingAs($revokedTeacher)->patch(route('plugins.course-assessments.grading.update', $revokedAttempt), [
            'scores' => [$revokedQuestion => 2],
        ])->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $revokedAttempt,
            'status' => 'pending_review',
            'manual_score' => 0,
            'graded_by' => null,
        ]);
    }

    public function test_mysql_teacher_profile_lock_serializes_grading_before_branch_reassignment(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teacher profile/account lock ordering is verified by the MySQL 8 workflow.');
        }

        $this->setupAssessmentPlugins();
        $branchA = $this->createBranch('LOCK-A');
        $branchB = $this->createBranch('LOCK-B');
        $teacher = $this->createTeacher($branchA, $branchA);
        $courseA = $this->createCourse($branchA);
        $attemptId = $this->createPendingAttempt($courseA, $branchA, 'Teacher Branch Race Assessment');
        $this->assignTeacher((int) $teacher->id, $courseA);
        $questionId = (int) DB::table('course_assessment_attempt_questions')
            ->where('attempt_id', $attemptId)
            ->value('id');

        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = 'assessment_teacher_race_' . bin2hex(random_bytes(3));
        $secondaryConfig = config('database.connections.' . $primaryName);
        $secondaryConfig['database'] = $primary->getDatabaseName();
        config(['database.connections.' . $secondaryName => $secondaryConfig]);
        DB::purge($secondaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            // The route's nested transaction grades under the outer transaction,
            // keeping attempt -> set -> course -> Teacher -> User -> role locks held.
            $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
                'scores' => [$questionId => 4],
                'feedback' => [$questionId => 'Graded before reassignment.'],
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertDatabaseHas('course_assessment_attempts', [
                'id' => $attemptId,
                'status' => 'graded',
                'manual_score' => 4,
                'graded_by' => $teacher->id,
            ]);

            $secondary->beginTransaction();
            try {
                // TeacherController updates profile first, then linked account.
                // Its profile update must wait while the grader owns the profile lock.
                $secondary->table('teachers')->where('user_id', $teacher->id)->update([
                    'branch_id' => $branchB,
                    'updated_at' => now(),
                ]);
                $this->fail('Concurrent Teacher profile reassignment must wait for the grading transaction.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }

            $primary->commit();

            // After the grade commits, the lifecycle writer can move both branch
            // identities. A later stale-authenticated request must now be denied.
            $secondary->beginTransaction();
            $secondary->table('teachers')->where('user_id', $teacher->id)->update([
                'branch_id' => $branchB,
                'updated_at' => now(),
            ]);
            $secondary->table('users')->where('id', $teacher->id)->update([
                'branch_id' => $branchB,
                'updated_at' => now(),
            ]);
            $secondary->commit();

            $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
                'scores' => [$questionId => 1],
                'feedback' => [$questionId => 'Must not overwrite after reassignment.'],
            ])->assertForbidden();

            $this->assertDatabaseHas('course_assessment_attempts', [
                'id' => $attemptId,
                'status' => 'graded',
                'manual_score' => 4,
                'graded_by' => $teacher->id,
            ]);
            $this->assertDatabaseHas('course_assessment_attempt_questions', [
                'id' => $questionId,
                'marks_awarded' => 4,
                'manual_feedback' => 'Graded before reassignment.',
                'graded_by' => $teacher->id,
            ]);
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::purge($secondaryName);
        }
    }

    public function test_mysql_account_deactivation_waits_for_grading_then_blocks_the_next_grade(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Account-row grading serialization requires MySQL 8.');
        }

        [$teacher, $attemptId, $questionId] = $this->createMysqlTeacherGradingFixture('ACCOUNT');
        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = $this->createSecondaryMysqlConnection($primaryName, 'assessment_account_race');
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $primary->beginTransaction();

        try {
            // The real grading endpoint acquires Teacher -> User -> teacher role
            // locks and writes a grade while the outer transaction retains them.
            $this->gradeAttempt($teacher, $attemptId, $questionId, 4, 'Before account deactivation.');
            $auditCount = $this->gradeAuditCount($attemptId);

            $secondary->beginTransaction();
            try {
                $secondary->table('users')->where('id', $teacher->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
                $this->fail('Account deactivation must wait while the grading transaction holds the linked User row.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }

            $primary->commit();

            $secondary->table('users')->where('id', $teacher->id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

            $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
                'scores' => [$questionId => 1],
                'feedback' => [$questionId => 'Must not overwrite after account deactivation.'],
                'teacher_feedback' => 'Must remain unchanged.',
            ])->assertForbidden();

            $this->assertGradingUnchanged($attemptId, $questionId, $teacher->id, 4, 'Before account deactivation.');
            $this->assertSame($auditCount, $this->gradeAuditCount($attemptId));
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::purge($secondaryName);
        }
    }

    // This two-session regression proves the role pivot stays serialized with the canonical Teacher -> User -> role grading lock order.
    public function test_mysql_teacher_role_revocation_waits_for_grading_then_blocks_the_next_grade(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teacher role-pivot grading serialization requires MySQL 8.');
        }

        [$teacher, $attemptId, $questionId] = $this->createMysqlTeacherGradingFixture('ROLE');
        $teacher->load('roles');
        $roleId = DB::table('roles')->where('slug', 'teacher')->value('id');
        $this->assertNotNull($roleId);

        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = $this->createSecondaryMysqlConnection($primaryName, 'assessment_role_race');
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $primary->beginTransaction();

        try {
            $this->gradeAttempt($teacher, $attemptId, $questionId, 4, 'Before role revocation.');
            $auditCount = $this->gradeAuditCount($attemptId);

            $secondary->beginTransaction();
            try {
                $secondary->table('role_user')
                    ->where('user_id', $teacher->id)
                    ->where('role_id', $roleId)
                    ->delete();
                $this->fail('Teacher role revocation must wait while the grading transaction holds the teacher role pivot.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }

            $primary->commit();

            $secondary->table('role_user')
                ->where('user_id', $teacher->id)
                ->where('role_id', $roleId)
                ->delete();

            $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
                'scores' => [$questionId => 1],
                'feedback' => [$questionId => 'Must not overwrite after role revocation.'],
                'teacher_feedback' => 'Must remain unchanged.',
            ])->assertForbidden();

            $this->assertGradingUnchanged($attemptId, $questionId, $teacher->id, 4, 'Before role revocation.');
            $this->assertSame($auditCount, $this->gradeAuditCount($attemptId));
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::purge($secondaryName);
        }
    }

    private function createMysqlTeacherGradingFixture(string $suffix): array
    {
        $this->setupAssessmentPlugins();
        $branch = $this->createBranch('MYSQL-' . $suffix);
        $teacher = $this->createTeacher($branch, $branch);
        $courseId = $this->createCourse($branch);
        $attemptId = $this->createPendingAttempt($courseId, $branch, 'MySQL ' . $suffix . ' Grading Race');
        $this->assignTeacher((int) $teacher->id, $courseId);
        $questionId = (int) DB::table('course_assessment_attempt_questions')
            ->where('attempt_id', $attemptId)
            ->value('id');

        return [$teacher, $attemptId, $questionId];
    }

    private function createSecondaryMysqlConnection(string $primaryName, string $prefix): string
    {
        $primary = DB::connection($primaryName);
        $secondaryName = $prefix . '_' . bin2hex(random_bytes(3));
        $config = config('database.connections.' . $primaryName);
        $config['database'] = $primary->getDatabaseName();
        config(['database.connections.' . $secondaryName => $config]);
        DB::purge($secondaryName);
        DB::connection($secondaryName);

        return $secondaryName;
    }

    private function gradeAttempt(object $teacher, int $attemptId, int $questionId, float $score, string $feedback): void
    {
        $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
            'scores' => [$questionId => $score],
            'feedback' => [$questionId => $feedback],
            'teacher_feedback' => $feedback,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function gradeAuditCount(int $attemptId): int
    {
        return (int) DB::table('audit_logs')
            ->where('action', 'course_assessment.graded')
            ->where('entity_type', 'course_assessment_attempt')
            ->where('entity_id', (string) $attemptId)
            ->count();
    }

    private function assertGradingUnchanged(
        int $attemptId,
        int $questionId,
        int $teacherId,
        float $score,
        string $feedback
    ): void {
        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptId,
            'status' => 'graded',
            'manual_score' => $score,
            'final_score' => $score,
            'graded_by' => $teacherId,
            'teacher_feedback' => $feedback,
        ]);
        $this->assertDatabaseHas('course_assessment_attempt_questions', [
            'id' => $questionId,
            'marks_awarded' => $score,
            'manual_feedback' => $feedback,
            'graded_by' => $teacherId,
        ]);
    }

    public function test_branch_manager_queue_excludes_course_linked_assessment_with_conflicting_course_branch(): void
    {
        $this->setupAssessmentPlugins();
        $branchA = $this->createBranch('MANAGER-A');
        $branchB = $this->createBranch('MANAGER-B');
        $manager = $this->createUserWithRole('branch_manager', $branchA);

        $courseA = $this->createCourse($branchA);
        $courseB = $this->createCourse($branchB);
        $visibleAttempt = $this->createPendingAttempt($courseA, $branchA, 'Branch A Queue Assessment');
        $hiddenAttempt = $this->createPendingAttempt($courseB, $branchA, 'Conflicting Branch Course Assessment');

        $this->actingAs($manager)
            ->get(route('plugins.course-assessments.grading.index'))
            ->assertOk()
            ->assertViewHas('attempts', function ($attempts) use ($visibleAttempt, $hiddenAttempt): bool {
                $visibleIds = $attempts->getCollection()->modelKeys();

                return in_array($visibleAttempt, $visibleIds, true)
                    && !in_array($hiddenAttempt, $visibleIds, true);
            });

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $visibleAttempt,
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $hiddenAttempt,
            'status' => 'pending_review',
        ]);
    }

    public function test_same_branch_teacher_can_grade_but_unassigned_teacher_is_denied(): void
    {
        $this->setupAssessmentPlugins();
        $branchA = $this->createBranch('A');
        $teacher = $this->createTeacher($branchA, $branchA);
        $courseA = $this->createCourse($branchA);
        $attemptA = $this->createPendingAttempt($courseA, $branchA, 'Branch A Assessment');
        $this->assignTeacher((int) $teacher->id, $courseA);

        $questionA = (int) DB::table('course_assessment_attempt_questions')->where('attempt_id', $attemptA)->value('id');
        $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptA), [
            'scores' => [$questionA => 4],
            'feedback' => [$questionA => 'Good work.'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptA,
            'status' => 'graded',
            'manual_score' => 4,
            'graded_by' => $teacher->id,
        ]);

        $unassignedTeacher = $this->createTeacher(null, null);
        $unassignedAttempt = $this->createPendingAttempt($courseA, $branchA, 'Unassigned Teacher Must Not Grade');
        $this->assignTeacher((int) $unassignedTeacher->id, $courseA);
        $this->actingAs($unassignedTeacher)
            ->get(route('plugins.course-assessments.grading.index'))
            ->assertForbidden();

        $unassignedQuestion = (int) DB::table('course_assessment_attempt_questions')->where('attempt_id', $unassignedAttempt)->value('id');
        $this->actingAs($unassignedTeacher)->patch(route('plugins.course-assessments.grading.update', $unassignedAttempt), [
            'scores' => [$unassignedQuestion => 2],
        ])->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $unassignedAttempt,
            'status' => 'pending_review',
            'manual_score' => 0,
            'graded_by' => null,
        ]);
    }


    public function test_missing_teacher_profile_storage_fails_closed_when_teacher_management_is_enabled(): void
    {
        $this->setupAssessmentPlugins();
        $branch = $this->createBranch('MISSING-PROFILE');
        $teacher = $this->createTeacher($branch, $branch);
        $course = $this->createCourse($branch);
        $attempt = $this->createPendingAttempt($course, $branch, 'Missing Teacher Profile Storage');
        $this->assignTeacher((int) $teacher->id, $course);
        $question = (int) DB::table('course_assessment_attempt_questions')
            ->where('attempt_id', $attempt)
            ->value('id');

        Schema::dropIfExists('teachers');

        $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attempt), [
            'scores' => [$question => 3],
        ])->assertStatus(503);

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attempt,
            'status' => 'pending_review',
            'manual_score' => 0,
            'graded_by' => null,
        ]);
    }

    public function test_grading_rolls_back_scores_and_feedback_when_required_audit_write_fails(): void
    {
        $this->setupAssessmentPlugins();
        $branch = $this->createBranch('AUDIT-FAILURE');
        $teacher = $this->createTeacher($branch, $branch);
        $courseId = $this->createCourse($branch);
        $attemptId = $this->createPendingAttempt($courseId, $branch, 'Required Audit Failure Assessment');
        $this->assignTeacher((int) $teacher->id, $courseId);
        $questionId = (int) DB::table('course_assessment_attempt_questions')
            ->where('attempt_id', $attemptId)
            ->value('id');

        AuditLog::creating(static function (): void {
            throw new RuntimeException('forced assessment audit persistence failure');
        });

        // Let the injected persistence exception escape Laravel's HTTP exception handler.
        $this->withoutExceptionHandling();

        try {
            $caught = null;
            try {
                $this->actingAs($teacher)->patch(route('plugins.course-assessments.grading.update', $attemptId), [
                    'scores' => [$questionId => 4],
                    'feedback' => [$questionId => 'Must be rolled back.'],
                    'teacher_feedback' => 'Must also be rolled back.',
                ]);
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $this->assertInstanceOf(RuntimeException::class, $caught);
            $this->assertSame('forced assessment audit persistence failure', $caught->getMessage());
            $this->assertDatabaseHas('course_assessment_attempts', [
                'id' => $attemptId,
                'status' => 'pending_review',
                'manual_score' => 0,
                'final_score' => null,
                'graded_by' => null,
                'teacher_feedback' => null,
            ]);
            $this->assertDatabaseHas('course_assessment_attempt_questions', [
                'id' => $questionId,
                'marks_awarded' => 0,
                'manual_feedback' => null,
                'graded_by' => null,
            ]);
            $this->assertDatabaseMissing('audit_logs', [
                'action' => 'course_assessment.graded',
                'entity_type' => 'course_assessment_attempt',
                'entity_id' => (string) $attemptId,
            ]);
        } finally {
            AuditLog::flushEventListeners();
        }
    }


}
