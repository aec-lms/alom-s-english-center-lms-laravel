<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuizContestPluginTest extends TestCase
{
    private function createContest(?int $branchId = null, ?string $startsAt = null, ?string $endsAt = null): int
    {
        $admin = $this->createUserWithRole('admin');
        $id = DB::table('quiz_contests')->insertGetId([
            'created_by' => $admin->id,
            'branch_id' => $branchId,
            'title' => 'Grammar Challenge',
            'description' => 'A short English quiz.',
            'prize_description' => 'Certificate',
            'winner_count' => 1,
            'starts_at' => $startsAt ?? now()->subMinute(),
            'ends_at' => $endsAt ?? now()->addHour(),
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([
            ['question' => 'Choose the correct verb.', 'options' => ['go', 'goes', 'going', 'gone'], 'correct' => 1],
            ['question' => 'Choose the article.', 'options' => ['a', 'an', 'the', 'no article'], 'correct' => 1],
        ] as $position => $question) {
            DB::table('quiz_contest_questions')->insert([
                'quiz_contest_id' => $id,
                'question' => $question['question'],
                'options' => json_encode($question['options']),
                'correct_option' => $question['correct'],
                'position' => $position + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $id;
    }

    public function test_quiz_contest_installs_without_other_academic_plugins_and_student_can_submit_once(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['quiz-contest']);
        $student = $this->createUserWithRole('student');
        $contest = $this->createContest();

        $this->actingAs($student)->get("/quiz-contests/{$contest}")->assertOk()->assertDontSee('সঠিক উত্তর:');
        $questions = DB::table('quiz_contest_questions')->where('quiz_contest_id', $contest)->orderBy('position')->pluck('id');
        $answers = [$questions[0] => 1, $questions[1] => 1];

        $this->actingAs($student)->post("/quiz-contests/{$contest}/submit", ['answers' => $answers])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('quiz_contest_entries', ['quiz_contest_id' => $contest, 'user_id' => $student->id, 'score' => 2]);

        $this->actingAs($student)->post("/quiz-contests/{$contest}/submit", ['answers' => $answers])->assertStatus(409);
    }

    public function test_contest_rejects_incomplete_answers_and_hides_answer_key_until_end(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['quiz-contest']);
        $student = $this->createUserWithRole('student');
        $contest = $this->createContest();
        $questions = DB::table('quiz_contest_questions')->where('quiz_contest_id', $contest)->orderBy('position')->pluck('id');

        $this->actingAs($student)->post("/quiz-contests/{$contest}/submit", [
            'answers' => [$questions[0] => 1],
        ])->assertStatus(422);

        $this->actingAs($student)->get("/quiz-contests/{$contest}")->assertOk()->assertDontSee('সঠিক উত্তর:');

        DB::table('quiz_contests')->where('id', $contest)->update(['ends_at' => now()->subMinute()]);
        $this->actingAs($student)->get("/quiz-contests/{$contest}")->assertOk();
    }

    public function test_branch_manager_can_only_manage_and_draw_contests_for_own_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'quiz-contest']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'QUIZ-A', 'name' => 'Quiz Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'QUIZ-B', 'name' => 'Quiz Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $permission = Permission::query()->where('slug', 'quiz.contests.manage')->firstOrFail();
        Role::query()->where('slug', 'branch_manager')->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($manager)->post('/admin/quiz-contests', [
            'title' => 'Branch Quiz',
            'prize_description' => 'Certificate',
            'winner_count' => 1,
            'starts_at' => now()->addHour()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
            'branch_id' => $branchB,
            'questions' => [[
                'question' => 'One question?',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_option' => 0,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('quiz_contests', ['title' => 'Branch Quiz', 'branch_id' => $branchA]);
        $this->assertDatabaseMissing('quiz_contests', ['title' => 'Branch Quiz', 'branch_id' => $branchB]);

        $otherContest = $this->createContest($branchB);
        $this->actingAs($manager)->get('/admin/quiz-contests')->assertOk()->assertDontSee('Grammar Challenge');
        $this->actingAs($manager)->get("/admin/quiz-contests/{$otherContest}/entries")->assertNotFound();
    }

    public function test_results_are_published_by_score_then_earliest_submission_and_locked(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['quiz-contest']);
        $admin = $this->createUserWithRole('admin');
        $studentA = $this->createUserWithRole('student');
        $studentB = $this->createUserWithRole('student');
        $contest = $this->createContest(null, now()->subDays(2)->toDateTimeString(), now()->subMinute()->toDateTimeString());
        $questionIds = DB::table('quiz_contest_questions')->where('quiz_contest_id', $contest)->orderBy('position')->pluck('id');
        DB::table('quiz_contest_entries')->insert([
            [
                'quiz_contest_id' => $contest, 'user_id' => $studentA->id,
                'answers' => json_encode([$questionIds[0] => 1, $questionIds[1] => 0]),
                'score' => 1, 'submitted_at' => now()->subMinutes(2),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'quiz_contest_id' => $contest, 'user_id' => $studentB->id,
                'answers' => json_encode([$questionIds[0] => 1, $questionIds[1] => 1]),
                'score' => 2, 'submitted_at' => now()->subMinute(),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin)->post("/admin/quiz-contests/{$contest}/publish-results")
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('quiz_contest_winners', [
            'quiz_contest_id' => $contest,
            'user_id' => $studentB->id,
            'rank' => 1,
        ]);
        $this->actingAs($admin)->post("/admin/quiz-contests/{$contest}/publish-results")->assertStatus(409);
    }

    public function test_admin_can_edit_a_future_contest_before_any_entries_exist(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['quiz-contest']);
        $admin = $this->createUserWithRole('admin');
        $contest = $this->createContest(null, now()->addHour()->toDateTimeString(), now()->addDays(2)->toDateTimeString());

        $this->actingAs($admin)->get("/admin/quiz-contests/{$contest}/edit")->assertOk();

        $this->actingAs($admin)->put("/admin/quiz-contests/{$contest}", [
            'title' => 'Updated Grammar Challenge',
            'description' => 'Updated description',
            'prize_description' => 'Updated certificate',
            'winner_count' => 2,
            'starts_at' => now()->addHours(2)->toDateTimeString(),
            'ends_at' => now()->addDays(3)->toDateTimeString(),
            'questions' => [[
                'question' => 'Updated question?',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_option' => 2,
            ]],
        ])->assertRedirect(route('plugins.quiz-contest.admin.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('quiz_contests', ['id' => $contest, 'title' => 'Updated Grammar Challenge', 'winner_count' => 2]);
        $this->assertDatabaseCount('quiz_contest_questions', 1);
        $this->assertDatabaseHas('quiz_contest_questions', ['quiz_contest_id' => $contest, 'question' => 'Updated question?', 'correct_option' => 2]);
    }

    public function test_contest_cannot_be_edited_after_it_starts_or_has_entries(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['quiz-contest']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $contest = $this->createContest();
        $questionIds = DB::table('quiz_contest_questions')->where('quiz_contest_id', $contest)->pluck('id');
        DB::table('quiz_contest_entries')->insert([
            'quiz_contest_id' => $contest,
            'user_id' => $student->id,
            'answers' => json_encode([$questionIds[0] => 1, $questionIds[1] => 1]),
            'score' => 2,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get("/admin/quiz-contests/{$contest}/edit")->assertStatus(409);
        $this->actingAs($admin)->put("/admin/quiz-contests/{$contest}", [
            'title' => 'Unsafe edit',
            'prize_description' => 'Prize',
            'winner_count' => 1,
            'starts_at' => now()->addHour()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
            'questions' => [[
                'question' => 'Question?',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_option' => 0,
            ]],
        ])->assertStatus(409);
        $this->assertDatabaseHas('quiz_contests', ['id' => $contest, 'title' => 'Grammar Challenge']);
    }

    public function test_active_contest_can_be_paused_and_resumed_but_paused_entries_are_rejected(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['quiz-contest']);
        $admin = $this->createUserWithRole('admin');
        $student = $this->createUserWithRole('student');
        $contest = $this->createContest(null, now()->subMinute()->toDateTimeString(), now()->addHour()->toDateTimeString());
        $questionIds = DB::table('quiz_contest_questions')->where('quiz_contest_id', $contest)->orderBy('position')->pluck('id');
        $answers = [$questionIds[0] => 1, $questionIds[1] => 1];

        $this->actingAs($admin)->patch("/admin/quiz-contests/{$contest}/status", ['status' => 'paused'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('quiz_contests', ['id' => $contest, 'status' => 'paused']);
        $this->actingAs($student)->post("/quiz-contests/{$contest}/submit", ['answers' => $answers])->assertStatus(422);
        $this->actingAs($student)->get("/quiz-contests/{$contest}")->assertNotFound();

        $this->actingAs($admin)->patch("/admin/quiz-contests/{$contest}/status", ['status' => 'published'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('quiz_contests', ['id' => $contest, 'status' => 'published']);
        $this->actingAs($student)->post("/quiz-contests/{$contest}/submit", ['answers' => $answers])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_branch_manager_cannot_edit_or_change_status_of_another_branch_contest(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'quiz-contest']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'QUIZ-EDIT-A', 'name' => 'Quiz Edit Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'QUIZ-EDIT-B', 'name' => 'Quiz Edit Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchA);
        $permission = Permission::query()->where('slug', 'quiz.contests.manage')->firstOrFail();
        Role::query()->where('slug', 'branch_manager')->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
        $contest = $this->createContest($branchB, now()->addHour()->toDateTimeString(), now()->addDays(2)->toDateTimeString());

        $this->actingAs($manager)->get("/admin/quiz-contests/{$contest}/edit")->assertNotFound();
        $this->actingAs($manager)->patch("/admin/quiz-contests/{$contest}/status", ['status' => 'paused'])->assertNotFound();
        $this->assertDatabaseHas('quiz_contests', ['id' => $contest, 'status' => 'published']);
    }

}
