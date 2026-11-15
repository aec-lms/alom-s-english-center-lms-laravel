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
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuditLogger $audit,
    ) {}

    public function isRoleAllowed(User $user): bool
    {
        $raw = (string) $this->plugins->getSetting(
            'role-ai-assistant',
            'allowed_roles',
            'admin,branch_manager,teacher,assistant_teacher,student,accountant,guardian,support_staff'
        );
        $allowed = array_filter(array_map('trim', explode(',', strtolower($raw))));
        if (empty($allowed)) {
            return true;
        }
        foreach ($allowed as $roleSlug) {
            if ($user->hasRole($roleSlug)) {
                return true;
            }
        }
        return false;
    }

    public function queryAssistant(User $user, string $prompt): array
    {
        if (!$this->isRoleAllowed($user)) {
            throw new RuntimeException('Your panel role is not enabled for the AI Assistant.');
        }

        $apiKey = (string) $this->plugins->getSetting('role-ai-assistant', 'api_key', '');
        if ($apiKey === '') {
            $apiKey = (string) env('GEMINI_API_KEY', '');
        }

        $provider = strtolower((string) $this->plugins->getSetting('role-ai-assistant', 'provider', 'gemini'));
        $model = (string) $this->plugins->getSetting('role-ai-assistant', 'model', 'gemini-4-hydrogen');
        $maxTokens = max(64, (int) $this->plugins->getSetting('role-ai-assistant', 'max_tokens', '1024'));
        $roleSlug = (string) ($user->roles()->first()?->slug ?? 'user');

        $systemInstruction = $this->systemPromptForRole($roleSlug);

        if ($apiKey === '') {
            $reply = $this->offlineRoleGuidance($roleSlug, $prompt);
        } else {
            $reply = $this->dispatchProviderRequest($provider, $model, $apiKey, $systemInstruction, $prompt, $maxTokens);
        }

        if (Schema::hasTable('ai_assistant_logs')) {
            AiAssistantLog::query()->create([
                'user_id' => $user->id,
                'role_slug' => $roleSlug,
                'provider' => $apiKey === '' ? 'panel-advisor' : $provider,
                'model' => $apiKey === '' ? 'built-in' : $model,
                'prompt_excerpt' => mb_substr($prompt, 0, 240),
                'tokens_estimated' => (int) ceil((strlen($prompt) + strlen($reply)) / 4),
                'status' => 'completed',
            ]);
        }

        $this->audit->log('ai.assistant_queried', 'ai_assistant', (string) $user->id, [
            'provider' => $apiKey === '' ? 'panel-advisor' : $provider,
            'model' => $model,
            'role' => $roleSlug,
        ], $user->id);

        return ['provider' => $provider, 'model' => $model, 'role' => $roleSlug, 'reply' => $reply];
    }

    private function systemPromptForRole(string $role): string
    {
        return match ($role) {
            'admin' => 'You are the AEC LMS Admin Panel AI Assistant. Help with plugin setup, SEO, Meta Pixel, Google Search Console, Boost campaigns, SMS/Gmail marketing, and institutional governance.',
            'teacher', 'assistant_teacher' => 'You are the AEC LMS Teacher Panel AI Assistant. Help draft lesson outlines, IELTS/grammar explanations, MCQ quiz questions, and rubric-based student feedback.',
            'student' => 'You are the AEC LMS Student Panel AI Tutor. Help learners practice English grammar, IELTS Reading/Writing/Speaking tips, vocabulary, and study planning in English or Bengali.',
            'accountant' => 'You are the AEC LMS Accountant Panel AI Assistant. Help summarize fee reconciliation, refund policies, commission payouts, and ledger audit checklists.',
            'guardian' => 'You are the AEC LMS Guardian Panel AI Assistant. Help parents understand ward attendance alerts, exam progress, and payment schedules.',
            'branch_manager' => 'You are the AEC LMS Branch Manager AI Assistant. Help coordinate branch batches, classroom schedules, student onboarding, and local notices.',
            default => 'You are the AEC LMS Support & Panel AI Assistant. Give concise, helpful guidance tailored to the user panel.',
        };
    }

    private function offlineRoleGuidance(string $role, string $prompt): string
    {
        $label = ucfirst(str_replace('_', ' ', $role));
        return "[{$label} Panel AI Ready] আপনার প্রশ্ন: \"{$prompt}\"।\nলাইভ AI উত্তর পেতে Admin → Plugin Center → Role-Based AI Assistant থেকে Gemini/OpenAI Key অথবা সার্ভার এনভায়রনমেন্টে GEMINI_API_KEY সেট করুন। এই প্যানেলে আপনি {$label} সংক্রান্ত যেকোনো ড্রাফট, প্ল্যান বা বিশ্লেষণ সহায়তা পাবেন।";
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
                throw new RuntimeException('OpenAI upstream error: HTTP ' . $resp->status());
            }
            return (string) data_get($resp->json(), 'choices.0.message.content', '');
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $resp = Http::withHeaders(['x-goog-api-key' => $apiKey])->timeout(20)->post($url, [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ]);
        if (!$resp->successful()) {
            throw new RuntimeException('Gemini upstream error: HTTP ' . $resp->status());
        }

        return (string) data_get($resp->json(), 'candidates.0.content.parts.0.text', '');
    }
}
