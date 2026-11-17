<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\RoleAiAssistant\Models\AiAssistantLog;
use Plugins\Modules\RoleAiAssistant\Services\AiAssistantService;
use Tests\TestCase;

class AiAssistantSecurityTest extends TestCase
{
    public function test_empty_role_allowlist_denies_every_role(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);
        $student = $this->createUserWithRole('student');

        app(PluginManager::class)->saveSettings('role-ai-assistant', ['allowed_roles' => '']);

        $this->assertFalse(app(AiAssistantService::class)->isRoleAllowed($student));
    }

    public function test_ask_route_requires_explicit_ai_use_permission(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);
        $student = $this->createUserWithRole('student');

        $this->actingAs($student)
            ->postJson('/ai-assistant/ask', ['prompt' => 'Help me practise English grammar'])
            ->assertForbidden();
    }

    public function test_non_admin_activity_view_only_shows_their_own_ai_logs(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);
        $student = $this->createUserWithRole('student');
        $other = $this->createUserWithRole('student', null, 'other-ai-user@aec.test');
        $permission = DB::table('permissions')->where('slug', 'ai.assistant.use')->firstOrFail();
        $student->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);

        AiAssistantLog::query()->create([
            'user_id' => $student->id,
            'role_slug' => 'student',
            'provider' => 'built-in',
            'model' => 'built-in',
            'prompt_excerpt' => '[content withheld]',
            'tokens_estimated' => 20,
            'status' => 'completed',
        ]);
        AiAssistantLog::query()->create([
            'user_id' => $other->id,
            'role_slug' => 'student',
            'provider' => 'built-in',
            'model' => 'built-in',
            'prompt_excerpt' => 'PRIVATE OTHER USER PROMPT',
            'tokens_estimated' => 30,
            'status' => 'completed',
        ]);

        $this->actingAs($student)
            ->get('/ai-assistant')
            ->assertOk()
            ->assertSee('[content withheld]')
            ->assertDontSee('PRIVATE OTHER USER PROMPT');
    }

    public function test_ai_response_uses_aec_brand_and_never_persists_prompt_content(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);
        $admin = $this->createUserWithRole('admin');
        app(PluginManager::class)->saveSettings('role-ai-assistant', [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-openai-key',
            'allowed_roles' => 'admin',
            'max_tokens' => '256',
        ], ['api_key']);

        Http::fake(function (ClientRequest $request) {
            $this->assertStringContainsString('api.openai.com', $request->url());
            return Http::response([
                'choices' => [['message' => ['content' => 'A concise English learning answer.']]],
            ], 200);
        });

        $result = app(AiAssistantService::class)->queryAssistant($admin, 'PRIVATE PROMPT THAT MUST NOT BE STORED');

        $this->assertSame('AEC LMS AI Assistant', $result['assistant']);
        $this->assertSame('A concise English learning answer.', $result['reply']);
        $this->assertDatabaseHas('ai_assistant_logs', [
            'user_id' => $admin->id,
            'prompt_excerpt' => '[content withheld]',
        ]);
        $this->assertDatabaseMissing('ai_assistant_logs', [
            'user_id' => $admin->id,
            'prompt_excerpt' => 'PRIVATE PROMPT THAT MUST NOT BE STORED',
        ]);
    }
}
