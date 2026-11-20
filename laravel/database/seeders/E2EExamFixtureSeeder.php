<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Plugins\PluginManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamAttempt;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;

class E2EExamFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $plugins = app(PluginManager::class);
        if (!$plugins->isInstalled('exam-management')) {
            $plugins->install('exam-management');
        } elseif (!$plugins->isEnabled('exam-management')) {
            $plugins->enable('exam-management');
        }

        $studentRole = Role::query()->where('slug', 'student')->firstOrFail();
        $student = User::query()->updateOrCreate(
            ['email' => 'e2e-student@aec.test'],
            [
                'name' => 'E2E Exam Student',
                'password' => Hash::make('E2E-Strong-Password-2026!'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $student->roles()->syncWithoutDetaching([$studentRole->id]);

        $exam = Exam::query()->create([
            'title' => 'E2E Frozen Snapshot Exam',
            'duration_minutes' => 30,
            'max_attempts' => 2,
            'pass_marks' => 2,
            'is_published' => true,
        ]);
        $question = ExamQuestion::query()->create([
            'exam_id' => $exam->id,
            'prompt' => 'E2E snapshot question: what is 2 + 2?',
            'options' => ['3', '4', '5'],
            'correct_option_index' => 1,
            'marks' => 2,
            'negative_marks' => 1,
        ]);

        $legacyExam = Exam::query()->create([
            'title' => 'E2E Legacy Snapshot Exam',
            'duration_minutes' => 20,
            'max_attempts' => 2,
            'pass_marks' => 5,
            'is_published' => true,
        ]);
        ExamQuestion::query()->create([
            'exam_id' => $legacyExam->id,
            'prompt' => 'Legacy question that must not be guessed',
            'options' => ['A', 'B'],
            'correct_option_index' => 0,
            'marks' => 10,
            'negative_marks' => 0,
        ]);
        $legacyAttempt = ExamAttempt::query()->create([
            'exam_id' => $legacyExam->id,
            'user_id' => $student->id,
            'started_at' => now(),
            'submitted_answers' => [],
            'exam_snapshot' => null,
            'score_obtained' => 7,
            'total_marks' => 10,
            'passed' => false,
            'submitted_at' => null,
        ]);

        $corruptExam = Exam::query()->create([
            'title' => 'E2E Corrupt Snapshot Exam',
            'duration_minutes' => 20,
            'max_attempts' => 2,
            'pass_marks' => 5,
            'is_published' => true,
        ]);
        ExamQuestion::query()->create([
            'exam_id' => $corruptExam->id,
            'prompt' => 'Corrupt snapshot question must not be reconstructed',
            'options' => ['Unsafe option A', 'Unsafe option B'],
            'correct_option_index' => 1,
            'marks' => 10,
            'negative_marks' => 0,
        ]);
        $corruptAttempt = ExamAttempt::query()->create([
            'exam_id' => $corruptExam->id,
            'user_id' => $student->id,
            'started_at' => now(),
            'submitted_answers' => ['1' => 0],
            'exam_snapshot' => ['version' => 999, 'questions' => []],
            'score_obtained' => 3.25,
            'total_marks' => 10,
            'passed' => false,
            'submitted_at' => null,
        ]);

        $directory = storage_path('framework/testing');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($directory . '/e2e-exam-fixture.json', json_encode([
            'student_email' => $student->email,
            'exam_id' => $exam->id,
            'question_id' => $question->id,
            'legacy_exam_id' => $legacyExam->id,
            'legacy_attempt_id' => $legacyAttempt->id,
            'corrupt_exam_id' => $corruptExam->id,
            'corrupt_attempt_id' => $corruptAttempt->id,
        ], JSON_THROW_ON_ERROR));
    }
}
