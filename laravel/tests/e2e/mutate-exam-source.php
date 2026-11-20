<?php

declare(strict_types=1);

use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\ExamManagement\Models\ExamQuestion;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$examId = (int) ($argv[1] ?? 0);
$exam = Exam::query()->with('questions')->findOrFail($examId);
$question = $exam->questions->first();
if (!$question) {
    throw new RuntimeException('E2E exam fixture has no question.');
}

$exam->update([
    'duration_minutes' => 1,
    'pass_marks' => 100,
]);
$question->update([
    'prompt' => 'UPDATED SOURCE QUESTION — must not replace the active snapshot',
    'options' => ['Edited option X', 'Edited option Y'],
    'correct_option_index' => 0,
    'marks' => 100,
    'negative_marks' => 50,
]);

echo "Source exam mutated.\n";
