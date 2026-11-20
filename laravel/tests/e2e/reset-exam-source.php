<?php

declare(strict_types=1);

use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamAttempt;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = \Illuminate\Support\Facades\DB::connection();
$databasePath = str_replace('\\', '/', $connection->getDatabaseName());
$isolatedE2eDatabase = $connection->getDriverName() === 'sqlite'
    && preg_match('~(?:^|/)database/e2e\.sqlite$~', $databasePath) === 1;

if (!$app->environment('testing') || !$isolatedE2eDatabase) {
    fwrite(STDERR, "Refusing to modify E2E fixtures outside the isolated APP_ENV=testing SQLite database (database/e2e.sqlite).\n");
    exit(1);
}
if (!$app->environment('testing')) {
    fwrite(STDERR, "Refusing to reset E2E exam fixtures outside APP_ENV=testing.\n");
    exit(1);
}

$examId = (int) ($argv[1] ?? 0);
$questionId = (int) ($argv[2] ?? 0);

$exam = Exam::query()->findOrFail($examId);
$question = ExamQuestion::query()
    ->where('exam_id', $examId)
    ->whereKey($questionId)
    ->firstOrFail();

// This is an isolated E2E fixture: remove attempts and any extra test questions
// left behind by a failed Playwright retry, then restore its canonical source data.
ExamAttempt::query()->where('exam_id', $examId)->delete();
ExamQuestion::query()
    ->where('exam_id', $examId)
    ->where('id', '<>', $questionId)
    ->delete();

$exam->update([
    'duration_minutes' => 30,
    'pass_marks' => 2,
    'is_published' => true,
]);

$question->update([
    'prompt' => 'E2E snapshot question: what is 2 + 2?',
    'options' => ['3', '4', '5'],
    'correct_option_index' => 1,
    'marks' => 2,
    'negative_marks' => 1,
]);

echo "E2E exam fixture reset.\n";
