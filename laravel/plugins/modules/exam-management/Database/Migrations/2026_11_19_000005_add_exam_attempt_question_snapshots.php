<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('exam_attempts') || !Schema::hasTable('exams')) {
            return;
        }

        if (!Schema::hasColumn('exam_attempts', 'question_snapshot')) {
            Schema::table('exam_attempts', function (Blueprint $table): void {
                $table->json('question_snapshot')->nullable();
            });
        }

        if (!Schema::hasTable('exam_questions')) {
            return;
        }

        // Legacy policy: existing attempts are frozen against the exam/question
        // state present at this migration. Their original historical question
        // state cannot be reconstructed because older rows did not store it.
        DB::table('exam_attempts')
            ->whereNull('question_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($attempts): void {
                foreach ($attempts as $attempt) {
                    $exam = DB::table('exams')->where('id', $attempt->exam_id)->first();
                    if (!$exam) {
                        continue;
                    }

                    $questions = DB::table('exam_questions')
                        ->where('exam_id', $attempt->exam_id)
                        ->orderBy('id')
                        ->get();

                    $snapshotQuestions = $questions->map(static function ($question): array {
                        $options = is_array($question->options)
                            ? $question->options
                            : (json_decode((string) $question->options, true) ?: []);

                        return [
                            'id' => (int) $question->id,
                            'prompt' => (string) $question->prompt,
                            'options' => array_values($options),
                            'correct_option_index' => (int) $question->correct_option_index,
                            'marks' => (float) $question->marks,
                            'negative_marks' => property_exists($question, 'negative_marks')
                                ? (float) ($question->negative_marks ?? 0)
                                : 0.0,
                        ];
                    })->values()->all();

                    $snapshot = [
                        'version' => 1,
                        'exam' => [
                            'duration_minutes' => (int) $exam->duration_minutes,
                            'pass_marks' => (float) $exam->pass_marks,
                        ],
                        'questions' => $snapshotQuestions,
                    ];

                    DB::table('exam_attempts')
                        ->where('id', $attempt->id)
                        ->whereNull('question_snapshot')
                        ->update(['question_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                }
            });
    }

    public function down(): void
    {
        // Preserve attempt evidence and grading history across plugin rollback.
        // The nullable snapshot column is intentionally retained.
    }
};
