<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Plugins\Modules\BoardQuestionSolver\Models\BoardQuestionSolution;
use Tests\TestCase;

class BoardQuestionSolverPluginTest extends TestCase
{
    private function configureStudent($student, int $moduleDailyLimit = 10): void
    {
        $plugins = app(PluginManager::class);
        $plugins->saveSettings('role-ai-assistant', [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-openai-key',
            'allowed_roles' => 'student',
            'max_tokens' => '256',
            'daily_token_budget' => '50000',
            'daily_request_limit' => '20',
        ], ['api_key']);

        $plugins->saveSettings('board-question-solver', [
            'enabled' => '1',
            'allow_image_questions' => '1',
            'max_question_length' => '4000',
            'max_image_kb' => '5120',
            'daily_request_limit' => (string) $moduleDailyLimit,
            'default_language' => 'bn',
        ]);

        $permission = DB::table('permissions')->where('slug', 'board-solver.use')->firstOrFail();
        $student->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
    }

    private function enablePlugins(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant', 'board-question-solver']);
    }

    public function test_student_can_solve_a_text_question_and_history_is_private(): void
    {
        $this->enablePlugins();
        $student = $this->createUserWithRole('student');
        $other = $this->createUserWithRole('student');
        $this->configureStudent($student);

        BoardQuestionSolution::query()->create([
            'user_id' => $other->id,
            'subject' => 'Private',
            'question_type' => 'short',
            'mode' => 'full',
            'language' => 'en',
            'question' => 'OTHER STUDENT PRIVATE QUESTION',
            'solution' => 'Private answer.',
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'A clear step-by-step English grammar explanation.']]],
                'usage' => ['total_tokens' => 120],
            ], 200),
        ]);

        $this->actingAs($student)->postJson('/student/board-question-solver/solve', [
            'board' => 'Dhaka',
            'exam_year' => 2025,
            'class_level' => 'SSC',
            'subject' => 'English',
            'question_type' => 'grammar',
            'mode' => 'full',
            'language' => 'en',
            'question' => 'Explain the use of the present perfect tense.',
        ])->assertOk()
            ->assertJsonPath('solution', 'A clear step-by-step English grammar explanation.');

        $this->assertDatabaseHas('board_question_solutions', [
            'user_id' => $student->id,
            'subject' => 'English',
            'question' => 'Explain the use of the present perfect tense.',
        ]);
        $this->assertDatabaseHas('ai_assistant_logs', [
            'user_id' => $student->id,
            'prompt_excerpt' => '[content withheld]',
        ]);
        $this->assertDatabaseMissing('ai_assistant_logs', [
            'user_id' => $student->id,
            'prompt_excerpt' => 'Explain the use of the present perfect tense.',
        ]);

        $this->actingAs($student)->get('/student/board-question-solver/history')
            ->assertOk()
            ->assertSee('Explain the use of the present perfect tense.')
            ->assertDontSee('OTHER STUDENT PRIVATE QUESTION');

        Http::assertSentCount(1);
    }

    public function test_question_photo_is_sent_in_memory_and_never_saved_as_a_file(): void
    {
        $this->enablePlugins();
        $student = $this->createUserWithRole('student');
        $this->configureStudent($student);
        Storage::fake('local');

        Http::fake(function (ClientRequest $request) {
            $imageUrl = data_get($request->data(), 'messages.1.content.1.image_url.url');
            $this->assertIsString($imageUrl);
            $this->assertStringStartsWith('data:image/png;base64,', $imageUrl);

            return Http::response([
                'choices' => [['message' => ['content' => 'The visible question asks about grammar.']]],
                'usage' => ['total_tokens' => 180],
            ], 200);
        });

        $this->actingAs($student)->post('/student/board-question-solver/solve', [
            '_token' => csrf_token(),
            'subject' => 'English',
            'question_type' => 'grammar',
            'mode' => 'hints',
            'language' => 'bn',
            'question' => '',
            'image' => UploadedFile::fake()->image('board-question.png', 640, 480),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('solution', 'The visible question asks about grammar.');

        $this->assertDatabaseHas('board_question_solutions', [
            'user_id' => $student->id,
            'question' => '[Image question]',
            'mode' => 'hints',
        ]);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertSentCount(1);
    }

    public function test_student_daily_solver_limit_is_enforced_before_an_extra_provider_request(): void
    {
        $this->enablePlugins();
        $student = $this->createUserWithRole('student');
        $this->configureStudent($student, 1);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'First answer.']]],
                'usage' => ['total_tokens' => 80],
            ], 200),
        ]);

        $payload = [
            'subject' => 'Mathematics',
            'question_type' => 'math',
            'mode' => 'explain',
            'language' => 'bn',
            'question' => 'What is a prime number?',
        ];

        $this->actingAs($student)->postJson('/student/board-question-solver/solve', $payload)->assertOk();
        $this->actingAs($student)->postJson('/student/board-question-solver/solve', $payload)
            ->assertStatus(429)
            ->assertJsonPath('message', 'The daily AI token or request budget has been reached. Please try again tomorrow or contact the administrator.');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('board_question_solutions', 1);
    }

    public function test_disabled_solver_does_not_send_provider_requests(): void
    {
        $this->enablePlugins();
        $student = $this->createUserWithRole('student');
        $this->configureStudent($student);
        app(PluginManager::class)->saveSettings('board-question-solver', ['enabled' => '0']);

        Http::fake();

        $this->actingAs($student)->postJson('/student/board-question-solver/solve', [
            'subject' => 'English',
            'question_type' => 'short',
            'mode' => 'full',
            'language' => 'en',
            'question' => 'A sample question.',
        ])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_solver_system_instruction_restricts_direct_answers_for_active_exams(): void
    {
        $this->enablePlugins();
        $student = $this->createUserWithRole('student');
        $this->configureStudent($student);

        Http::fake(function (ClientRequest $request) {
            $system = (string) data_get($request->data(), 'messages.0.content');
            $this->assertStringContainsString('active, timed or currently running examination', $system);
            $this->assertStringContainsString('untrusted educational data', $system);

            return Http::response([
                'choices' => [['message' => ['content' => 'I can offer a hint and explain the concept.']]],
                'usage' => ['total_tokens' => 100],
            ], 200);
        });

        $this->actingAs($student)->postJson('/student/board-question-solver/solve', [
            'subject' => 'English',
            'question_type' => 'mcq',
            'mode' => 'hints',
            'language' => 'en',
            'question' => 'I am currently in a timed examination. What is the answer?',
        ])->assertOk();

        Http::assertSentCount(1);
    }
    public function test_ready_board_solver_is_discoverable_from_the_student_dashboard(): void
    {
        $this->enablePlugins();
        $student = $this->createUserWithRole('student');
        $this->configureStudent($student);

        $this->actingAs($student)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Board Question Solver')
            ->assertSee(route('student.board-solver.index'));

        app(PluginManager::class)->saveSettings('board-question-solver', ['enabled' => '0']);
        $this->actingAs($student)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertDontSee('Board Question Solver');
    }

}
