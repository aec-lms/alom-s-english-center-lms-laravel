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

    public function queryAssistant(User $user, string $prompt): array
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
            $environmentKey = $provider === 'openai' ? env('OPENAI_API_KEY', '') : env('GEMINI_API_KEY', '');
            $apiKey = is_string($environmentKey) ? $environmentKey : '';
        }

        $defaultModel = $provider === 'openai' ? 'gpt-4o-mini' : 'gemini-2.5-flash';
        $configuredModel = trim((string) $this->plugins->getSetting('role-ai-assistant', 'model', $defaultModel));
        $model = $configuredModel !== '' ? $configuredModel : $defaultModel;
        $maxTokens = min(8192, max(64, (int) $this->plugins->getSetting('role-ai-assistant', 'max_tokens', '1024')));
        $systemInstruction = $this->systemPromptForRole($roleSlug);

        $reply = $apiKey === ''
            ? $this->offlineRoleGuidance($roleSlug, $prompt)
            : $this->dispatchProviderRequest($provider, $model, $apiKey, $systemInstruction, $prompt, $maxTokens);

        if (Schema::hasTable('ai_assistant_logs')) {
            AiAssistantLog::query()->create([
                'user_id' => $user->id,
                'role_slug' => $roleSlug,
                'provider' => $apiKey === '' ? 'built-in' : $provider,
                'model' => $apiKey === '' ? 'built-in' : $model,
                'prompt_excerpt' => '[content withheld]',
                'tokens_estimated' => (int) ceil((strlen($prompt) + strlen($reply)) / 4),
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

    private function systemPromptForRole(string $role): string
    {
        return match ($role) {
            'admin' => 'You are the AEC LMS Admin Panel AI Assistant. Help with plugin setup, SEO, marketing integrations, campaigns, and institutional governance.',
            'teacher', 'assistant_teacher' => 'You are the AEC LMS Teacher Panel AI Assistant. Help draft lesson outlines, English grammar explanations, MCQ quiz questions, and rubric-based student feedback.',
            'student' => 'You are the AEC LMS Student Panel AI Tutor. Help learners practice English grammar, IELTS skills, vocabulary, and study planning in English or Bengali.',
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

    private function dispatchProviderRequest(string $provider, string $model, string $apiKey, string $systemInstruction, string $prompt, int $maxTokens): string
    {
        if ($provider === 'openai') {
            $resp = Http::withToken($apiKey)->timeout(20)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemInstruction],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            if (!$resp->successful()) {
                throw new RuntimeException('AI service request failed. Please check the Admin integration settings.');
            }
            $reply = (string) data_get($resp->json(), 'choices.0.message.content', '');
            if ($reply === '') {
                throw new RuntimeException('AI service returned an empty response.');
            }
            return $reply;
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $resp = Http::withHeaders(['x-goog-api-key' => $apiKey])->timeout(20)->post($url, [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ]);
        if (!$resp->successful()) {
            throw new RuntimeException('AI service request failed. Please check the Admin integration settings.');
        }
        $reply = (string) data_get($resp->json(), 'candidates.0.content.parts.0.text', '');
        if ($reply === '') {
            throw new RuntimeException('AI service returned an empty response.');
        }
        return $reply;
    }
}
