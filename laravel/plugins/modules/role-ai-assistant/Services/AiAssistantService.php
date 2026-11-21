<?php

declare(strict_types=1);

namespace Plugins\Modules\RoleAiAssistant\Services;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Plugins\Modules\RoleAiAssistant\Models\AiAssistantLog;
use RuntimeException;

class AiAssistantService
{
    private const SUPPORTED_PROVIDERS = ['openai', 'gemini'];

    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuditLogger $audit,
        private readonly AiAssistantUsageBudget $usageBudget,
    ) {}

    public function isRoleAllowed(User $user): bool
    {
        return $this->allowedRoleSlug($user) !== null;
    }

    private function allowedRoleSlug(User $user): ?string
    {
        $raw = (string) $this->plugins->getSetting(
            'role-ai-assistant',
            'allowed_roles',
            'admin,branch_manager,teacher,assistant_teacher,student,accountant,guardian,support_staff'
        );
        $allowed = array_values(array_filter(array_map('trim', explode(',', strtolower($raw)))));
        if ($allowed === []) {
            return null;
        }

        foreach ($user->roles()->pluck('slug') as $roleSlug) {
            $roleSlug = strtolower((string) $roleSlug);
            if (in_array($roleSlug, $allowed, true)) {
                return $roleSlug;
            }
        }

        return null;
    }

    public function isProviderConfigured(): bool
    {
        $provider = strtolower((string) $this->plugins->getSetting('role-ai-assistant', 'provider', 'gemini'));
        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return false;
        }

        $apiKey = (string) $this->plugins->getSetting('role-ai-assistant', 'api_key', '');
        if ($apiKey === '') {
            $environmentKey = $provider === 'openai' ? config('services.openai.api_key', '') : config('services.gemini.api_key', '');
            $apiKey = is_string($environmentKey) ? $environmentKey : '';
        }

        return $apiKey !== '';
    }

    public function queryAssistant(
        User $user,
        string $prompt,
        ?string $specializedInstruction = null,
        ?string $imageData = null,
        ?int $dailyRequestLimitOverride = null
    ): array
    {
        $roleSlug = $this->allowedRoleSlug($user);
        if ($roleSlug === null) {
            throw new RuntimeException('Your panel role is not enabled for the AEC LMS AI Assistant.');
        }

        $provider = strtolower((string) $this->plugins->getSetting('role-ai-assistant', 'provider', 'gemini'));
        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            throw new RuntimeException('The selected AI integration is not supported. Choose a supported integration in Admin Settings.');
        }

        $apiKey = (string) $this->plugins->getSetting('role-ai-assistant', 'api_key', '');
        if ($apiKey === '') {
            $environmentKey = $provider === 'openai' ? config('services.openai.api_key', '') : config('services.gemini.api_key', '');
            $apiKey = is_string($environmentKey) ? $environmentKey : '';
        }

        $defaultModel = $provider === 'openai' ? 'gpt-4o-mini' : 'gemini-2.5-flash';
        $configuredModel = trim((string) $this->plugins->getSetting('role-ai-assistant', 'model', $defaultModel));
        $model = $configuredModel !== '' ? $configuredModel : $defaultModel;
        $maxTokens = min(8192, max(64, (int) $this->plugins->getSetting('role-ai-assistant', 'max_tokens', '1024')));
        $systemInstruction = $this->systemPromptForRole($roleSlug);
        if (is_string($specializedInstruction) && trim($specializedInstruction) !== '') {
            $systemInstruction .= "\n\n" . trim($specializedInstruction);
        }

        $reservationId = null;
        $actualTokens = 0;

        if ($apiKey === '') {
            $reply = $this->offlineRoleGuidance($roleSlug, $prompt);
            $actualTokens = strlen($systemInstruction) + strlen($prompt) + strlen($reply);
        } else {
            $estimatedPromptTokens = max(1, strlen($systemInstruction) + strlen($prompt) + ($imageData !== null ? 1024 : 16));
            $dailyTokenBudget = max(0, (int) $this->plugins->getSetting('role-ai-assistant', 'daily_token_budget', '50000'));
            $dailyRequestLimit = max(0, (int) $this->plugins->getSetting('role-ai-assistant', 'daily_request_limit', '20'));
            if ($dailyRequestLimitOverride !== null) {
                $dailyRequestLimit = min($dailyRequestLimit, max(0, $dailyRequestLimitOverride));
            }
            $reservation = $this->usageBudget->reserve(
                (int) $user->id,
                $roleSlug,
                $provider,
                $model,
                $estimatedPromptTokens,
                $maxTokens,
                $dailyTokenBudget,
                $dailyRequestLimit
            );

            if ($reservation === null) {
                throw new AiAssistantUsageLimitException('The daily AI token or request budget has been reached. Please try again tomorrow or contact the administrator.');
            }

            $reservationId = $reservation;
            $completionLimit = min($maxTokens, max(1, (int) $this->reservationCompletionLimit($reservation)));
            try {
                $providerResult = $this->dispatchProviderRequest($provider, $model, $apiKey, $systemInstruction, $prompt, $completionLimit, $imageData);
                $reply = $providerResult['reply'];
                $actualTokens = $providerResult['tokens'] ?? (strlen($systemInstruction) + strlen($prompt) + strlen($reply));
                $this->usageBudget->settle($reservationId, $actualTokens);
            } catch (\Throwable $exception) {
                $this->usageBudget->markUncertain($reservationId);
                throw $exception;
            }
        }

        if (Schema::hasTable('ai_assistant_logs')) {
            AiAssistantLog::query()->create([
                'user_id' => $user->id,
                'role_slug' => $roleSlug,
                'provider' => $apiKey === '' ? 'built-in' : $provider,
                'model' => $apiKey === '' ? 'built-in' : $model,
                'prompt_excerpt' => '[content withheld]',
                'tokens_estimated' => max(0, $actualTokens),
                'status' => 'completed',
            ]);
        }

        $this->audit->log('ai.assistant_queried', 'ai_assistant', (string) $user->id, [
            'provider' => $apiKey === '' ? 'built-in' : $provider,
            'model' => $model,
            'role' => $roleSlug,
        ], $user->id);

        return ['assistant' => 'AEC LMS AI Assistant', 'role' => $roleSlug, 'reply' => $reply];
    }

    private function reservationCompletionLimit(int $reservationId): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('ai_assistant_usage_reservations')
            ->where('id', $reservationId)
            ->value('completion_limit');
    }

    private function systemPromptForRole(string $role): string
    {
        return match ($role) {
            'admin' => 'You are the AEC LMS Admin Panel AI Assistant. Help with plugin setup, SEO, marketing integrations, campaigns, and institutional governance.',
            'teacher', 'assistant_teacher' => 'You are the AEC LMS Teacher Panel AI Assistant. Help draft lesson outlines, English grammar explanations, MCQ quiz questions, and rubric-based student feedback.',
            'student' => 'You are the AEC LMS Student Panel AI Teacher. Teach patiently in clear Bangla or English, matching the language used by the learner. Explain concepts step by step with simple examples, check understanding with short questions, offer practice and quizzes when useful, gently correct mistakes, and encourage the learner. For English, grammar, vocabulary, IELTS, and study skills, give accurate age-appropriate guidance. Never claim to know the learner’s course progress, grades, or personal records unless that information is explicitly provided in the current conversation.',
            'accountant' => 'You are the AEC LMS Accountant Panel AI Assistant. Help summarize fee reconciliation, refund policies, commission payouts, and ledger audit checklists.',
            'guardian' => 'You are the AEC LMS Guardian Panel AI Assistant. Help parents understand ward attendance alerts, exam progress, and payment schedules.',
            'branch_manager' => 'You are the AEC LMS Branch Manager AI Assistant. Help coordinate branch batches, classroom schedules, student onboarding, and local notices.',
            default => 'You are the AEC LMS Support & Panel AI Assistant. Give concise, helpful guidance tailored to the user panel.',
        };
    }

    private function offlineRoleGuidance(string $role, string $prompt): string
    {
        $label = ucfirst(str_replace('_', ' ', $role));
        return "আপনার {$label} প্যানেলের AEC LMS AI Assistant বর্তমানে অফলাইন মোডে আছে। Admin Settings থেকে AI integration configure করলে পূর্ণ AI সহায়তা চালু হবে। আপনার প্রশ্নটি আবার চেষ্টা করুন অথবা Admin-এর সঙ্গে যোগাযোগ করুন।";
    }

    private function dispatchProviderRequest(string $provider, string $model, string $apiKey, string $systemInstruction, string $prompt, int $maxTokens, ?string $imageData = null): array
    {
        if ($provider === 'openai') {
            $resp = Http::withToken($apiKey)->timeout($imageData === null ? 20 : 60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemInstruction],
                    ['role' => 'user', 'content' => $imageData === null ? $prompt : [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageData, 'detail' => 'high']],
                    ]],
                ],
            ]);
            if (!$resp->successful()) {
                throw new RuntimeException('AI service request failed. Please check the Admin integration settings.');
            }
            $payload = $resp->json();
            $reply = (string) data_get($payload, 'choices.0.message.content', '');
            if ($reply === '') {
                throw new RuntimeException('AI service returned an empty response.');
            }
            $usage = data_get($payload, 'usage.total_tokens');
            return ['reply' => $reply, 'tokens' => is_numeric($usage) && (int) $usage > 0 ? (int) $usage : null];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $parts = [['text' => $prompt]];
        if ($imageData !== null) {
            if (preg_match('/^data:(image\\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\\/]+=*)$/D', $imageData, $matches) !== 1) {
                throw new RuntimeException('The attached question image could not be decoded safely.');
            }
            $parts[] = ['inline_data' => ['mime_type' => $matches[1], 'data' => $matches[2]]];
        }
        $resp = Http::withHeaders(['x-goog-api-key' => $apiKey])->timeout($imageData === null ? 20 : 60)->post($url, [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ]);
        if (!$resp->successful()) {
            throw new RuntimeException('AI service request failed. Please check the Admin integration settings.');
        }
        $payload = $resp->json();
        $reply = (string) data_get($payload, 'candidates.0.content.parts.0.text', '');
        if ($reply === '') {
            throw new RuntimeException('AI service returned an empty response.');
        }
        $usage = data_get($payload, 'usageMetadata.totalTokenCount');
        return ['reply' => $reply, 'tokens' => is_numeric($usage) && (int) $usage > 0 ? (int) $usage : null];
    }
}
