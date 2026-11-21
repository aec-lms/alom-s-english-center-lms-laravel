<?php

declare(strict_types=1);

namespace Plugins\Modules\LearningRewards\Services;

use App\Core\Plugins\PluginManager;
use App\Core\Security\AuditLogger;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class LearningRewardsService
{
    private const ACTIVITIES = [
        'lesson_completed',
        'quiz_passed',
        'assignment_accepted',
        'attendance_present',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Award points only from trusted server-side workflow code.
     * No public HTTP endpoint accepts points or an arbitrary activity amount.
     */
    public function awardVerifiedActivity(
        int $userId,
        string $activityKey,
        string $sourceType,
        int $sourceId,
        ?string $eventReference = null,
    ): array {
        if (!in_array($activityKey, self::ACTIVITIES, true) || $sourceId < 1
            || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $sourceType)) {
            throw new InvalidArgumentException('Unsupported learning reward source.');
        }

        $idempotencyKey = hash('sha256', implode('|', [
            'learning-reward-v1', $userId, $activityKey, $sourceType, $sourceId, $eventReference ?? '',
        ]));

        try {
            return DB::transaction(function () use ($userId, $activityKey, $sourceType, $sourceId, $idempotencyKey): array {
                $user = User::query()->lockForUpdate()->findOrFail($userId);
                abort_unless($user->hasRole('student'), 403, 'Only active student accounts can earn learning rewards.');
                if (Schema::hasColumn('users', 'is_active')) {
                    abort_unless((bool) $user->is_active, 403, 'Inactive student accounts cannot earn learning rewards.');
                }

                $existing = DB::table('learning_reward_ledger')->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return ['awarded' => false, 'duplicate' => true, 'points' => (int) $existing->points, 'ledger_id' => (int) $existing->id];
                }

                $timezone = (string) config('app.timezone', 'Asia/Dhaka');
                $today = Carbon::now($timezone)->toDateString();
                $rule = DB::table('learning_reward_rules')->where('activity_key', $activityKey)->lockForUpdate()->first();
                $points = 0;

                if ($rule && $rule->is_enabled && (int) $rule->points > 0) {
                    $dayStart = Carbon::parse($today, $timezone)->startOfDay()->utc();
                    $dayEnd = Carbon::parse($today, $timezone)->endOfDay()->utc();
                    $earnedToday = (int) DB::table('learning_reward_ledger')
                        ->where('user_id', $userId)
                        ->where('activity_key', $activityKey)
                        ->whereBetween('awarded_at', [$dayStart, $dayEnd])
                        ->sum('points');
                    $points = min((int) $rule->points, max(0, (int) $rule->daily_cap - $earnedToday));
                }

                $now = now();
                // Zero-point entries still make a verified activity idempotent and preserve learning streaks
                // when an admin disables XP or the daily point cap has been reached.
                $ledgerId = DB::table('learning_reward_ledger')->insertGetId([
                    'user_id' => $userId,
                    'branch_id' => $this->resolveBranchId($user),
                    'activity_key' => $activityKey,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'points' => $points,
                    'description' => $this->descriptionFor($activityKey),
                    'awarded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->updateStreak($userId, $today, $timezone, $now);
                $this->unlockEligibleBadges($userId, $now);
                if ($points > 0) {
                    $this->audit->log('learning_rewards.points_awarded', 'learning_reward_ledger', (string) $ledgerId, [
                        'activity' => $activityKey, 'points' => $points, 'source_type' => $sourceType, 'source_id' => $sourceId,
                    ], $userId);
                }

                return ['awarded' => $points > 0, 'duplicate' => false, 'points' => $points, 'ledger_id' => $ledgerId];
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')
                || str_contains(strtolower($exception->getMessage()), 'duplicate entry')) {
                $existing = DB::table('learning_reward_ledger')->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return ['awarded' => false, 'duplicate' => true, 'points' => (int) $existing->points, 'ledger_id' => (int) $existing->id];
                }
            }
            throw $exception;
        }
    }

    public function reverseActivityAward(int $userId, string $activityKey, string $sourceType, int $sourceId, int $actorId, string $reason): ?int
    {
        $entry = DB::table('learning_reward_ledger')
            ->where('user_id', $userId)
            ->where('activity_key', $activityKey)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('points', '>', 0)
            ->orderByDesc('id')
            ->first();

        if (!$entry) {
            return null;
        }

        $existingReversal = DB::table('learning_reward_ledger')
            ->where('source_type', 'reward_reversal')
            ->where('source_id', $entry->id)
            ->first();

        return $existingReversal
            ? (int) $existingReversal->id
            : $this->reverseAward((int) $entry->id, $actorId, $reason);
    }

    public function reverseAward(int $ledgerId, int $actorId, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 180) {
            throw new InvalidArgumentException('A clear reversal reason is required.');
        }

        return DB::transaction(function () use ($ledgerId, $actorId, $reason): int {
            $entry = DB::table('learning_reward_ledger')->where('id', $ledgerId)->lockForUpdate()->first();
            abort_unless($entry && (int) $entry->points > 0, 404);
            $user = User::query()->lockForUpdate()->findOrFail((int) $entry->user_id);
            $key = hash('sha256', 'learning-reward-reversal-v1|' . $ledgerId);
            $existing = DB::table('learning_reward_ledger')->where('idempotency_key', $key)->first();
            if ($existing) {
                return (int) $existing->id;
            }

            $now = now();
            $id = DB::table('learning_reward_ledger')->insertGetId([
                'user_id' => $user->id,
                'branch_id' => $entry->branch_id,
                'activity_key' => 'reversal',
                'source_type' => 'reward_reversal',
                'source_id' => $ledgerId,
                'idempotency_key' => $key,
                'points' => -(int) $entry->points,
                'description' => $reason,
                'awarded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->log('learning_rewards.points_reversed', 'learning_reward_ledger', (string) $id, [
                'original_ledger_id' => $ledgerId, 'reason' => $reason,
            ], $actorId);
            return $id;
        }, 3);
    }

    private function updateStreak(int $userId, string $today, string $timezone, mixed $now): void
    {
        $streak = DB::table('learning_reward_streaks')->where('user_id', $userId)->lockForUpdate()->first();
        if (!$streak) {
            DB::table('learning_reward_streaks')->insert([
                'user_id' => $userId, 'current_streak' => 1, 'longest_streak' => 1,
                'last_activity_date' => $today, 'timezone' => $timezone, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return;
        }

        $last = $streak->last_activity_date;
        if ($last === $today) {
            return;
        }

        $yesterday = Carbon::parse($today, $timezone)->subDay()->toDateString();
        $current = $last === $yesterday ? (int) $streak->current_streak + 1 : 1;
        DB::table('learning_reward_streaks')->where('user_id', $userId)->update([
            'current_streak' => $current,
            'longest_streak' => max((int) $streak->longest_streak, $current),
            'last_activity_date' => $today,
            'timezone' => $timezone,
            'updated_at' => $now,
        ]);
    }

    private function unlockEligibleBadges(int $userId, mixed $now): void
    {
        $points = (int) DB::table('learning_reward_ledger')->where('user_id', $userId)->sum('points');
        $streak = (int) DB::table('learning_reward_streaks')->where('user_id', $userId)->value('current_streak');
        $badges = DB::table('learning_reward_badges')->where('is_active', true)->get();
        foreach ($badges as $badge) {
            $eligible = ($badge->points_threshold !== null && $points >= (int) $badge->points_threshold)
                || ($badge->streak_threshold !== null && $streak >= (int) $badge->streak_threshold);
            if (!$eligible) {
                continue;
            }
            DB::table('learning_reward_user_badges')->insertOrIgnore([
                'user_id' => $userId, 'badge_id' => $badge->id, 'awarded_at' => $now,
            ]);
        }
    }

    private function resolveBranchId(User $user): ?int
    {
        $accountBranchId = $user->branch_id !== null ? (int) $user->branch_id : null;
        $profileBranchId = null;

        if (app(PluginManager::class)->isEnabled('student-management')) {
            abort_unless(Schema::hasTable('students') && Schema::hasColumn('students', 'user_id'),
                403, 'An active student profile is required to earn learning rewards.');
            $profileQuery = DB::table('students')->where('user_id', $user->id);
            if (Schema::hasColumn('students', 'deleted_at')) {
                $profileQuery->whereNull('deleted_at');
            }
            // Match Student Management's account-before-profile lock order so a concurrent
            // branch/status reassignment cannot race the branch recorded in the ledger.
            $profile = $profileQuery->lockForUpdate()->first();
            abort_unless($profile, 403, 'An active student profile is required to earn learning rewards.');
            if (Schema::hasColumn('students', 'status')) {
                abort_unless($profile->status === 'active', 403, 'Inactive student profiles cannot earn learning rewards.');
            }
            if (Schema::hasColumn('students', 'branch_id') && $profile->branch_id !== null) {
                $profileBranchId = (int) $profile->branch_id;
            }
        }

        if ($accountBranchId !== null && $profileBranchId !== null) {
            abort_unless($accountBranchId === $profileBranchId, 403, 'Student branch assignments do not match.');
        }

        $branchId = $profileBranchId ?? $accountBranchId;
        if ($branchId !== null) {
            abort_unless(app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches'),
                403, 'Student branch is unavailable or inactive.');
            $branch = DB::table('branches')->where('id', $branchId)->where('is_active', true)->lockForUpdate()->first();
            abort_unless($branch, 403, 'Student branch is unavailable or inactive.');
        }

        return $branchId;
    }

    private function descriptionFor(string $activityKey): string
    {
        return match ($activityKey) {
            'lesson_completed' => 'Lesson completed',
            'quiz_passed' => 'Quiz passed',
            'assignment_accepted' => 'Assignment accepted',
            'attendance_present' => 'Class attended',
            default => 'Learning activity',
        };
    }
}
