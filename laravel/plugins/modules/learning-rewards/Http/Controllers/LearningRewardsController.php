<?php

declare(strict_types=1);

namespace Plugins\Modules\LearningRewards\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $start = match ($period) {
            'week' => now('Asia/Dhaka')->startOfWeek()->utc(),
            'month' => now('Asia/Dhaka')->startOfMonth()->utc(),
            default => null,
        };

        $query = DB::table('learning_reward_ledger as ledger')
            ->join('users', 'users.id', '=', 'ledger.user_id')
            ->leftJoin('learning_reward_profiles as profiles', 'profiles.user_id', '=', 'users.id')
            ->where(function ($q): void {
                $q->whereNull('profiles.leaderboard_opt_out')->orWhere('profiles.leaderboard_opt_out', false);
            })
            ->where(function ($q): void {
                $q->whereNull('profiles.profile_public')->orWhere('profiles.profile_public', true);
            })
            ->when($student->branch_id !== null, fn ($q) => $q->where('ledger.branch_id', (int) $student->branch_id))
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
        ]);

        DB::transaction(function () use ($data, $audit, $actor): void {
            $seen = [];
            foreach ($data['rules'] as $rule) {
                abort_if(isset($seen[$rule['activity_key']]), 422, 'Duplicate reward rule.');
                $seen[$rule['activity_key']] = true;
                DB::table('learning_reward_rules')->where('activity_key', $rule['activity_key'])->update([
                    'points' => $rule['points'],
                    'daily_cap' => $rule['daily_cap'],
                    'is_enabled' => (bool) ($rule['is_enabled'] ?? false),
                    'updated_at' => now(),
                ]);
            }
            $audit->log('learning_rewards.rules_updated', 'learning_reward_rules', 'all', ['activity_keys' => array_keys($seen)], $actor->id);
        });

        return back()->with('status', 'Learning reward rules updated.');
    }
}
