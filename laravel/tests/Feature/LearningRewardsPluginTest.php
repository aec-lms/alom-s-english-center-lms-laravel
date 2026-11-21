<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\LearningRewards\Services\LearningRewardsService;
use Tests\TestCase;

class LearningRewardsPluginTest extends TestCase
{
    private function bootRewards(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['learning-rewards']);
    }

    public function test_learning_rewards_installs_independently_and_seeds_safe_non_cash_rules(): void
    {
        $this->bootRewards();

        $this->assertDatabaseHas('learning_reward_rules', [
            'activity_key' => 'lesson_completed',
            'points' => 10,
            'daily_cap' => 100,
            'is_enabled' => 1,
        ]);
        $this->assertDatabaseHas('learning_reward_badges', ['slug' => 'first-steps']);
        $this->assertFalse(app(PluginManager::class)->isEnabled('course-management'));
    }

    public function test_verified_learning_awards_are_idempotent_and_daily_capped(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $service = app(LearningRewardsService::class);

        $first = $service->awardVerifiedActivity((int) $student->id, 'lesson_completed', 'course_lesson', 101);
        $retry = $service->awardVerifiedActivity((int) $student->id, 'lesson_completed', 'course_lesson', 101);

        $this->assertTrue($first['awarded']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame(10, $first['points']);
        $this->assertSame(1, DB::table('learning_reward_ledger')->where('user_id', $student->id)->count());

        for ($lessonId = 102; $lessonId <= 112; $lessonId++) {
            $service->awardVerifiedActivity((int) $student->id, 'lesson_completed', 'course_lesson', $lessonId);
        }

        $total = (int) DB::table('learning_reward_ledger')->where('user_id', $student->id)->sum('points');
        $this->assertSame(100, $total);
        $this->assertSame(1, DB::table('learning_reward_user_badges')
            ->join('learning_reward_badges', 'learning_reward_badges.id', '=', 'learning_reward_user_badges.badge_id')
            ->where('learning_reward_user_badges.user_id', $student->id)
            ->where('learning_reward_badges.slug', 'first-steps')
            ->count());
    }

    public function test_learning_streak_increments_once_per_local_calendar_day(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $service = app(LearningRewardsService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-20 20:00:00', 'Asia/Dhaka'));
        try {
            $service->awardVerifiedActivity((int) $student->id, 'lesson_completed', 'course_lesson', 201);
            $service->awardVerifiedActivity((int) $student->id, 'quiz_passed', 'quiz_attempt', 202);
            $this->assertSame(1, (int) DB::table('learning_reward_streaks')->where('user_id', $student->id)->value('current_streak'));

            Carbon::setTestNow(Carbon::parse('2026-11-21 20:00:00', 'Asia/Dhaka'));
            $service->awardVerifiedActivity((int) $student->id, 'lesson_completed', 'course_lesson', 203);
            $streak = DB::table('learning_reward_streaks')->where('user_id', $student->id)->first();
            $this->assertSame(2, (int) $streak->current_streak);
            $this->assertSame(2, (int) $streak->longest_streak);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_student_accounts_cannot_receive_learning_rewards(): void
    {
        $this->bootRewards();
        $teacher = $this->createUserWithRole('teacher');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(LearningRewardsService::class)->awardVerifiedActivity((int) $teacher->id, 'lesson_completed', 'course_lesson', 101);
    }

    public function test_inactive_student_accounts_cannot_receive_learning_rewards(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $student->forceFill(['is_active' => false])->save();

        $this->expectException(\Symfony\\Component\\HttpKernel\\Exception\\HttpException::class);
        app(LearningRewardsService::class)->awardVerifiedActivity((int) $student->id, 'lesson_completed', 'course_lesson', 102);
    }

    public function test_reversal_is_immutable_traceable_and_idempotent(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $service = app(LearningRewardsService::class);

        $award = $service->awardVerifiedActivity((int) $student->id, 'assignment_accepted', 'assignment_submission', 55);
        $firstReversal = $service->reverseActivityAward(
            (int) $student->id,
            'assignment_accepted',
            'assignment_submission',
            55,
            (int) $admin->id,
            'Teacher changed the grade to zero.',
        );
        $secondReversal = $service->reverseActivityAward(
            (int) $student->id,
            'assignment_accepted',
            'assignment_submission',
            55,
            (int) $admin->id,
            'Duplicate correction request.',
        );

        $this->assertNotNull($firstReversal);
        $this->assertSame($firstReversal, $secondReversal);
        $this->assertSame(2, DB::table('learning_reward_ledger')->where('user_id', $student->id)->count());
        $this->assertSame(0, (int) DB::table('learning_reward_ledger')->where('user_id', $student->id)->sum('points'));
        $this->assertDatabaseHas('learning_reward_ledger', [
            'id' => $award['ledger_id'],
            'points' => 25,
        ]);
    }

    public function test_attendance_correction_cannot_be_farmed_by_present_absent_present_toggles(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $service = app(LearningRewardsService::class);

        $first = $service->awardVerifiedActivity((int) $student->id, 'attendance_present', 'attendance_entry', 7001);
        $this->assertSame(5, $first['points']);

        $service->reverseActivityAward(
            (int) $student->id,
            'attendance_present',
            'attendance_entry',
            7001,
            (int) $admin->id,
            'Attendance was corrected to absent.',
        );

        // A later present correction must not mint another award for the same attendance row.
        $second = $service->awardVerifiedActivity((int) $student->id, 'attendance_present', 'attendance_entry', 7001);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(5, $second['points']);
        $this->assertSame(2, DB::table('learning_reward_ledger')->where('user_id', $student->id)->count());
        $this->assertSame(0, (int) DB::table('learning_reward_ledger')->where('user_id', $student->id)->sum('points'));
    }

    public function test_student_dashboard_and_privacy_aware_branch_leaderboard_work(): void
    {
        $this->bootRewards();
        $studentA = $this->createUserWithRole('student');
        $studentB = $this->createUserWithRole('student');
        $inactiveStudent = $this->createUserWithRole('student');
        app(LearningRewardsService::class)->awardVerifiedActivity((int) $studentA->id, 'lesson_completed', 'course_lesson', 1);
        app(LearningRewardsService::class)->awardVerifiedActivity((int) $studentB->id, 'quiz_passed', 'quiz_attempt', 2);
        app(LearningRewardsService::class)->awardVerifiedActivity((int) $inactiveStudent->id, 'lesson_completed', 'course_lesson', 3);
        DB::table('learning_reward_profiles')->insert([
            'user_id' => $inactiveStudent->id,
            'leaderboard_opt_out' => false,
            'profile_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inactiveStudent->forceFill(['is_active' => false])->save();

        $this->actingAs($studentA)->get('/my-learning-rewards')->assertOk()->assertSee('My Learning Rewards');
        // No privacy profile means no public leaderboard visibility until the
        // learner explicitly opts in.
        $this->actingAs($studentA)->get('/learning-leaderboard?period=month')->assertOk()->assertDontSee($studentA->name);

        $this->actingAs($studentA)->patch('/my-learning-rewards/privacy', [
            'leaderboard_opt_out' => false,
            'profile_public' => true,
        ])->assertRedirect();
        $this->actingAs($studentA)->get('/learning-leaderboard?period=month')->assertOk()->assertSee($studentA->name)->assertDontSee($inactiveStudent->name);

        $this->actingAs($studentB)->patch('/my-learning-rewards/privacy', [
            'leaderboard_opt_out' => true,
            'profile_public' => true,
        ])->assertRedirect();

        $this->actingAs($studentA)->get('/learning-leaderboard?period=month')->assertOk()->assertDontSee($studentB->name);
        $this->assertDatabaseHas('learning_reward_profiles', [
            'user_id' => $studentB->id,
            'leaderboard_opt_out' => 1,
            'profile_public' => 1,
        ]);
    }

    public function test_leaderboard_is_scoped_to_the_students_assigned_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'learning-rewards']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'REWARD-A',
            'name' => 'Rewards Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'REWARD-B',
            'name' => 'Rewards Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentA = $this->createUserWithRole('student', $branchA);
        $studentB = $this->createUserWithRole('student', $branchB);
        $unassignedStudent = $this->createUserWithRole('student');
        $service = app(LearningRewardsService::class);
        $service->awardVerifiedActivity((int) $studentA->id, 'lesson_completed', 'course_lesson', 301);
        $service->awardVerifiedActivity((int) $studentB->id, 'lesson_completed', 'course_lesson', 302);
        $service->awardVerifiedActivity((int) $unassignedStudent->id, 'lesson_completed', 'course_lesson', 303);

        $this->actingAs($studentA)->get('/learning-leaderboard?period=all')
            ->assertOk()
            ->assertSee($studentA->name)
            ->assertDontSee($studentB->name)
            ->assertDontSee($unassignedStudent->name);

        $this->actingAs($unassignedStudent)->get('/learning-leaderboard?period=all')
            ->assertOk()
            ->assertSee($unassignedStudent->name)
            ->assertDontSee($studentA->name)
            ->assertDontSee($studentB->name);

        $this->assertDatabaseHas('learning_reward_ledger', [
            'user_id' => $studentA->id,
            'branch_id' => $branchA,
        ]);
        $this->assertDatabaseHas('learning_reward_ledger', [
            'user_id' => $studentB->id,
            'branch_id' => $branchB,
        ]);
    }

    public function test_admin_can_create_and_deactivate_achievement_badges(): void
    {
        $this->bootRewards();
        $admin = $this->createUserWithRole('admin');
        $permission = Permission::query()->where('slug', 'gamification.manage')->firstOrFail();
        Role::query()->where('slug', 'admin')->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($admin)->post('/admin/learning-rewards/badges', [
            'slug' => 'grammar-master',
            'name' => 'Grammar Master',
            'description' => 'Complete grammar learning milestones.',
            'icon' => 'book-check',
            'points_threshold' => 250,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $badgeId = (int) DB::table('learning_reward_badges')->where('slug', 'grammar-master')->value('id');
        $this->assertGreaterThan(0, $badgeId);

        $this->actingAs($admin)->put("/admin/learning-rewards/badges/{$badgeId}", [
            'name' => 'Grammar Champion',
            'description' => 'Reach the grammar learning milestone.',
            'icon' => 'award',
            'points_threshold' => 300,
            'streak_threshold' => null,
            'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('learning_reward_badges', [
            'id' => $badgeId,
            'name' => 'Grammar Champion',
            'points_threshold' => 300,
            'is_active' => 0,
        ]);
    }

    public function test_client_cannot_award_points_and_admin_rule_updates_are_permission_gated(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $this->actingAs($student)->post('/my-learning-rewards/award', [
            'activity_key' => 'lesson_completed',
            'source_id' => 999,
            'points' => 500,
        ])->assertNotFound();

        $this->actingAs($student)->get('/admin/learning-rewards')->assertForbidden();

        $admin = $this->createUserWithRole('admin');
        $permission = Permission::query()->where('slug', 'gamification.manage')->firstOrFail();
        Role::query()->where('slug', 'admin')->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
        $this->actingAs($admin)->get('/admin/learning-rewards')->assertOk();
    }

    public function test_streak_badges_unlock_even_when_xp_is_disabled(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        DB::table('learning_reward_rules')->where('activity_key', 'lesson_completed')->update(['is_enabled' => false]);
        $service = app(LearningRewardsService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Asia/Dhaka'));
        try {
            for ($day = 0; $day < 7; $day++) {
                Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Asia/Dhaka')->addDays($day));
                $result = $service->awardVerifiedActivity(
                    (int) $student->id,
                    'lesson_completed',
                    'course_lesson',
                    950 + $day,
                );
                $this->assertSame(0, $result['points']);
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(7, (int) DB::table('learning_reward_streaks')->where('user_id', $student->id)->value('current_streak'));
        $this->assertDatabaseHas('learning_reward_user_badges', [
            'user_id' => $student->id,
            'badge_id' => DB::table('learning_reward_badges')->where('slug', 'seven-day-streak')->value('id'),
        ]);
    }
}
