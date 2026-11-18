<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Plugins\Modules\RoleAiAssistant\Services\AiAssistantUsageBudget;
use Tests\TestCase;

class AiAssistantUsageBudgetTest extends TestCase
{
    public function test_daily_token_reservations_prevent_concurrent_requests_from_exceeding_the_budget(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);
        $user = $this->createUserWithRole('admin');
        $budget = app(AiAssistantUsageBudget::class);

        $first = $budget->reserve($user->id, 'admin', 'openai', 'gpt-4o-mini', 50, 100, 200, 10);
        $this->assertNotNull($first);

        $second = $budget->reserve($user->id, 'admin', 'openai', 'gpt-4o-mini', 50, 100, 200, 10);
        $this->assertNull($second, 'A second request must not reserve tokens already held by the first request.');

        $budget->settle($first, 120);
        $third = $budget->reserve($user->id, 'admin', 'openai', 'gpt-4o-mini', 20, 40, 200, 10);

        $this->assertNotNull($third);
        $this->assertDatabaseHas('ai_assistant_usage_reservations', [
            'id' => $first,
            'status' => 'completed',
            'actual_tokens' => 120,
        ]);
    }

    public function test_provider_request_is_rejected_before_network_call_when_daily_budget_is_insufficient(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);
        $admin = $this->createUserWithRole('admin');
        app(\App\Core\Plugins\PluginManager::class)->saveSettings('role-ai-assistant', [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-openai-key',
            'allowed_roles' => 'admin',
            'max_tokens' => '128',
            'daily_token_budget' => '64',
            'daily_request_limit' => '10',
        ], ['api_key']);

        Http::fake();

        $this->actingAs($admin)
            ->postJson('/ai-assistant/ask', ['prompt' => str_repeat('A', 120)])
            ->assertStatus(429)
            ->assertJsonPath('error', 'The daily AI token or request budget has been reached. Please try again tomorrow or contact the administrator.');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistant_usage_reservations', 0);
    }

    public function test_daily_request_limit_is_enforced_per_user(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['role-ai-assistant']);
        $user = $this->createUserWithRole('admin');
        $budget = app(AiAssistantUsageBudget::class);

        $first = $budget->reserve($user->id, 'admin', 'openai', 'gpt-4o-mini', 10, 10, 1000, 1);
        $this->assertNotNull($first);
        $budget->settle($first, 15);

        $second = $budget->reserve($user->id, 'admin', 'openai', 'gpt-4o-mini', 10, 10, 1000, 1);
        $this->assertNull($second);
    }
}
