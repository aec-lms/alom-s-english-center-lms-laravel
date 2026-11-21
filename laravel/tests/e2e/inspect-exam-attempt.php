<?php

declare(strict_types=1);

use Plugins\Modules\ExamManagement\Models\ExamAttempt;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$attemptId = (int) ($argv[1] ?? 0);
$attempt = ExamAttempt::query()->findOrFail($attemptId);

echo json_encode([
    'id' => $attempt->id,
    'submitted_at' => $attempt->submitted_at?->toIso8601String(),
    'score_obtained' => (float) $attempt->score_obtained,
    'total_marks' => (float) $attempt->total_marks,
    'exam_snapshot' => $attempt->exam_snapshot,
], JSON_THROW_ON_ERROR);
