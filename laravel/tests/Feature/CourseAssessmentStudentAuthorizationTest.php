<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\CourseAssessments\Models\AssessmentAttempt;
use Plugins\Modules\CourseAssessments\Models\AssessmentSet;
use Tests\TestCase;

class CourseAssessmentStudentAuthorizationTest extends TestCase
{
    public function test_web_attempt_and_history_endpoints_deny_users_after_student_role_revocation(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);

        $student = $this->createUserWithRole('student');
        $set = AssessmentSet::query()->create([
            'title' => 'Role revocation assessment',
            'status' => 'published',
            'created_by' => $student->id,
        ]);
        $attempt = AssessmentAttempt::query()->create([
            'assessment_set_id' => $set->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'max_score' => 0,
        ]);

        $this->actingAs($student)
            ->get('/my-learning/assessments/attempts/' . $attempt->id)
            ->assertOk();

        $student->roles()->detach();

        $this->actingAs($student)
            ->get('/my-learning/assessments/attempts/' . $attempt->id)
            ->assertForbidden();

        $this->actingAs($student)
            ->post('/my-learning/assessments/attempts/' . $attempt->id . '/submit')
            ->assertForbidden();

        $this->actingAs($student)
            ->get('/my-learning/assessments/history')
            ->assertForbidden();

        $this->actingAs($student)
            ->post('/my-learning/assessments/' . $set->id . '/start')
            ->assertForbidden();

        $this->actingAs($student)
            ->put('/my-learning/assessments/attempts/' . $attempt->id . '/questions/1', [
                'selected_option' => 0,
            ])
            ->assertForbidden();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/my-learning/assessments')
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/my-learning/assessments/history')
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/my-learning/assessments/' . $set->id . '/start')
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/my-learning/assessments/attempts/' . $attempt->id)
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->putJson('/api/v1/my-learning/assessments/attempts/' . $attempt->id . '/questions/1', [
                'selected_option' => 0,
            ])
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/my-learning/assessments/attempts/' . $attempt->id . '/submit')
            ->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attempt->id,
            'student_id' => $student->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_inactive_student_account_is_denied_even_when_student_role_remains(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);

        $student = $this->createUserWithRole('student');
        $set = AssessmentSet::query()->create([
            'title' => 'Inactive account assessment',
            'status' => 'published',
            'created_by' => $student->id,
        ]);

        $student->forceFill(['is_active' => false])->save();

        $this->actingAs($student)
            ->get('/my-learning/assessments')
            ->assertForbidden();

        $this->actingAs($student)
            ->post('/my-learning/assessments/' . $set->id . '/start')
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/my-learning/assessments')
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/my-learning/assessments/' . $set->id . '/start')
            ->assertForbidden();
    }

    public function test_student_cannot_open_another_students_assessment_attempt(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);

        $owner = $this->createUserWithRole('student');
        $otherStudent = $this->createUserWithRole('student');
        $set = AssessmentSet::query()->create([
            'title' => 'Attempt ownership assessment',
            'status' => 'published',
            'created_by' => $owner->id,
        ]);
        $attempt = AssessmentAttempt::query()->create([
            'assessment_set_id' => $set->id,
            'student_id' => $owner->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'max_score' => 0,
        ]);

        $this->actingAs($otherStudent)
            ->get('/my-learning/assessments/attempts/' . $attempt->id)
            ->assertNotFound();
    }

    private function mysqlStudentLockFixture(string $label): array
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);

        $student = $this->createUserWithRole('student');
        $set = AssessmentSet::query()->create([
            'title' => 'MySQL student lock ' . $label,
            'status' => 'published',
            'created_by' => $student->id,
        ]);
        $attempt = AssessmentAttempt::query()->create([
            'assessment_set_id' => $set->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'max_score' => 5,
        ]);
        $questionId = (int) DB::table('course_assessment_attempt_questions')->insertGetId([
            'attempt_id' => $attempt->id,
            'source_question_id' => null,
            'type' => 'written',
            'question' => 'Describe a complete sentence.',
            'options' => null,
            'correct_option' => null,
            'correct_answer' => 'A sentence contains a subject and predicate.',
            'explanation' => null,
            'marks' => 5,
            'position' => 1,
            'selected_option' => null,
            'answer_text' => null,
            'is_correct' => null,
            'marks_awarded' => 0,
            'manual_feedback' => null,
            'graded_by' => null,
            'graded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$student, (int) $attempt->id, $questionId];
    }

    /**
     * Run a real HTTP request in a separate PHP process so InnoDB can arbitrate
     * two genuinely independent MySQL sessions. No copied PDO/socket is shared
     * across forked processes.
     */
    private function startMysqlStudentAnswerWorker(int $studentId, int $attemptId, int $questionId, bool $holdOuterTransaction): array
    {
        $script = <<<'PHP'
<?php
declare(strict_types=1);

$payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
$basePath = $payload['base_path'];
chdir($basePath);
require $basePath . '/vendor/autoload.php';
$app = require $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = null;
try {
    $connection = \Illuminate\Support\Facades\DB::connection();
    if ($payload['hold_outer_transaction']) {
        $connection->beginTransaction();
    }

    $student = \App\Models\User::query()->findOrFail((int) $payload['student_id']);
    \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($student);
    \Illuminate\Support\Facades\Auth::shouldUse('sanctum');

    $threadId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
    echo "READY:" . $threadId . PHP_EOL;
    flush();

    $request = \Illuminate\Http\Request::create(
        $payload['uri'],
        'PUT',
        ['answer_text' => 'concurrent answer saved']
    );
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('X-Requested-With', 'XMLHttpRequest');

    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    echo "RESULT:" . $response->getStatusCode() . ':' . substr(preg_replace('/\\s+/', ' ', (string) $response->getContent()), 0, 1000) . PHP_EOL;
    flush();

    if ($payload['hold_outer_transaction']) {
        $command = fgets(STDIN);
        if (trim((string) $command) !== 'COMMIT') {
            throw new RuntimeException('Parent did not release the assessment transaction.');
        }
        $connection->commit();
    }

    $kernel->terminate($request, $response);
    // Explicitly release the independent MySQL session before signalling the
    // parent. This prevents PHP shutdown from keeping the child alive after
    // the lock assertions have completed.
    \Illuminate\Support\Facades\DB::disconnect();
    echo "DONE" . PHP_EOL;
    flush();
    exit(0);
} catch (\Throwable $exception) {
    if ($connection && $connection->transactionLevel() > 0) {
        try {
            $connection->rollBack();
        } catch (\Throwable) {
        }
    }
    echo 'ERROR:' . get_class($exception) . ':' . $exception->getMessage() . PHP_EOL;
    flush();
    exit(2);
}
PHP;

        $scriptPath = tempnam(sys_get_temp_dir(), 'aec-student-lock-');
        if ($scriptPath === false) {
            $this->fail('Unable to create the MySQL concurrency worker script.');
        }
        file_put_contents($scriptPath, $script);

        $payload = base64_encode((string) json_encode([
            'base_path' => base_path(),
            'student_id' => $studentId,
            'uri' => '/api/v1/my-learning/assessments/attempts/' . $attemptId . '/questions/' . $questionId,
            'hold_outer_transaction' => $holdOuterTransaction,
        ], JSON_THROW_ON_ERROR));

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $scriptPath, $payload],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path()
        );

        if (!is_resource($process)) {
            @unlink($scriptPath);
            $this->fail('Unable to start the separate MySQL concurrency worker.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'script_path' => $scriptPath,
            'buffer' => '',
            'stderr' => '',
        ];
    }

    private function readMysqlWorkerLine(array &$worker, int $timeoutSeconds = 20): string
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $chunk = stream_get_contents($worker['pipes'][1]);
            if ($chunk !== false && $chunk !== '') {
                $worker['buffer'] .= $chunk;
            }
            $stderr = stream_get_contents($worker['pipes'][2]);
            if ($stderr !== false && $stderr !== '') {
                $worker['stderr'] .= $stderr;
            }

            $newline = strpos($worker['buffer'], PHP_EOL);
            if ($newline !== false) {
                $line = substr($worker['buffer'], 0, $newline);
                $worker['buffer'] = substr($worker['buffer'], $newline + strlen(PHP_EOL));

                return trim($line);
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail(
            'Timed out waiting for MySQL worker output. stdout=' . $worker['buffer']
            . '; stderr=' . $worker['stderr']
        );
    }

    private function finishMysqlStudentAnswerWorker(array &$worker): void
    {
        // Close only the input pipe first. Keep stdout/stderr open until the
        // child has exited so its final flush/shutdown cannot hit a closed pipe.
        if (is_resource($worker['pipes'][0])) {
            fclose($worker['pipes'][0]);
        }

        $exitCode = null;
        $deadline = microtime(true) + 10;

        do {
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            foreach ([1, 2] as $pipeIndex) {
                if (! is_resource($worker['pipes'][$pipeIndex])) {
                    continue;
                }

                $chunk = stream_get_contents($worker['pipes'][$pipeIndex]);
                if ($chunk === false || $chunk === '') {
                    continue;
                }

                if ($pipeIndex === 1) {
                    $worker['buffer'] .= $chunk;
                } else {
                    $worker['stderr'] .= $chunk;
                }
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        if ($exitCode === null) {
            proc_terminate($worker['process']);
            $status = proc_get_status($worker['process']);
            $exitCode = $status['running'] ? 255 : (int) $status['exitcode'];
        }

        foreach ([1, 2] as $pipeIndex) {
            if (! is_resource($worker['pipes'][$pipeIndex])) {
                continue;
            }

            $chunk = stream_get_contents($worker['pipes'][$pipeIndex]);
            if ($chunk !== false && $chunk !== '') {
                if ($pipeIndex === 1) {
                    $worker['buffer'] .= $chunk;
                } else {
                    $worker['stderr'] .= $chunk;
                }
            }

            fclose($worker['pipes'][$pipeIndex]);
        }

        $closeExitCode = proc_close($worker['process']);
        if ($exitCode === -1 && $closeExitCode >= 0) {
            $exitCode = $closeExitCode;
        }

        @unlink($worker['script_path']);
        $this->assertSame(
            0,
            $exitCode,
            'MySQL worker failed (exit ' . $exitCode . '). stdout=' . $worker['buffer']
            . '; stderr=' . $worker['stderr']
        );
    }

    private function mysqlConcurrencyConnection(string $name): object
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.' . $name => config('database.connections.' . $default)]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function waitForMysqlLockWait(object $monitor, int $threadId): void
    {
        $deadline = microtime(true) + 12;

        do {
            $transaction = $monitor->selectOne(
                'SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?',
                [$threadId]
            );

            if (strtoupper((string) ($transaction->trx_state ?? '')) === 'LOCK WAIT') {
                return;
            }

            usleep(25_000);
        } while (microtime(true) < $deadline);

        $this->fail('The concurrent assessment request never entered an InnoDB row-lock wait.');
    }

    private function assertMysqlLockTimeout(callable $statement, string $message): void
    {
        try {
            $statement();
            $this->fail($message);
        } catch (QueryException $exception) {
            $this->assertSame(
                1205,
                (int) ($exception->errorInfo[1] ?? 0),
                'Expected an InnoDB lock-wait timeout: ' . $exception->getMessage()
            );
        }
    }

    public function test_mysql_student_answer_locks_account_and_role_before_concurrent_revocation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('proc_open')) {
            $this->markTestSkipped('Two-session account/role lock ordering is verified by the MySQL 8 workflow.');
        }

        [$student, $attemptId, $questionId] = $this->mysqlStudentLockFixture('mutation-first');
        $roleId = (int) DB::table('roles')->where('slug', 'student')->value('id');
        $writer = $this->mysqlConcurrencyConnection('assessment_student_writer_first');
        $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $worker = $this->startMysqlStudentAnswerWorker((int) $student->id, $attemptId, $questionId, true);
        try {
            $ready = $this->readMysqlWorkerLine($worker);
            $this->assertStringStartsWith('READY:', $ready);

            $result = $this->readMysqlWorkerLine($worker);
            $this->assertStringStartsWith('RESULT:200:', $result, $worker['stderr']);

            $this->assertMysqlLockTimeout(
                fn () => $writer->table('role_user')
                    ->where('user_id', $student->id)
                    ->where('role_id', $roleId)
                    ->delete(),
                'Role revocation must wait while the assessment request owns the role-pivot lock.'
            );
            $this->assertMysqlLockTimeout(
                fn () => $writer->table('users')->where('id', $student->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]),
                'Account deactivation must wait while the assessment request owns the user-row lock.'
            );

            fwrite($worker['pipes'][0], 'COMMIT' . PHP_EOL);
            fflush($worker['pipes'][0]);
            $this->assertSame('DONE', $this->readMysqlWorkerLine($worker));
        } finally {
            if (is_resource($worker['process'])) {
                @fwrite($worker['pipes'][0], 'COMMIT' . PHP_EOL);
                @fflush($worker['pipes'][0]);
            }
            $this->finishMysqlStudentAnswerWorker($worker);
        }

        $writer->table('role_user')
            ->where('user_id', $student->id)
            ->where('role_id', $roleId)
            ->delete();
        $writer->table('users')->where('id', $student->id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/v1/my-learning/assessments/attempts/' . $attemptId . '/questions/' . $questionId, [
                'answer_text' => 'must not overwrite after revocation',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('course_assessment_attempt_questions', [
            'id' => $questionId,
            'answer_text' => 'concurrent answer saved',
        ]);
        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptId,
            'status' => 'in_progress',
        ]);
    }

    public function test_mysql_waiting_assessment_answer_rejects_role_revocation_that_commits_first(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('proc_open')) {
            $this->markTestSkipped('Two-session account/role lock ordering is verified by the MySQL 8 workflow.');
        }

        [$student, $attemptId, $questionId] = $this->mysqlStudentLockFixture('role-revoke-first');
        $roleId = (int) DB::table('roles')->where('slug', 'student')->value('id');
        $writer = $this->mysqlConcurrencyConnection('assessment_student_writer_role_first');
        $writer->beginTransaction();
        $writer->table('users')->where('id', $student->id)->lockForUpdate()->first();
        $writer->table('role_user')
            ->where('user_id', $student->id)
            ->where('role_id', $roleId)
            ->delete();

        $worker = $this->startMysqlStudentAnswerWorker((int) $student->id, $attemptId, $questionId, false);
        try {
            $ready = $this->readMysqlWorkerLine($worker);
            $this->assertStringStartsWith('READY:', $ready);
            $threadId = (int) substr($ready, strlen('READY:'));
            $this->waitForMysqlLockWait($writer, $threadId);

            $writer->commit();
            $this->assertStringStartsWith('RESULT:403:', $this->readMysqlWorkerLine($worker));
            $this->assertSame('DONE', $this->readMysqlWorkerLine($worker));
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            $this->finishMysqlStudentAnswerWorker($worker);
        }

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $student->id,
            'role_id' => $roleId,
        ]);
        $this->assertDatabaseHas('course_assessment_attempt_questions', [
            'id' => $questionId,
            'answer_text' => null,
        ]);
        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptId,
            'status' => 'in_progress',
        ]);
    }

    public function test_mysql_waiting_assessment_answer_rejects_account_deactivation_that_commits_first(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('proc_open')) {
            $this->markTestSkipped('Two-session account/role lock ordering is verified by the MySQL 8 workflow.');
        }

        [$student, $attemptId, $questionId] = $this->mysqlStudentLockFixture('deactivate-first');
        $writer = $this->mysqlConcurrencyConnection('assessment_student_writer_inactive_first');
        $writer->beginTransaction();
        $writer->table('users')->where('id', $student->id)->lockForUpdate()->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $worker = $this->startMysqlStudentAnswerWorker((int) $student->id, $attemptId, $questionId, false);
        try {
            $ready = $this->readMysqlWorkerLine($worker);
            $this->assertStringStartsWith('READY:', $ready);
            $threadId = (int) substr($ready, strlen('READY:'));
            $this->waitForMysqlLockWait($writer, $threadId);

            $writer->commit();
            $this->assertSame('RESULT:403', $this->readMysqlWorkerLine($worker));
            $this->assertSame('DONE', $this->readMysqlWorkerLine($worker));
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            $this->finishMysqlStudentAnswerWorker($worker);
        }

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $student->id,
            'role_id' => DB::table('roles')->where('slug', 'student')->value('id'),
        ]);
        $this->assertDatabaseHas('course_assessment_attempt_questions', [
            'id' => $questionId,
            'answer_text' => null,
        ]);
        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptId,
            'status' => 'in_progress',
        ]);
    }

}
