<?php

declare(strict_types=1);

namespace Plugins\Modules\LearningRewards\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LearningRewardsController extends Controller
{
    public function dashboard(Request $request): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $points = (int) DB::table('learning_reward_ledger')->where('user_id', $student->id)->sum('points');
        $streak = DB::table('learning_reward_streaks')->where('user_id', $student->id)->first();
        $badges = DB::table('learning_reward_user_badges as owned')
            ->join('learning_reward_badges as badges', 'badges.id', '=', 'owned.badge_id')
            ->where('owned.user_id', $student->id)
            ->orderByDesc('owned.awarded_at')
            ->get(['badges.name', 'badges.description', 'badges.icon', 'owned.awarded_at']);
        $activity = DB::table('learning_reward_ledger')->where('user_id', $student->id)
            ->latest('awarded_at')->limit(15)->get();
        $profile = DB::table('learning_reward_profiles')->where('user_id', $student->id)->first();

        return view('learning-rewards::student-dashboard', compact('points', 'streak', 'badges', 'activity', 'profile'));
    }

    public function leaderboard(Request $request): View
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $period = $request->query('period', 'month');
        abort_unless(in_array($period, ['week', 'month', 'all'], true), 404);
        $timezone = (string) config('app.timezone', 'Asia/Dhaka');
        $branchId = $this->resolveStudentBranch($student);
        $start = match ($period) {
            'week' => now($timezone)->startOfWeek()->utc(),
            'month' => now($timezone)->startOfMonth()->utc(),
            default => null,
        };

        $query = DB::table('learning_reward_ledger as ledger')
            ->join('users', 'users.id', '=', 'ledger.user_id')
            ->leftJoin('learning_reward_profiles as profiles', 'profiles.user_id', '=', 'users.id')
            // Leaderboard visibility is explicit opt-in. Missing privacy rows
            // and users who have not chosen public visibility stay private.
            ->where('profiles.leaderboard_opt_out', false)
            ->where('profiles.profile_public', true)
            ->when($branchId !== null, fn ($q) => $q->where('ledger.branch_id', $branchId), fn ($q) => $q->whereNull('ledger.branch_id'))
            ->when($start !== null, fn ($q) => $q->where('ledger.awarded_at', '>=', $start))
            ->selectRaw('users.id as user_id, users.name as student_name, SUM(ledger.points) as points')
            ->groupBy('users.id', 'users.name')
            ->havingRaw('SUM(ledger.points) > 0')
            ->orderByDesc('points')
            ->orderBy('users.name')
            ->paginate(25)
            ->withQueryString();

        return view('learning-rewards::leaderboard', compact('query', 'period'));
    }

    public function updatePrivacy(Request $request): RedirectResponse
    {
        $student = $request->user();
        abort_unless($student && $student->hasRole('student'), 403);
        $data = $request->validate([
            'leaderboard_opt_out' => ['required', 'boolean'],
            'profile_public' => ['required', 'boolean'],
        ]);
        $profile = DB::table('learning_reward_profiles')->where('user_id', $student->id)->exists();
        $values = [
            'leaderboard_opt_out' => $data['leaderboard_opt_out'],
            'profile_public' => $data['profile_public'],
            'updated_at' => now(),
        ];
        if ($profile) {
            DB::table('learning_reward_profiles')->where('user_id', $student->id)->update($values);
        } else {
            DB::table('learning_reward_profiles')->insert($values + ['user_id' => $student->id, 'created_at' => now()]);
        }

        return back()->with('status', 'Reward profile privacy settings updated.');
    }

    public function adminSettings(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('gamification.manage'), 403);
        $rules = DB::table('learning_reward_rules')->orderBy('activity_key')->get();
        $badges = DB::table('learning_reward_badges')->orderBy('id')->get();
        return view('learning-rewards::admin-settings', compact('rules', 'badges'));
    }

    public function updateRules(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasPermission('gamification.manage'), 403);
        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1', 'max:4'],
            'rules.*.activity_key' => ['required', 'in:lesson_completed,quiz_passed,assignment_accepted,attendance_present'],
            'rules.*.points' => ['required', 'integer', 'min:0', 'max:500'],
            'rules.*.daily_cap' => ['required', 'integer', 'min:0', 'max:5000'],
            'rules.*.is_enabled' => ['nullable', 'boolean'],
            'rules.*.pass_percentage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        DB::transaction(function () use ($data, $audit, $actor): void {
            $seen = [];
            foreach ($data['rules'] as $rule) {
                abort_if(isset($seen[$rule['activity_key']]), 422, 'Duplicate reward rule.');
                $seen[$rule['activity_key']] = true;
                DB::table('learning_reward_rules')->where('activity_key', $rule['activity_key'])->update([
                    'points' => $rule['points'],
                    'daily_cap' => $rule['daily_cap'],
                    'pass_percentage' => $rule['pass_percentage'] ?? null,
                    'is_enabled' => (bool) ($rule['is_enabled'] ?? false),
                    'updated_at' => now(),
                ]);
            }
            $audit->log('learning_rewards.rules_updated', 'learning_reward_rules', 'all', ['activity_keys' => array_keys($seen)], $actor->id);
        });

        return back()->with('status', 'Learning reward rules updated.');
    }

    private function resolveStudentBranch(object $student): ?int
    {
        if (Schema::hasColumn('users', 'is_active')) {
            abort_unless((bool) $student->is_active, 403, 'Inactive student accounts cannot view the leaderboard.');
        }

        $accountBranchId = $student->branch_id !== null ? (int) $student->branch_id : null;
        $profileBranchId = null;
        if (app(PluginManager::class)->isEnabled('student-management')) {
            abort_unless(Schema::hasTable('students') && Schema::hasColumn('students', 'user_id'),
                403, 'An active student profile is required to view the leaderboard.');
            $profileQuery = DB::table('students')->where('user_id', $student->id);
            if (Schema::hasColumn('students', 'deleted_at')) {
                $profileQuery->whereNull('deleted_at');
            }
            $profile = $profileQuery->first();
            abort_unless($profile, 403, 'An active student profile is required to view the leaderboard.');
            if (Schema::hasColumn('students', 'status')) {
                abort_unless($profile->status === 'active', 403, 'Inactive student profiles cannot view the leaderboard.');
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
            abort_unless(app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && DB::table('branches')->where('id', $branchId)->where('is_active', true)->exists(),
                403, 'Student branch is unavailable or inactive.');
        }

        return $branchId;
    }

    public function storeBadge(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasPermission('gamification.manage'), 403);
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:learning_reward_badges,slug'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:240'],
            'icon' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'points_threshold' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'streak_threshold' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        abort_if(empty($data['points_threshold']) && empty($data['streak_threshold']), 422, 'A badge needs a points or streak threshold.');

        $id = DB::table('learning_reward_badges')->insertGetId([
            'slug' => $data['slug'],
            'name' => trim($data['name']),
            'description' => trim($data['description']),
            'icon' => $data['icon'],
            'points_threshold' => $data['points_threshold'] ?? null,
            'streak_threshold' => $data['streak_threshold'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->log('learning_rewards.badge_created', 'learning_reward_badge', (string) $id, ['slug' => $data['slug']], $actor->id);

        return back()->with('status', 'Achievement badge created.');
    }

    public function updateBadge(Request $request, int $badge, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasPermission('gamification.manage'), 403);
        $record = DB::table('learning_reward_badges')->where('id', $badge)->first();
        abort_unless($record, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:240'],
            'icon' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'points_threshold' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'streak_threshold' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['required', 'boolean'],
        ]);
        abort_if(empty($data['points_threshold']) && empty($data['streak_threshold']), 422, 'A badge needs a points or streak threshold.');

        DB::table('learning_reward_badges')->where('id', $badge)->update([
            'name' => trim($data['name']),
            'description' => trim($data['description']),
            'icon' => $data['icon'],
            'points_threshold' => $data['points_threshold'] ?? null,
            'streak_threshold' => $data['streak_threshold'] ?? null,
            'is_active' => (bool) $data['is_active'],
            'updated_at' => now(),
        ]);
        $audit->log('learning_rewards.badge_updated', 'learning_reward_badge', (string) $badge, ['slug' => $record->slug, 'is_active' => (bool) $data['is_active']], $actor->id);

        return back()->with('status', 'Achievement badge updated.');
    }
}
