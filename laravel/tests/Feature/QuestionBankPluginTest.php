<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuestionBankPluginTest extends TestCase
{
    private function admin(): object
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['question-bank']);

        return $this->createUserWithRole('admin');
    }

    private function createQuestion(array $overrides = []): int
    {
        return DB::table('question_bank_questions')->insertGetId(array_merge([
            'exam_category' => 'bcs',
            'question_type' => 'mcq',
            'subject' => 'English',
            'topic' => 'Grammar',
            'difficulty' => 'medium',
            'prompt' => 'Choose the correct verb.',
            'options' => json_encode(['He go', 'He goes', 'He going']),
            'correct_answer' => 'He goes',
            'explanation' => 'Third-person singular takes -s.',
            'default_marks' => 1,
            'default_negative_marks' => 0.25,
            'tags' => json_encode(['grammar']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_question_bank_plugin_is_independent_and_stores_separate_categories_and_types(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('plugins.question-bank.questions.store'), [
            'exam_category' => 'bank_job',
            'question_type' => 'mcq',
            'subject' => 'Mathematics',
            'topic' => 'Percentage',
            'difficulty' => 'hard',
            'prompt' => 'What is 25% of 200?',
            'options' => ['25', '40', '50', '75'],
            'correct_answer' => '50',
            'default_marks' => 1,
            'default_negative_marks' => 0.25,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('plugins.question-bank.questions.store'), [
            'exam_category' => 'admission',
            'question_type' => 'written',
            'subject' => 'Bangla',
            'topic' => 'Essay',
            'difficulty' => 'medium',
            'prompt' => 'Write an essay on language movement.',
            'correct_answer' => 'Award marks for relevance, structure and evidence.',
            'default_marks' => 10,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('question_bank_questions', ['exam_category' => 'bank_job', 'question_type' => 'mcq', 'subject' => 'Mathematics']);
        $this->assertDatabaseHas('question_bank_questions', ['exam_category' => 'admission', 'question_type' => 'written', 'subject' => 'Bangla']);
        $this->assertDatabaseHas('plugins', ['slug' => 'question-bank', 'is_installed' => true, 'is_enabled' => true]);
    }

    public function test_manual_paper_snapshot_is_immutable_and_answer_key_is_separate_from_printed_paper(): void
    {
        $admin = $this->admin();
        $questionId = $this->createQuestion();

        $response = $this->actingAs($admin)->post(route('plugins.question-bank.papers.store'), [
            'exam_category' => 'bcs',
            'title' => 'BCS English Model Test',
            'duration_minutes' => 45,
            'selection_mode' => 'manual',
            'question_ids' => [$questionId],
        ])->assertRedirect();

        $paperId = (int) basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->assertGreaterThan(0, $paperId);

        DB::table('question_bank_questions')->where('id', $questionId)->update([
            'prompt' => 'Changed after paper generation.',
            'correct_answer' => 'Changed answer.',
        ]);

        $this->assertDatabaseHas('question_bank_paper_items', [
            'paper_id' => $paperId,
            'question_id' => $questionId,
        ]);
        $snapshot = DB::table('question_bank_paper_items')->where('paper_id', $paperId)->value('question_snapshot');
        $snapshot = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Choose the correct verb.', $snapshot['prompt']);
        $this->assertSame('He goes', $snapshot['correct_answer']);

        $this->actingAs($admin)->get(route('plugins.question-bank.papers.print', $paperId))
            ->assertOk()->assertSee('Choose the correct verb.')->assertDontSee('He goes');
        $this->actingAs($admin)->get(route('plugins.question-bank.papers.answer-key', $paperId))
            ->assertOk()->assertSee('He goes');
    }

    public function test_random_paper_respects_category_type_and_requested_count(): void
    {
        $admin = $this->admin();
        $this->createQuestion(['subject' => 'English']);
        $this->createQuestion(['subject' => 'English', 'prompt' => 'Select the synonym.', 'correct_answer' => 'Rapid']);
        $this->createQuestion(['exam_category' => 'admission', 'subject' => 'English', 'prompt' => 'Admission-only question.']);

        $response = $this->actingAs($admin)->post(route('plugins.question-bank.papers.store'), [
            'exam_category' => 'bcs',
            'title' => 'Random BCS English Set',
            'duration_minutes' => 60,
            'selection_mode' => 'random',
            'random_count' => 2,
            'question_type' => 'mcq',
            'subject' => 'English',
        ])->assertRedirect();

        $paperId = (int) basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->assertSame(2, DB::table('question_bank_paper_items')->where('paper_id', $paperId)->count());
        $this->assertDatabaseHas('question_bank_papers', ['id' => $paperId, 'exam_category' => 'bcs', 'selection_mode' => 'random']);
    }
}
