<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\BranchManagement\Models\Branch;
use Plugins\Modules\ExamManagement\Http\Controllers\ExamController;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Http\Controllers\PaymentController;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamAttempt;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;
use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class ExamAttemptEligibilityLockTest extends TestCase
{
    public function test_web_and_api_attempt_start_revalidate_publication_and_window_under_exam_lock(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        $controller = app(ExamController::class);

        [$unpublishedExam] = $this->createSnapshotFixture();
        $staleUnpublishedExam = $unpublishedExam->fresh();
        DB::table('exams')->where('id', $unpublishedExam->id)->update(['is_published' => false]);

        $this->assertControllerAbort(
            404,
            fn () => $controller->take(
                $this->studentRequest('GET', '/exams/' . $unpublishedExam->id . '/take', $student),
                $staleUnpublishedExam
            )
        );
        $this->assertSame(
            0,
            ExamAttempt::query()->where('exam_id', $unpublishedExam->id)->count(),
            'An unpublished exam must not create an attempt after a stale route preflight.'
        );

        [$closedWindowExam] = $this->createSnapshotFixture();
        $staleWindowExam = $closedWindowExam->fresh();
        DB::table('exams')->where('id', $closedWindowExam->id)->update([
            'ends_at' => now()->subMinute(),
        ]);

        $this->assertControllerAbort(
            403,
            fn () => $controller->apiTake(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $closedWindowExam->id . '/attempts',
                    $student,
                    [],
                    true
                ),
                $staleWindowExam
            )
        );
        $this->assertSame(
            0,
            ExamAttempt::query()->where('exam_id', $closedWindowExam->id)->count(),
            'An exam whose window closed while waiting for the exam lock must not create an API attempt.'
        );
    }

    public function test_autosave_and_web_api_submit_revalidate_exam_window_without_mutating_attempt(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();

        $this->actingAs($student)->get('/exams/' . $exam->id . '/take')->assertOk();
        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->firstOrFail();
        $staleExam = $exam->fresh();

        DB::table('exams')->where('id', $exam->id)->update([
            'ends_at' => now()->subMinute(),
        ]);

        $controller = app(ExamController::class);
        $this->assertControllerAbort(
            403,
            fn () => $controller->saveAttemptAnswers(
                $this->studentRequest(
                    'POST',
                    '/exams/' . $exam->id . '/attempt/autosave',
                    $student,
                    [
                        'attempt_id' => $attempt->id,
                        'answers' => [(string) $question->id => 1],
                    ]
                ),
                $staleExam
            )
        );

        $this->assertControllerAbort(
            403,
            fn () => $controller->submitAttempt(
                $this->studentRequest(
                    'POST',
                    '/exams/' . $exam->id . '/attempt',
                    $student,
                    [
                        'attempt_id' => $attempt->id,
                        'answers' => [(string) $question->id => 1],
                    ]
                ),
                $staleExam
            )
        );

        $this->assertControllerAbort(
            403,
            fn () => $controller->apiSubmitAttempt(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit',
                    $student,
                    ['answers' => [(string) $question->id => 1]],
                    true
                ),
                $staleExam,
                (int) $attempt->id
            )
        );

        $unchanged = $attempt->fresh();
        $this->assertNull($unchanged->submitted_at);
        $this->assertNull($unchanged->submitted_answers);
        $this->assertSame(0.0, (float) $unchanged->score_obtained);
        $this->assertSame(0.0, (float) $unchanged->total_marks);
        $this->assertFalse($unchanged->passed);
    }

    public function test_exam_listing_and_history_respect_the_canonical_active_student_profile(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'exam-management']);

        $branchA = Branch::query()->create([
            'code' => 'READ-ACCESS-A-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Read Access Branch A',
            'is_active' => true,
        ]);
        $branchB = Branch::query()->create([
            'code' => 'READ-ACCESS-B-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Read Access Branch B',
            'is_active' => true,
        ]);

        // The student profile is the canonical branch for read-only exam
        // discovery and history, matching existing LMS enrollment behavior.
        $student = $this->createUserWithRole('student', (int) $branchA->id);
        Student::query()->create([
            'user_id' => $student->id,
            'branch_id' => $branchB->id,
            'student_code' => 'READ-ACCESS-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        [$accountExam] = $this->createSnapshotFixture();
        DB::table('exams')->where('id', $accountExam->id)->update(['branch_id' => $branchA->id]);
        $profileExam = Exam::query()->create([
            'branch_id' => $branchB->id,
            'title' => 'Canonical Profile Branch Exam',
            'duration_minutes' => 30,
            'max_attempts' => 2,
            'pass_marks' => 5,
            'is_published' => true,
        ]);

        $controller = app(ExamController::class);
        $list = $controller->apiIndex(
            $this->studentRequest('GET', '/api/v1/my/exams', $student, [], true)
        );
        $titles = array_column($list->getData(true)['data'], 'title');
        $this->assertContains('Canonical Profile Branch Exam', $titles);
        $this->assertNotContains('Snapshot Grammar Exam', $titles);

        $this->assertControllerAbort(
            403,
            fn () => $controller->history(
                $this->studentRequest('GET', '/exams/' . $accountExam->id . '/attempts', $student),
                $accountExam->fresh()
            )
        );
        $controller->history(
            $this->studentRequest('GET', '/exams/' . $profileExam->id . '/attempts', $student),
            $profileExam->fresh()
        );

        DB::table('students')->where('user_id', $student->id)->update(['status' => 'inactive']);
        $this->assertControllerAbort(
            403,
            fn () => $controller->apiIndex(
                $this->studentRequest('GET', '/api/v1/my/exams', $student, [], true)
            )
        );
        $this->assertControllerAbort(
            403,
            fn () => $controller->history(
                $this->studentRequest('GET', '/exams/' . $profileExam->id . '/attempts', $student),
                $profileExam->fresh()
            )
        );

        DB::table('students')->where('user_id', $student->id)->update([
            'status' => 'active',
            'deleted_at' => now(),
        ]);
        $this->assertControllerAbort(
            403,
            fn () => $controller->apiIndex(
                $this->studentRequest('GET', '/api/v1/my/exams', $student, [], true)
            )
        );
        $this->assertControllerAbort(
            403,
            fn () => $controller->history(
                $this->studentRequest('GET', '/exams/' . $profileExam->id . '/attempts', $student),
                $profileExam->fresh()
            )
        );

        DB::table('students')->where('user_id', $student->id)->update(['deleted_at' => null]);
        DB::table('users')->where('id', $student->id)->update(['is_active' => false]);
        $this->assertControllerAbort(
            403,
            fn () => $controller->apiIndex(
                $this->studentRequest('GET', '/api/v1/my/exams', $student, [], true)
            )
        );
        $this->assertControllerAbort(
            403,
            fn () => $controller->history(
                $this->studentRequest('GET', '/exams/' . $profileExam->id . '/attempts', $student),
                $profileExam->fresh()
            )
        );
    }

    public function test_api_submit_rechecks_student_branch_after_route_preflight(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'exam-management']);

        $branchA = Branch::query()->create([
            'code' => 'TOCTOU-A-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'TOCTOU Branch A',
            'is_active' => true,
        ]);
        $branchB = Branch::query()->create([
            'code' => 'TOCTOU-B-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'TOCTOU Branch B',
            'is_active' => true,
        ]);

        $student = $this->createUserWithRole('student', (int) $branchA->id);
        Student::query()->create([
            'user_id' => $student->id,
            'branch_id' => $branchA->id,
            'student_code' => 'TOCTOU-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        [$exam, $question] = $this->createSnapshotFixture();
        DB::table('exams')->where('id', $exam->id)->update(['branch_id' => $branchA->id]);

        $this->actingAs($student)->get('/exams/' . $exam->id . '/take')->assertOk();
        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->firstOrFail();
        $staleExam = $exam->fresh();

        DB::table('users')->where('id', $student->id)->update(['branch_id' => $branchB->id]);
        DB::table('students')->where('user_id', $student->id)->update(['branch_id' => $branchB->id]);

        $this->assertControllerAbort(
            403,
            fn () => app(ExamController::class)->apiSubmitAttempt(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit',
                    $student,
                    ['answers' => [(string) $question->id => 1]],
                    true
                ),
                $staleExam,
                (int) $attempt->id
            )
        );

        $unchanged = $attempt->fresh();
        $this->assertNull($unchanged->submitted_at);
        $this->assertNull($unchanged->submitted_answers);
        $this->assertSame(0.0, (float) $unchanged->score_obtained);
        $this->assertSame(0.0, (float) $unchanged->total_marks);
    }

    public function test_conflicting_account_and_student_profile_branches_fail_closed_for_exam_attempts(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'exam-management']);

        $branchA = Branch::query()->create([
            'code' => 'BRANCH-MISMATCH-A-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Mismatch Branch A',
            'is_active' => true,
        ]);
        $branchB = Branch::query()->create([
            'code' => 'BRANCH-MISMATCH-B-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Mismatch Branch B',
            'is_active' => true,
        ]);

        $student = $this->createUserWithRole('student', (int) $branchA->id);
        Student::query()->create([
            'user_id' => $student->id,
            'branch_id' => $branchA->id,
            'student_code' => 'BRANCH-MISMATCH-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        [$exam, $question] = $this->createSnapshotFixture();
        DB::table('exams')->where('id', $exam->id)->update(['branch_id' => $branchA->id]);

        $this->actingAs($student)->get('/exams/' . $exam->id . '/take')->assertOk();
        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        // Keep the Student profile on A but move the linked account to B.
        DB::table('users')->where('id', $student->id)->update([
            'branch_id' => $branchB->id,
        ]);

        $this->assertControllerAbort(
            403,
            fn () => app(ExamController::class)->apiSubmitAttempt(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit',
                    $student,
                    ['answers' => [(string) $question->id => 1]],
                    true
                ),
                $exam->fresh(),
                (int) $attempt->id
            )
        );

        $unchanged = $attempt->fresh();
        $this->assertNull($unchanged->submitted_at);
        $this->assertNull($unchanged->submitted_answers);
        $this->assertSame(0.0, (float) $unchanged->score_obtained);
        $this->assertSame(0.0, (float) $unchanged->total_marks);
        $this->assertFalse($unchanged->passed);
    }

    public function test_inactive_or_soft_deleted_student_profile_cannot_submit_an_existing_attempt(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['student-management', 'exam-management']);

        $inactiveStudent = $this->createUserWithRole('student');
        DB::table('students')->insert([
            'user_id' => $inactiveStudent->id,
            'branch_id' => null,
            'student_code' => 'EXAM-INACTIVE-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [$inactiveExam, $inactiveQuestion] = $this->createSnapshotFixture();

        $this->actingAs($inactiveStudent)->get('/exams/' . $inactiveExam->id . '/take')->assertOk();
        $inactiveAttempt = ExamAttempt::query()
            ->where('exam_id', $inactiveExam->id)
            ->where('user_id', $inactiveStudent->id)
            ->firstOrFail();

        DB::table('students')->where('user_id', $inactiveStudent->id)->update([
            'status' => 'inactive',
            'updated_at' => now(),
        ]);

        $this->assertControllerAbort(
            403,
            fn () => app(ExamController::class)->apiSubmitAttempt(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $inactiveExam->id . '/attempts/' . $inactiveAttempt->id . '/submit',
                    $inactiveStudent,
                    ['answers' => [(string) $inactiveQuestion->id => 1]],
                    true
                ),
                $inactiveExam->fresh(),
                (int) $inactiveAttempt->id
            )
        );

        $this->assertAttemptStillPending($inactiveAttempt);

        $deletedStudent = $this->createUserWithRole('student');
        DB::table('students')->insert([
            'user_id' => $deletedStudent->id,
            'branch_id' => null,
            'student_code' => 'EXAM-DELETED-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [$deletedExam, $deletedQuestion] = $this->createSnapshotFixture();

        $this->actingAs($deletedStudent)->get('/exams/' . $deletedExam->id . '/take')->assertOk();
        $deletedAttempt = ExamAttempt::query()
            ->where('exam_id', $deletedExam->id)
            ->where('user_id', $deletedStudent->id)
            ->firstOrFail();

        DB::table('students')->where('user_id', $deletedStudent->id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertControllerAbort(
            403,
            fn () => app(ExamController::class)->apiSubmitAttempt(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $deletedExam->id . '/attempts/' . $deletedAttempt->id . '/submit',
                    $deletedStudent,
                    ['answers' => [(string) $deletedQuestion->id => 1]],
                    true
                ),
                $deletedExam->fresh(),
                (int) $deletedAttempt->id
            )
        );

        $this->assertAttemptStillPending($deletedAttempt);
    }


    public function test_locked_exam_eligibility_rejects_an_account_deactivated_after_authentication(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();

        $this->actingAs($student)->get('/exams/' . $exam->id . '/take')->assertOk();
        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->firstOrFail();
        $staleExam = $exam->fresh();

        DB::table('users')->where('id', $student->id)->update(['is_active' => false]);

        $controller = app(ExamController::class);
        $this->assertControllerAbort(403, fn () => $controller->take(
            $this->studentRequest('GET', '/exams/' . $exam->id . '/take', $student),
            $staleExam
        ));
        $this->assertControllerAbort(403, fn () => $controller->apiTake(
            $this->studentRequest('POST', '/api/v1/my/exams/' . $exam->id . '/attempts', $student, [], true),
            $staleExam
        ));
        $this->assertControllerAbort(403, fn () => $controller->saveAttemptAnswers(
            $this->studentRequest('POST', '/exams/' . $exam->id . '/attempt/autosave', $student, [
                'attempt_id' => $attempt->id,
                'answers' => [(string) $question->id => 1],
            ]),
            $staleExam
        ));
        $this->assertControllerAbort(403, fn () => $controller->submitAttempt(
            $this->studentRequest('POST', '/exams/' . $exam->id . '/attempt', $student, [
                'attempt_id' => $attempt->id,
                'answers' => [(string) $question->id => 1],
            ]),
            $staleExam
        ));
        $this->assertControllerAbort(403, fn () => $controller->apiSubmitAttempt(
            $this->studentRequest('POST', '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit', $student, [
                'answers' => [(string) $question->id => 1,
            ]], true),
            $staleExam,
            (int) $attempt->id
        ));

        $this->assertAttemptStillPending($attempt);
    }

    public function test_locked_exam_eligibility_rejects_a_revoked_student_role_from_stale_authenticated_relation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        // Cache the authenticated role relation to model a request that passed
        // its pre-transaction role check before the administrator revoked it.
        $student->load('roles');
        [$exam, $question] = $this->createSnapshotFixture();

        $this->actingAs($student)->get('/exams/' . $exam->id . '/take')->assertOk();
        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->firstOrFail();
        $staleExam = $exam->fresh();

        $roleId = DB::table('roles')->where('slug', 'student')->value('id');
        $this->assertNotNull($roleId);
        DB::table('role_user')->where('user_id', $student->id)->where('role_id', $roleId)->delete();

        $controller = app(ExamController::class);
        $this->assertControllerAbort(403, fn () => $controller->take(
            $this->studentRequest('GET', '/exams/' . $exam->id . '/take', $student),
            $staleExam
        ));
        $this->assertControllerAbort(403, fn () => $controller->apiTake(
            $this->studentRequest('POST', '/api/v1/my/exams/' . $exam->id . '/attempts', $student, [], true),
            $staleExam
        ));
        $this->assertControllerAbort(403, fn () => $controller->saveAttemptAnswers(
            $this->studentRequest('POST', '/exams/' . $exam->id . '/attempt/autosave', $student, [
                'attempt_id' => $attempt->id,
                'answers' => [(string) $question->id => 1],
            ]),
            $staleExam
        ));
        $this->assertControllerAbort(403, fn () => $controller->submitAttempt(
            $this->studentRequest('POST', '/exams/' . $exam->id . '/attempt', $student, [
                'attempt_id' => $attempt->id,
                'answers' => [(string) $question->id => 1],
            ]),
            $staleExam
        ));
        $this->assertControllerAbort(403, fn () => $controller->apiSubmitAttempt(
            $this->studentRequest('POST', '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit', $student, [
                'answers' => [(string) $question->id => 1,
            ]], true),
            $staleExam,
            (int) $attempt->id
        ));

        $this->assertAttemptStillPending($attempt);
    }

    public function test_revoked_paid_exam_entitlement_blocks_web_and_api_start_autosave_and_submit(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management', 'learn-and-earn']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();
        $exam->update([
            'learn_earn_type' => 'paid',
            'access_price' => 10,
        ]);

        DB::table('learn_earn_exam_access')->insert([
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'status' => 'granted',
            'payment_reference' => 'TOCTOU-PAID-' . strtoupper(bin2hex(random_bytes(4))),
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $student->createToken('exam-entitlement-toctou')->plainTextToken;
        $start = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/my/exams/' . $exam->id . '/attempts')
            ->assertOk();
        $attemptId = (int) $start->json('attempt.id');
        $this->assertGreaterThan(0, $attemptId);
        $staleExam = $exam->fresh();

        DB::table('learn_earn_exam_access')
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->update(['status' => 'revoked', 'updated_at' => now()]);

        $controller = app(ExamController::class);
        $this->assertControllerAbort(
            403,
            fn () => $controller->take(
                $this->studentRequest('GET', '/exams/' . $exam->id . '/take', $student),
                $staleExam
            )
        );
        $this->assertControllerAbort(
            403,
            fn () => $controller->apiTake(
                $this->studentRequest('POST', '/api/v1/my/exams/' . $exam->id . '/attempts', $student, [], true),
                $staleExam
            )
        );
        $this->assertControllerAbort(
            403,
            fn () => $controller->saveAttemptAnswers(
                $this->studentRequest(
                    'POST',
                    '/exams/' . $exam->id . '/attempt/autosave',
                    $student,
                    [
                        'attempt_id' => $attemptId,
                        'answers' => [(string) $question->id => 1],
                    ]
                ),
                $staleExam
            )
        );
        $this->assertControllerAbort(
            403,
            fn () => $controller->submitAttempt(
                $this->studentRequest(
                    'POST',
                    '/exams/' . $exam->id . '/attempt',
                    $student,
                    [
                        'attempt_id' => $attemptId,
                        'answers' => [(string) $question->id => 1],
                    ]
                ),
                $staleExam
            )
        );
        $this->assertControllerAbort(
            403,
            fn () => $controller->apiSubmitAttempt(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attemptId . '/submit',
                    $student,
                    ['answers' => [(string) $question->id => 1]],
                    true
                ),
                $staleExam,
                $attemptId
            )
        );

        $attempt = ExamAttempt::query()->findOrFail($attemptId);
        $this->assertNull($attempt->submitted_at);
        $this->assertNull($attempt->submitted_answers);
        $this->assertSame(0.0, (float) $attempt->score_obtained);
        $this->assertSame(0.0, (float) $attempt->total_marks);
        $this->assertFalse($attempt->passed);
    }

    public function test_mysql_authenticated_exam_request_holds_user_lock_against_account_deactivation_writer(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('User-row authorization contention requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        [$exam, $question] = $this->createSnapshotFixture();
        $primaryName = DB::getDefaultConnection();
        $secondaryName = $this->createSecondaryMysqlConnection();
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $writerWaited = false;

        DB::listen(function ($query) use ($primaryName, $secondary, $student, &$writerWaited): void {
            $sql = strtolower($query->sql);
            if ($writerWaited || $query->connectionName !== $primaryName
                || !str_contains($sql, 'from ' . chr(96) . 'users' . chr(96))
                || !str_contains($sql, 'for update')) {
                return;
            }

            try {
                $secondary->table('users')->where('id', $student->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
                $this->fail('Account deactivation must wait while the authenticated exam request owns the User row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
                $writerWaited = true;
            }
        });

        try {
            app(ExamController::class)->take(
                $this->studentRequest('GET', '/exams/' . $exam->id . '/take', $student),
                $exam->fresh()
            );
            $this->assertTrue($writerWaited, 'The deactivation writer must contend with the User lock held by the protected exam request.');

            $secondary->table('users')->where('id', $student->id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
            $attempt = ExamAttempt::query()
                ->where('exam_id', $exam->id)
                ->where('user_id', $student->id)
                ->firstOrFail();

            $this->assertControllerAbort(403, fn () => app(ExamController::class)->submitAttempt(
                $this->studentRequest('POST', '/exams/' . $exam->id . '/attempt', $student, [
                    'attempt_id' => $attempt->id,
                    'answers' => [(string) $question->id => 1],
                ]),
                $exam->fresh()
            ));
            $this->assertAttemptStillPending($attempt);
        } finally {
            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        }
    }

    public function test_mysql_authenticated_exam_request_holds_student_role_pivot_lock_against_revocation_writer(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Student-role pivot authorization contention requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management']);
        $student = $this->createUserWithRole('student');
        $student->load('roles');
        [$exam, $question] = $this->createSnapshotFixture();
        $roleId = DB::table('roles')->where('slug', 'student')->value('id');
        $this->assertNotNull($roleId);
        $primaryName = DB::getDefaultConnection();
        $secondaryName = $this->createSecondaryMysqlConnection();
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $writerWaited = false;

        DB::listen(function ($query) use ($primaryName, $secondary, $student, $roleId, &$writerWaited): void {
            $sql = strtolower($query->sql);
            if ($writerWaited || $query->connectionName !== $primaryName
                || !str_contains($sql, 'role_user')
                || !str_contains($sql, 'for update')) {
                return;
            }

            try {
                $secondary->table('role_user')
                    ->where('user_id', $student->id)
                    ->where('role_id', $roleId)
                    ->delete();
                $this->fail('Role revocation must wait while the authenticated exam request owns the Student role pivot lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
                $writerWaited = true;
            }
        });

        try {
            app(ExamController::class)->take(
                $this->studentRequest('GET', '/exams/' . $exam->id . '/take', $student),
                $exam->fresh()
            );
            $this->assertTrue($writerWaited, 'The role-revocation writer must contend with the Student pivot lock held by the protected exam request.');

            $secondary->table('role_user')
                ->where('user_id', $student->id)
                ->where('role_id', $roleId)
                ->delete();
            $attempt = ExamAttempt::query()
                ->where('exam_id', $exam->id)
                ->where('user_id', $student->id)
                ->firstOrFail();

            $this->assertControllerAbort(403, fn () => app(ExamController::class)->submitAttempt(
                $this->studentRequest('POST', '/exams/' . $exam->id . '/attempt', $student, [
                    'attempt_id' => $attempt->id,
                    'answers' => [(string) $question->id => 1],
                ]),
                $exam->fresh()
            ));
            $this->assertAttemptStillPending($attempt);
        } finally {
            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        }
    }

    public function test_mysql_exam_lock_order_and_second_connection_nowait_contention(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL 8 row-lock order and second-connection contention are verified by the MySQL workflow.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['exam-management', 'student-management', 'learn-and-earn']);
        $student = $this->createUserWithRole('student');
        Student::query()->create([
            'user_id' => $student->id,
            'branch_id' => null,
            'student_code' => 'LOCK-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        [$exam] = $this->createSnapshotFixture();
        $exam->update([
            'learn_earn_type' => 'paid',
            'access_price' => 10,
        ]);
        DB::table('learn_earn_exam_access')->insert([
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'status' => 'granted',
            'payment_reference' => 'LOCK-' . strtoupper(bin2hex(random_bytes(4))),
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lockedSql = [];
        DB::listen(function ($query) use (&$lockedSql): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                $lockedSql[] = $sql;
            }
        });

        $this->actingAs($student)->get('/exams/' . $exam->id . '/take')->assertOk();

        $lockIndex = static function (string $table) use ($lockedSql): ?int {
            foreach ($lockedSql as $index => $sql) {
                if (str_contains($sql, 'from ' . chr(96) . $table . chr(96))) {
                    return $index;
                }
            }

            return null;
        };
        $examLock = $lockIndex('exams');
        $userLock = $lockIndex('users');
        $roleLock = $lockIndex('role_user');
        $studentLock = $lockIndex('students');
        $accessLock = $lockIndex('learn_earn_exam_access');
        $attemptLock = $lockIndex('exam_attempts');

        $this->assertNotNull($examLock, json_encode($lockedSql, JSON_THROW_ON_ERROR));
        $this->assertNotNull($userLock, json_encode($lockedSql, JSON_THROW_ON_ERROR));
        $this->assertNotNull($roleLock, json_encode($lockedSql, JSON_THROW_ON_ERROR));
        $this->assertNotNull($studentLock, json_encode($lockedSql, JSON_THROW_ON_ERROR));
        $this->assertNotNull($accessLock, json_encode($lockedSql, JSON_THROW_ON_ERROR));
        $this->assertNotNull($attemptLock, json_encode($lockedSql, JSON_THROW_ON_ERROR));
        $this->assertLessThan($userLock, $examLock);
        $this->assertLessThan($roleLock, $userLock);
        $this->assertLessThan($studentLock, $roleLock);
        $this->assertLessThan($accessLock, $studentLock);
        $this->assertLessThan($attemptLock, $accessLock);

        $connection = DB::connection();
        $secondaryName = 'exam_lock_secondary';
        $secondaryConfig = config('database.connections.mysql');
        $secondaryConfig['database'] = $connection->getDatabaseName();
        config(['database.connections.' . $secondaryName => $secondaryConfig]);
        DB::purge($secondaryName);
        $secondary = DB::connection($secondaryName);

        $connection->beginTransaction();
        try {
            $connection->table('exams')->where('id', $exam->id)->lockForUpdate()->firstOrFail();
            $secondary->beginTransaction();

            try {
                $secondary->selectOne('SELECT id FROM exams WHERE id = ? FOR UPDATE NOWAIT', [$exam->id]);
                $this->fail('A second MySQL connection must not acquire the exam row while the first connection holds its lock.');
            } catch (QueryException $exception) {
                $this->assertSame(3572, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            }
        } finally {
            if ($secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::purge($secondaryName);
        }
    }

    public function test_mysql_attempt_lock_first_blocks_the_real_refund_entitlement_revocation_writer(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The real refund-writer lock interleaving is verified by the MySQL 8 workflow.');
        }

        [$student, $exam, $attempt, $invoiceId, $orderNumber] = $this->createPaidExamRefundFixture();
        $primary = DB::connection();
        $secondaryName = $this->createSecondaryMysqlConnection();
        $secondary = DB::connection($secondaryName);

        $primary->beginTransaction();
        try {
            $primary->table('exams')->where('id', $exam->id)->lockForUpdate()->firstOrFail();
            $primary->table('users')->where('id', $student->id)->lockForUpdate()->firstOrFail();
            $primary->table('students')->where('user_id', $student->id)->lockForUpdate()->firstOrFail();
            $primary->table('learn_earn_exam_access')
                ->where('exam_id', $exam->id)
                ->where('user_id', $student->id)
                ->lockForUpdate()
                ->firstOrFail();
            $primary->table('exam_attempts')->where('id', $attempt->id)->lockForUpdate()->firstOrFail();

            $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $secondary->beginTransaction();
            try {
                $this->invokeRefundEntitlementRevocation($secondaryName, $invoiceId);
                $this->fail('The existing refund writer must wait when the in-flight attempt already owns the paid-entitlement row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }

            $this->assertSame(
                'granted',
                $primary->table('learn_earn_exam_access')
                    ->where('exam_id', $exam->id)
                    ->where('user_id', $student->id)
                    ->value('status'),
                'A timed-out refund transaction must not revoke access while the attempt holds its entitlement lock.'
            );
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
        }

        $secondary->beginTransaction();
        try {
            $this->invokeRefundEntitlementRevocation($secondaryName, $invoiceId);
            $secondary->commit();
        } catch (\Throwable $exception) {
            if ($secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }
            throw $exception;
        }

        $this->assertDatabaseHas('learn_earn_exam_access', [
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'payment_reference' => $orderNumber,
            'status' => 'revoked',
        ]);

        $this->assertControllerAbort(
            403,
            fn () => app(ExamController::class)->apiSubmitAttempt(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit',
                    $student,
                    ['answers' => [(string) $exam->questions()->firstOrFail()->id => 1]],
                    true
                ),
                $exam->fresh(),
                (int) $attempt->id
            )
        );

        $this->assertAttemptStillPending($attempt);
        DB::purge($secondaryName);
    }

    public function test_mysql_refund_writer_first_makes_waiting_submit_retry_fail_closed(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The refund-first lock interleaving is verified by the MySQL 8 workflow.');
        }

        [$student, $exam, $attempt, $invoiceId, $orderNumber] = $this->createPaidExamRefundFixture();
        $primary = DB::connection();
        $secondaryName = $this->createSecondaryMysqlConnection();
        $secondary = DB::connection($secondaryName);

        $secondary->beginTransaction();
        try {
            $this->invokeRefundEntitlementRevocation($secondaryName, $invoiceId);
            $this->assertSame(
                'revoked',
                $secondary->table('learn_earn_exam_access')
                    ->where('exam_id', $exam->id)
                    ->where('user_id', $student->id)
                    ->value('status')
            );

            $primary->statement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                app(ExamController::class)->apiSubmitAttempt(
                    $this->studentRequest(
                        'POST',
                        '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit',
                        $student,
                        ['answers' => [(string) $exam->questions()->firstOrFail()->id => 1]],
                        true
                    ),
                    $exam->fresh(),
                    (int) $attempt->id
                );
                $this->fail('Submission must wait for the refund-owned entitlement row rather than bypassing its lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            }

            $secondary->commit();
        } catch (\Throwable $exception) {
            if ($secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }
            throw $exception;
        }

        $this->assertDatabaseHas('learn_earn_exam_access', [
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'payment_reference' => $orderNumber,
            'status' => 'revoked',
        ]);

        $this->assertControllerAbort(
            403,
            fn () => app(ExamController::class)->apiSubmitAttempt(
                $this->studentRequest(
                    'POST',
                    '/api/v1/my/exams/' . $exam->id . '/attempts/' . $attempt->id . '/submit',
                    $student,
                    ['answers' => [(string) $exam->questions()->firstOrFail()->id => 1]],
                    true
                ),
                $exam->fresh(),
                (int) $attempt->id
            )
        );

        $this->assertAttemptStillPending($attempt);
        DB::purge($secondaryName);
    }

    public function test_conflicting_user_and_student_profile_branches_fail_closed_on_attempt_start(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'exam-management']);

        $branchA = Branch::query()->create([
            'code' => 'EXAM-MISMATCH-A-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Exam Mismatch Branch A',
            'is_active' => true,
        ]);
        $branchB = Branch::query()->create([
            'code' => 'EXAM-MISMATCH-B-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Exam Mismatch Branch B',
            'is_active' => true,
        ]);

        $student = $this->createUserWithRole('student', (int) $branchA->id);
        Student::query()->create([
            'user_id' => $student->id,
            'branch_id' => $branchB->id,
            'student_code' => 'EXAM-MISMATCH-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        [$exam] = $this->createSnapshotFixture();
        DB::table('exams')->where('id', $exam->id)->update(['branch_id' => $branchA->id]);

        $controller = app(ExamController::class);
        $request = fn () => $this->studentRequest(
            'GET',
            '/exams/' . $exam->id . '/take',
            $student
        );

        // Account=A, profile=B must not silently prefer either identity.
        $this->assertControllerAbort(403, fn () => $controller->take($request(), $exam->fresh()));

        // The inverse mismatch must fail identically.
        DB::table('users')->where('id', $student->id)->update(['branch_id' => $branchB->id]);
        DB::table('students')->where('user_id', $student->id)->update(['branch_id' => $branchA->id]);
        $this->assertControllerAbort(403, fn () => $controller->take($request(), $exam->fresh()));

        $this->assertSame(
            0,
            ExamAttempt::query()->where('exam_id', $exam->id)->where('user_id', $student->id)->count(),
            'Conflicting account/profile branch identities must never create an exam attempt.'
        );
    }

    private function createPaidExamRefundFixture(): array
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'exam-management',
            'student-management',
            'learn-and-earn',
            'commerce-cart',
            'payment-management',
        ]);

        $student = $this->createUserWithRole('student');
        Student::query()->create([
            'user_id' => $student->id,
            'branch_id' => null,
            'student_code' => 'REFUND-' . strtoupper(bin2hex(random_bytes(4))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        [$exam] = $this->createSnapshotFixture();
        $exam->update([
            'learn_earn_type' => 'paid',
            'access_price' => 10,
        ]);

        $invoiceId = random_int(10000000, 90000000);
        $orderNumber = 'REFUND-' . strtoupper(bin2hex(random_bytes(5)));
        $orderId = DB::table('commerce_orders')->insertGetId([
            'order_number' => $orderNumber,
            'user_id' => $student->id,
            'invoice_id' => $invoiceId,
            'currency' => 'BDT',
            'subtotal' => 10,
            'discount_total' => 0,
            'grand_total' => 10,
            'status' => 'paid',
            'payment_status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commerce_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'mock_test',
            'item_id' => $exam->id,
            'title_snapshot' => 'Paid mock test',
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('learn_earn_exam_access')->insert([
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'status' => 'granted',
            'payment_reference' => $orderNumber,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->get('/exams/' . $exam->id . '/take')->assertOk();
        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        return [$student, $exam, $attempt, $invoiceId, $orderNumber];
    }

    private function createSecondaryMysqlConnection(): string
    {
        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = 'exam_refund_secondary_' . bin2hex(random_bytes(3));
        $secondaryConfig = config('database.connections.' . $primaryName);
        $secondaryConfig['database'] = $primary->getDatabaseName();
        config(['database.connections.' . $secondaryName => $secondaryConfig]);
        DB::purge($secondaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $secondaryName;
    }

    private function invokeRefundEntitlementRevocation(string $connectionName, int $invoiceId): void
    {
        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection($connectionName);

        try {
            // Refund settlement owns the production commerce/SCORM entitlement
            // revocation writer; keep this MySQL lock test on that implementation.
            $settlement = app(\Plugins\Modules\PaymentManagement\Services\RefundSettlementService::class);
            $method = new \ReflectionMethod(
                \Plugins\Modules\PaymentManagement\Services\RefundSettlementService::class,
                'suspendCommerceAccessAfterRefund'
            );
            $method->setAccessible(true);

            $invoice = new FeeInvoice();
            $invoice->setAttribute('id', $invoiceId);
            $invoice->setAttribute('net_amount', 10.0);

            $method->invoke($settlement, $invoice, 0.0);
        } finally {
            DB::setDefaultConnection($originalDefault);
        }
    }

    private function assertAttemptStillPending(ExamAttempt $attempt): void
    {
        $unchanged = $attempt->fresh();
        $this->assertNull($unchanged->submitted_at);
        $this->assertNull($unchanged->submitted_answers);
        $this->assertSame(0.0, (float) $unchanged->score_obtained);
        $this->assertSame(0.0, (float) $unchanged->total_marks);
        $this->assertFalse($unchanged->passed);
    }

    private function studentRequest(
        string $method,
        string $uri,
        object $student,
        array $input = [],
        bool $expectsJson = false
    ): Request {
        $server = $expectsJson ? ['HTTP_ACCEPT' => 'application/json'] : [];
        $request = Request::create($uri, $method, $input, [], [], $server);
        $request->setUserResolver(static fn () => $student);

        return $request;
    }

    private function assertControllerAbort(int $expectedStatus, callable $action): void
    {
        try {
            $action();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame($expectedStatus, $exception->getStatusCode());

            return;
        }

        $this->fail('Expected the controller to abort with HTTP ' . $expectedStatus . '.');
    }

    private function createSnapshotFixture(): array
    {
        $exam = Exam::query()->create([
            'title' => 'Snapshot Grammar Exam',
            'duration_minutes' => 30,
            'max_attempts' => 2,
            'pass_marks' => 5,
            'is_published' => true,
        ]);
        $question = ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'Original question text',
            'options' => ['Original option A', 'Original option B'],
            'correct_option_index' => 1,
            'marks' => 5,
            'negative_marks' => 1,
        ]);

        return [$exam, $question];
    }
}
