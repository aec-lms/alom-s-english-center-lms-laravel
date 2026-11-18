<?php

declare(strict_types=1);

namespace Plugins\Modules\RoleAiAssistant\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AiAssistantUsageBudget
{
    public function reserve(
        int $userId,
        string $role,
        string $provider,
        string $model,
        int $promptTokens,
        int $completionLimit,
        int $dailyTokenBudget,
        int $dailyRequestLimit
    ): ?int {
        $date = now()->toDateString();
        $promptTokens = max(1, $promptTokens);
        $completionLimit = max(1, $completionLimit);
        $dailyTokenBudget = max(0, $dailyTokenBudget);
        $dailyRequestLimit = max(0, $dailyRequestLimit);

        return DB::transaction(function () use (
            $date, $userId, $role, $provider, $model, $promptTokens,
            $completionLimit, $dailyTokenBudget, $dailyRequestLimit
        ): ?int {
            DB::table('ai_assistant_budget_days')->insertOrIgnore([
                'budget_date' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ai_assistant_budget_days')
                ->where('budget_date', $date)
                ->lockForUpdate()
                ->first();

            // If the provider accepted a request but the client timed out, its
            // reservation stays charged until a later administrative reconciliation.
            DB::table('ai_assistant_usage_reservations')
                ->where('budget_date', $date)
                ->where('status', 'pending')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'uncertain', 'updated_at' => now()]);

            $requestCount = (int) DB::table('ai_assistant_usage_reservations')
                ->where('budget_date', $date)
                ->where('user_id', $userId)
                ->whereIn('status', ['pending', 'uncertain', 'completed', 'over_budget'])
                ->count();

            if ($dailyRequestLimit < 1 || $requestCount >= $dailyRequestLimit) {
                return null;
            }

            $usedTokens = (int) DB::table('ai_assistant_usage_reservations')
                ->where('budget_date', $date)
                ->whereIn('status', ['completed', 'over_budget'])
                ->sum('actual_tokens');

            $reservedTokens = (int) DB::table('ai_assistant_usage_reservations')
                ->where('budget_date', $date)
                ->whereIn('status', ['pending', 'uncertain'])
                ->selectRaw('COALESCE(SUM(prompt_tokens + completion_limit), 0) as total')
                ->value('total');

            $remaining = max(0, $dailyTokenBudget - $usedTokens - $reservedTokens);
            $availableCompletion = min($completionLimit, $remaining - $promptTokens);
            if ($availableCompletion < 1) {
                return null;
            }

            return (int) DB::table('ai_assistant_usage_reservations')->insertGetId([
                'budget_date' => $date,
                'user_id' => $userId,
                'role_slug' => $role,
                'provider' => $provider,
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_limit' => $availableCompletion,
                'status' => 'pending',
                'actual_tokens' => null,
                'expires_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function settle(int $reservationId, int $actualTokens): void
    {
        DB::transaction(function () use ($reservationId, $actualTokens): void {
            $reservation = DB::table('ai_assistant_usage_reservations')
                ->where('id', $reservationId)
                ->first();

            if (!$reservation) {
                throw new RuntimeException('The AI usage reservation is missing.');
            }

            DB::table('ai_assistant_budget_days')
                ->where('budget_date', $reservation->budget_date)
                ->lockForUpdate()
                ->first();

            $locked = DB::table('ai_assistant_usage_reservations')
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->first();

            if (!$locked || !in_array($locked->status, ['pending', 'uncertain'], true)) {
                throw new RuntimeException('The AI usage reservation is no longer active.');
            }

            $actualTokens = max(0, $actualTokens);
            $ceiling = (int) $locked->prompt_tokens + (int) $locked->completion_limit;

            DB::table('ai_assistant_usage_reservations')
                ->where('id', $reservationId)
                ->update([
                    'status' => $actualTokens > $ceiling ? 'over_budget' : 'completed',
                    'actual_tokens' => $actualTokens,
                    'updated_at' => now(),
                ]);
        }, 3);
    }

    public function markUncertain(int $reservationId): void
    {
        $reservation = DB::table('ai_assistant_usage_reservations')
            ->where('id', $reservationId)
            ->first();

        if (!$reservation) {
            return;
        }

        DB::transaction(function () use ($reservationId, $reservation): void {
            DB::table('ai_assistant_budget_days')
                ->where('budget_date', $reservation->budget_date)
                ->lockForUpdate()
                ->first();

            DB::table('ai_assistant_usage_reservations')
                ->where('id', $reservationId)
                ->whereIn('status', ['pending', 'uncertain'])
                ->update(['status' => 'uncertain', 'updated_at' => now()]);
        }, 3);
    }

    public function release(int $reservationId): void
    {
        $reservation = DB::table('ai_assistant_usage_reservations')
            ->where('id', $reservationId)
            ->first();

        if (!$reservation) {
            return;
        }

        DB::transaction(function () use ($reservationId, $reservation): void {
            DB::table('ai_assistant_budget_days')
                ->where('budget_date', $reservation->budget_date)
                ->lockForUpdate()
                ->first();

            DB::table('ai_assistant_usage_reservations')
                ->where('id', $reservationId)
                ->where('status', 'pending')
                ->update(['status' => 'released', 'updated_at' => now()]);
        }, 3);
    }
}
