<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearnAndEarnQuestionBankTest extends TestCase
{
    public function test_admin_can_create_edit_archive_and_restore_mcqs(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['learn-and-earn']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/learn-and-earn/questions', [
            'subject' => 'English Grammar',
            'difficulty' => 'easy',
            'question' => 'Choose the correct past participle.',
            'options' => ['go', 'went', 'gone', 'going'],
            'correct_option_index' => 2,
            'positive_marks' => 1,
            'negative_marks' => 0.25,
            'explanation' => 'Gone is the past participle of go.',
        ])->assertRedirect();

        $questionId = (int) DB::table('learn_earn_questions')->value('id');
        $this->assertDatabaseHas('learn_earn_questions', [
            'id' => $questionId,
            'created_by' => $admin->id,
            'subject' => 'English Grammar',
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put('/admin/learn-and-earn/questions/'.$questionId, [
            'subject' => 'Verb Forms',
            'difficulty' => 'hard',
            'question' => 'Choose the correct third form.',
            'options' => ['write', 'wrote', 'written', 'writing'],
            'correct_option_index' => 2,
            'positive_marks' => 2,
            'negative_marks' => 0.5,
            'explanation' => 'Written is the past participle.',
        ])->assertRedirect();

        $this->assertDatabaseHas('learn_earn_questions', [
            'id' => $questionId,
            'subject' => 'Verb Forms',
            'difficulty' => 'hard',
            'question' => 'Choose the correct third form.',
            'explanation' => 'Written is the past participle.',
        ]);

        $this->actingAs($admin)->patch('/admin/learn-and-earn/questions/'.$questionId.'/status', [
            'is_active' => false,
        ])->assertRedirect();
        $this->assertDatabaseHas('learn_earn_questions', ['id' => $questionId, 'is_active' => false]);

        $this->actingAs($admin)->patch('/admin/learn-and-earn/questions/'.$questionId.'/status', [
            'is_active' => true,
        ])->assertRedirect();
        $this->assertDatabaseHas('learn_earn_questions', ['id' => $questionId, 'is_active' => true]);
    }

    public function test_editing_question_bank_item_does_not_change_existing_exam_question_snapshot(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['learn-and-earn', 'exam-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/learn-and-earn/questions', [
            'subject' => 'Tense',
            'difficulty' => 'medium',
            'question' => 'Original snapshot prompt',
            'options' => ['A', 'B', 'C', 'D'],
            'correct_option_index' => 1,
            'positive_marks' => 1,
            'negative_marks' => 0,
            'explanation' => 'Original explanation',
        ])->assertRedirect();

        $questionId = (int) DB::table('learn_earn_questions')->value('id');

        $this->actingAs($admin)->post('/admin/learn-and-earn/exams', [
            'title' => 'Snapshot Integrity Test',
            'question_ids' => [$questionId],
            'duration_minutes' => 30,
            'pass_marks' => 1,
            'type' => 'free',
            'is_published' => true,
        ])->assertRedirect();

        $examId = (int) DB::table('exams')->where('title', 'Snapshot Integrity Test')->value('id');
        $this->assertNotSame(0, $examId);
        $snapshot = DB::table('exam_questions')->where('exam_id', $examId)->first();
        $this->assertSame('Original snapshot prompt', $snapshot->prompt);

        $this->actingAs($admin)->put('/admin/learn-and-earn/questions/'.$questionId, [
            'subject' => 'Tense',
            'difficulty' => 'hard',
            'question' => 'Updated question bank prompt',
            'options' => ['W', 'X', 'Y', 'Z'],
            'correct_option_index' => 3,
            'positive_marks' => 3,
            'negative_marks' => 1,
            'explanation' => 'Updated explanation',
        ])->assertRedirect();

        $this->assertDatabaseHas('exam_questions', [
            'exam_id' => $examId,
            'prompt' => 'Original snapshot prompt',
        ]);
        $this->assertDatabaseHas('learn_earn_questions', [
            'id' => $questionId,
            'question' => 'Updated question bank prompt',
        ]);
    }
}
