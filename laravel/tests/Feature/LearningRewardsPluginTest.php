<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use App\Models\AuditLog;
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

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
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

    public function test_required_audit_failures_roll_back_reward_awards_and_reversals(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $service = app(LearningRewardsService::class);

        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->action === 'learning_rewards.points_awarded') {
                throw new \RuntimeException('forced reward award audit failure');
            }
        });

        try {
            try {
                $service->awardVerifiedActivity((int) $student->id, 'assignment_accepted', 'assignment_submission', 88001);
                $this->fail('Reward award must roll back when its required audit event cannot be written.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('forced reward award audit failure', $exception->getMessage());
            }
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseMissing('learning_reward_ledger', [
            'user_id' => $student->id,
            'source_id' => 88001,
        ]);
        $this->assertDatabaseMissing('learning_reward_streaks', ['user_id' => $student->id]);

        $award = $service->awardVerifiedActivity((int) $student->id, 'assignment_accepted', 'assignment_submission', 88001);
        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->action === 'learning_rewards.points_reversed') {
                throw new \RuntimeException('forced reward reversal audit failure');
            }
        });

        try {
            try {
                $service->reverseAward($award['ledger_id'], (int) $admin->id, 'Correction requires reversal.');
                $this->fail('Reward reversal must roll back when its required audit event cannot be written.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('forced reward reversal audit failure', $exception->getMessage());
            }
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertSame(1, DB::table('learning_reward_ledger')->where('user_id', $student->id)->count());
        $this->assertSame(25, (int) DB::table('learning_reward_ledger')->where('user_id', $student->id)->sum('points'));
    }

    public function test_attendance_present_absent_present_transitions_are_individually_idempotent(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $admin = $this->createUserWithRole('admin');
        $service = app(LearningRewardsService::class);

        // Each persisted attendance audit row identifies one real status transition.
        $first = $service->awardVerifiedActivity((int) $student->id, 'attendance_present', 'attendance_record', 7001, 'transition-1');
        $this->assertSame(5, $first['points']);
        $this->assertTrue($service->awardVerifiedActivity(
            (int) $student->id, 'attendance_present', 'attendance_record', 7001, 'transition-1'
        )['duplicate']);

        $service->reverseActivityAward(
            (int) $student->id,
            'attendance_present',
            'attendance_record',
            7001,
            (int) $admin->id,
            'Attendance was corrected to absent.',
        );

        // A new persisted present transition earns once; retries of that same transition do not.
        $second = $service->awardVerifiedActivity((int) $student->id, 'attendance_present', 'attendance_record', 7001, 'transition-3');
        $this->assertFalse($second['duplicate']);
        $this->assertSame(5, $second['points']);
        $this->assertTrue($service->awardVerifiedActivity(
            (int) $student->id, 'attendance_present', 'attendance_record', 7001, 'transition-3'
        )['duplicate']);

        $this->assertSame(3, DB::table('learning_reward_ledger')->where('user_id', $student->id)->count());
        $this->assertSame(5, (int) DB::table('learning_reward_ledger')->where('user_id', $student->id)->sum('points'));
    }

    public function test_student_dashboard_and_privacy_aware_branch_leaderboard_work(): void
    {
        $this->bootRewards();
        $studentA = $this->createUserWithRole('student');
        $studentB = $this->createUserWithRole('student');
        $inactiveStudent = $this->createUserWithRole('student');
        $studentA->forceFill(['name' => 'Rewards Student A'])->save();
        $studentB->forceFill(['name' => 'Rewards Student B'])->save();
        $inactiveStudent->forceFill(['name' => 'Inactive Rewards Student'])->save();
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
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $studentA->id,
            'action' => 'learning_rewards.privacy_updated',
            'entity_type' => 'learning_reward_profile',
        ]);
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

    public function test_rewards_pages_and_privacy_recheck_persisted_student_role(): void
    {
        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        app(LearningRewardsService::class)->awardVerifiedActivity(
            (int) $student->id,
            'lesson_completed',
            'course_lesson',
            88001,
        );
        $student->load('roles');
        $studentRoleId = DB::table('roles')->where('slug', 'student')->value('id');
        $this->assertNotNull($studentRoleId);

        DB::table('role_user')
            ->where('user_id', $student->id)
            ->where('role_id', $studentRoleId)
            ->delete();

        // A cached Eloquent role relation must never authorize a subsequent request.
        $this->actingAs($student)->get('/my-learning-rewards')->assertForbidden();
        $this->actingAs($student)->get('/learning-leaderboard')->assertForbidden();
        $this->actingAs($student)->patch('/my-learning-rewards/privacy', [
            'leaderboard_opt_out' => false,
            'profile_public' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('learning_reward_profiles', ['user_id' => $student->id]);
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
        $studentA->forceFill(['name' => 'Branch A Rewards Student'])->save();
        $studentB->forceFill(['name' => 'Branch B Rewards Student'])->save();
        $unassignedStudent->forceFill(['name' => 'Unassigned Rewards Student'])->save();
        $service = app(LearningRewardsService::class);
        $service->awardVerifiedActivity((int) $studentA->id, 'lesson_completed', 'course_lesson', 301);
        $service->awardVerifiedActivity((int) $studentB->id, 'lesson_completed', 'course_lesson', 302);
        $service->awardVerifiedActivity((int) $unassignedStudent->id, 'lesson_completed', 'course_lesson', 303);
        foreach ([$studentA, $studentB, $unassignedStudent] as $user) {
            DB::table('learning_reward_profiles')->insert([
                'user_id' => $user->id,
                'leaderboard_opt_out' => false,
                'profile_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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

    public function test_student_management_profiles_must_be_active_for_rewards_and_leaderboard(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'learning-rewards']);
        $branch = DB::table('branches')->insertGetId([
            'code' => 'REWARD-PROFILE',
            'name' => 'Rewards Profile Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $active = $this->createUserWithRole('student', $branch);
        $inactive = $this->createUserWithRole('student', $branch);
        $active->forceFill(['name' => 'Active Profile Rewards Student'])->save();
        $inactive->forceFill(['name' => 'Inactive Profile Rewards Student'])->save();

        foreach ([[$active, 'REWARD-PROFILE-A'], [$inactive, 'REWARD-PROFILE-I']] as [$user, $code]) {
            \Plugins\Modules\StudentManagement\Models\Student::query()->create([
                'user_id' => $user->id,
                'branch_id' => $branch,
                'student_code' => $code,
                'admission_date' => now()->toDateString(),
                'status' => 'active',
            ]);
        }

        $service = app(LearningRewardsService::class);
        $service->awardVerifiedActivity((int) $active->id, 'lesson_completed', 'course_lesson', 801);
        $service->awardVerifiedActivity((int) $inactive->id, 'lesson_completed', 'course_lesson', 802);

        foreach ([$active, $inactive] as $user) {
            DB::table('learning_reward_profiles')->insert([
                'user_id' => $user->id,
                'leaderboard_opt_out' => false,
                'profile_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('students')->where('user_id', $inactive->id)->update(['status' => 'inactive']);

        $this->actingAs($active)->get('/learning-leaderboard?period=all')
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee($inactive->name);

        try {
            $service->awardVerifiedActivity((int) $inactive->id, 'lesson_completed', 'course_lesson', 803);
            $this->fail('Inactive student profiles must not earn learning rewards.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_reward_awards_fail_closed_when_student_account_and_profile_branches_conflict(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'learning-rewards']);
        $branchA = DB::table('branches')->insertGetId([
            'code' => 'REWARD-CONFLICT-A', 'name' => 'Reward Conflict A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'REWARD-CONFLICT-B', 'name' => 'Reward Conflict B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', $branchA);
        \Plugins\Modules\StudentManagement\Models\Student::query()->create([
            'user_id' => $student->id,
            'branch_id' => $branchB,
            'student_code' => 'REWARD-CONFLICT-STUDENT',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        try {
            app(LearningRewardsService::class)->awardVerifiedActivity((int) $student->id, 'lesson_completed', 'course_lesson', 901);
            $this->fail('Conflicting account/profile branch assignments must not earn rewards.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('learning_reward_ledger', [
            'user_id' => $student->id,
            'source_id' => 901,
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

    public function test_mysql_concurrent_reward_awards_respect_daily_caps_and_idempotency(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Concurrent reward worker coverage runs only in the MySQL 8 CI job.');
        }
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            $this->markTestSkipped('PHP process control is required for the MySQL reward concurrency regression.');
        }

        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        DB::table('learning_reward_rules')->where('activity_key', 'lesson_completed')->update([
            'points' => 5,
            'daily_cap' => 10,
            'is_enabled' => true,
        ]);

        $duplicateResults = $this->runConcurrentRewardWorkers((int) $student->id, [2001, 2001]);
        $this->assertCount(2, $duplicateResults);
        $this->assertCount(1, array_filter($duplicateResults, static fn (array $result): bool => ($result['duplicate'] ?? false) === true));
        $this->assertSame(1, DB::table('learning_reward_ledger')
            ->where('user_id', $student->id)->where('source_id', 2001)->count());

        $capResults = $this->runConcurrentRewardWorkers((int) $student->id, [2002, 2003]);
        $this->assertCount(2, $capResults);
        $this->assertSame(10, (int) DB::table('learning_reward_ledger')
            ->where('user_id', $student->id)
            ->where('activity_key', 'lesson_completed')
            ->sum('points'));
        $this->assertSame(3, DB::table('learning_reward_ledger')
            ->where('user_id', $student->id)
            ->where('activity_key', 'lesson_completed')->count());
    }

    public function test_mysql_concurrent_streak_threshold_awards_only_one_seven_day_badge(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent streak/badge coverage runs only in the MySQL 8 CI job.');
        }
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            $this->markTestSkipped('PHP process control is required for the MySQL reward concurrency regression.');
        }

        $this->bootRewards();
        $student = $this->createUserWithRole('student');
        $timezone = 'Asia/Dhaka';
        $yesterday = Carbon::now($timezone)->subDay()->toDateString();
        DB::table('learning_reward_rules')->where('activity_key', 'lesson_completed')->update([
            'points' => 0,
            'daily_cap' => 0,
            'is_enabled' => false,
        ]);
        DB::table('learning_reward_streaks')->insert([
            'user_id' => $student->id,
            'current_streak' => 6,
            'longest_streak' => 6,
            'last_activity_date' => $yesterday,
            'timezone' => $timezone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $results = $this->runConcurrentRewardWorkers((int) $student->id, [99011, 99012]);
        $this->assertCount(2, $results);
        $this->assertSame(2, DB::table('learning_reward_ledger')
            ->where('user_id', $student->id)
            ->whereIn('source_id', [99011, 99012])
            ->where('points', 0)
            ->count());

        $streak = DB::table('learning_reward_streaks')->where('user_id', $student->id)->first();
        $this->assertNotNull($streak);
        $this->assertSame(7, (int) $streak->current_streak);
        $badgeId = DB::table('learning_reward_badges')->where('slug', 'seven-day-streak')->value('id');
        $this->assertNotNull($badgeId);
        $this->assertSame(1, DB::table('learning_reward_user_badges')
            ->where('user_id', $student->id)
            ->where('badge_id', $badgeId)
            ->count());
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
    /**
     * Start two independent Laravel PHP workers behind a shared gate so the
     * service must serialize real concurrent transactions against MySQL.
     */
    private function runConcurrentRewardWorkers(int $userId, array $sourceIds): array
    {
        $root = sys_get_temp_dir() . '/aec-learning-rewards-race-' . bin2hex(random_bytes(8));
        $gatePath = $root . '/start.gate';
        $workerPath = base_path('tests/Support/learning-rewards-award-worker.php');
        mkdir($root, 0700, true);

        $connection = config('database.connections.mysql');
        $baseEnvironment = [
            'PATH' => (string) (getenv('PATH') ?: ''),
            'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
        ];
        $processes = [];
        $pipesByWorker = [];

        try {
            $this->assertFileExists($workerPath, 'The MySQL reward worker script must exist.');
            foreach ($sourceIds as $index => $sourceId) {
                $workerNumber = $index + 1;
                $environment = array_merge($baseEnvironment, [
                    'APP_ENV' => 'testing',
                    'APP_DEBUG' => 'false',
                    'APP_URL' => 'https://aec-rewards-concurrency.test',
                    'APP_KEY' => (string) config('app.key'),
                    'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'),
                    'DB_PORT' => (string) ($connection['port'] ?? '3306'),
                    'DB_DATABASE' => (string) ($connection['database'] ?? ''),
                    'DB_USERNAME' => (string) ($connection['username'] ?? ''),
                    'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
                    'CACHE_STORE' => 'file',
                    'CACHE_DRIVER' => 'file',
                    'SESSION_DRIVER' => 'file',
                    'QUEUE_CONNECTION' => 'sync',
                    'AEC_REWARD_USER_ID' => (string) $userId,
                    'AEC_REWARD_ACTIVITY' => 'lesson_completed',
                    'AEC_REWARD_SOURCE_ID' => (string) $sourceId,
                    'AEC_REWARD_READY_FILE' => $root . '/worker-' . $workerNumber . '.ready',
                    'AEC_REWARD_START_GATE' => $gatePath,
                ]);
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, $workerPath],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    base_path(),
                    $environment
                );
                $this->assertIsResource($process, 'Unable to start a real MySQL learning-rewards worker.');
                fclose($pipes[0]);
                $processes[] = $process;
                $pipesByWorker[] = $pipes;
            }

            $readyDeadline = microtime(true) + 20;
            while (microtime(true) < $readyDeadline) {
                if (is_file($root . '/worker-1.ready') && is_file($root . '/worker-2.ready')) {
                    break;
                }
                usleep(10000);
            }
            $this->assertFileExists($root . '/worker-1.ready', 'First MySQL reward worker did not reach the start barrier.');
            $this->assertFileExists($root . '/worker-2.ready', 'Second MySQL reward worker did not reach the start barrier.');
            file_put_contents($gatePath, 'go', LOCK_EX);

            $outcomes = [];
            foreach ($processes as $index => $process) {
                $stdout = stream_get_contents($pipesByWorker[$index][1]);
                $stderr = stream_get_contents($pipesByWorker[$index][2]);
                fclose($pipesByWorker[$index][1]);
                fclose($pipesByWorker[$index][2]);
                $exitCode = proc_close($process);
                $decoded = json_decode(trim((string) $stdout), true);
                $this->assertSame(0, $exitCode, 'MySQL reward worker exited abnormally: ' . (string) $stderr);
                $this->assertIsArray($decoded, 'MySQL reward worker did not return JSON. stdout=' . (string) $stdout . ' stderr=' . (string) $stderr);
                $this->assertTrue($decoded['success'] ?? false, 'MySQL reward worker failed: ' . (string) ($decoded['message'] ?? 'unknown error'));
                $outcomes[] = $decoded['result'];
            }

            return $outcomes;
        } finally {
            foreach ($processes as $index => $process) {
                if (is_resource($process)) {
                    @proc_terminate($process);
                    if (isset($pipesByWorker[$index])) {
                        foreach ([1, 2] as $pipeIndex) {
                            if (isset($pipesByWorker[$index][$pipeIndex]) && is_resource($pipesByWorker[$index][$pipeIndex])) {
                                @fclose($pipesByWorker[$index][$pipeIndex]);
                            }
                        }
                    }
                    @proc_close($process);
                }
            }
            foreach (glob($root . '/*') ?: [] as $entry) {
                if (is_file($entry) || is_link($entry)) {
                    @unlink($entry);
                }
            }
            @rmdir($root);
        }
    }


    public function test_mysql_branch_reassignment_waits_for_reward_award_and_ledger_uses_committed_branch(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Branch reassignment/reward serialization requires MySQL 8.');
        }

        $this->markSystemInstalled();
        $this->activatePlugins(['learning-rewards', 'student-management', 'branch-management']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'REWARD-BRANCH-A-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Reward Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'REWARD-BRANCH-B-' . strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Reward Branch B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = $this->createUserWithRole('student', (int) $branchA);
        DB::table('students')->insert([
            'user_id' => $student->id,
            'branch_id' => $branchA,
            'student_code' => 'REWARD-BRANCH-' . strtoupper(bin2hex(random_bytes(3))),
            'admission_date' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $primaryName = DB::getDefaultConnection();
        $primary = DB::connection($primaryName);
        $secondaryName = 'learning_reward_branch_' . bin2hex(random_bytes(3));
        config(['database.connections.' . $secondaryName => config('database.connections.' . $primaryName)]);
        DB::purge($secondaryName);
        $secondary = DB::connection($secondaryName);
        $secondary->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            // Mirror Student Management's canonical account -> role -> profile order.
            $primary->table('users')->where('id', $student->id)->lockForUpdate()->first();
            $primary->table('role_user')->where('user_id', $student->id)->orderBy('role_id')->lockForUpdate()->get();
            $primary->table('students')->where('user_id', $student->id)->lockForUpdate()->first();
            $primary->table('users')->where('id', $student->id)->update(['branch_id' => $branchB, 'updated_at' => now()]);
            $primary->table('students')->where('user_id', $student->id)->update(['branch_id' => $branchB, 'updated_at' => now()]);

            DB::setDefaultConnection($secondaryName);
            try {
                app(LearningRewardsService::class)->awardVerifiedActivity(
                    (int) $student->id,
                    'lesson_completed',
                    'course_lesson',
                    99001,
                );
                $this->fail('A reward award must wait while the Student Management branch reassignment owns the account/profile locks.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            }

            DB::setDefaultConnection($primaryName);
            $primary->commit();

            DB::setDefaultConnection($secondaryName);
            $result = app(LearningRewardsService::class)->awardVerifiedActivity(
                (int) $student->id,
                'lesson_completed',
                'course_lesson',
                99001,
            );
            $this->assertFalse($result['duplicate']);
            $this->assertDatabaseHas('learning_reward_ledger', [
                'id' => $result['ledger_id'],
                'user_id' => $student->id,
                'branch_id' => $branchB,
                'source_id' => 99001,
            ]);
        } catch (\Throwable $exception) {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            throw $exception;
        } finally {
            DB::setDefaultConnection($primaryName);
            DB::purge($secondaryName);
        }
    }


}
