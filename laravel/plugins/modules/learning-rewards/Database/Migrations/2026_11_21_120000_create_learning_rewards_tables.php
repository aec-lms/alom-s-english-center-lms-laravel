<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('learning_reward_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('activity_key', 48)->unique();
            $table->unsignedSmallInteger('points')->default(0);
            $table->unsignedInteger('daily_cap')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('learning_reward_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('activity_key', 48);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->char('idempotency_key', 64)->unique();
            $table->integer('points');
            $table->string('description', 180);
            $table->timestamp('awarded_at');
            $table->timestamps();
            $table->index(['user_id', 'awarded_at'], 'learning_reward_user_date_idx');
            $table->index(['branch_id', 'awarded_at'], 'learning_reward_branch_date_idx');
            $table->index(['activity_key', 'awarded_at'], 'learning_reward_activity_date_idx');
        });

        Schema::create('learning_reward_streaks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_activity_date')->nullable();
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->timestamps();
        });

        Schema::create('learning_reward_badges', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 100);
            $table->string('description', 240);
            $table->string('icon', 64)->default('award');
            $table->unsignedInteger('points_threshold')->nullable();
            $table->unsignedInteger('streak_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('learning_reward_user_badges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('learning_reward_badges')->cascadeOnDelete();
            $table->timestamp('awarded_at');
            $table->unique(['user_id', 'badge_id']);
            $table->index(['user_id', 'awarded_at'], 'learning_reward_badge_user_date_idx');
        });

        Schema::create('learning_reward_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('leaderboard_opt_out')->default(false);
            $table->boolean('profile_public')->default(true);
            $table->timestamps();
        });

        DB::table('learning_reward_rules')->insert([
            ['activity_key' => 'lesson_completed', 'points' => 10, 'daily_cap' => 100, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['activity_key' => 'quiz_passed', 'points' => 20, 'daily_cap' => 100, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['activity_key' => 'assignment_accepted', 'points' => 25, 'daily_cap' => 100, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['activity_key' => 'attendance_present', 'points' => 5, 'daily_cap' => 20, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('learning_reward_badges')->insert([
            ['slug' => 'first-steps', 'name' => 'First Steps', 'description' => 'Earn your first 10 learning points.', 'icon' => 'footprints', 'points_threshold' => 10, 'streak_threshold' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'dedicated-learner', 'name' => 'Dedicated Learner', 'description' => 'Earn 100 learning points.', 'icon' => 'book-open', 'points_threshold' => 100, 'streak_threshold' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'seven-day-streak', 'name' => '7-Day Streak', 'description' => 'Learn on seven consecutive days.', 'icon' => 'flame', 'points_threshold' => null, 'streak_threshold' => 7, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'consistent-learner', 'name' => 'Consistent Learner', 'description' => 'Earn 500 learning points.', 'icon' => 'trophy', 'points_threshold' => 500, 'streak_threshold' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_reward_profiles');
        Schema::dropIfExists('learning_reward_user_badges');
        Schema::dropIfExists('learning_reward_badges');
        Schema::dropIfExists('learning_reward_streaks');
        Schema::dropIfExists('learning_reward_ledger');
        Schema::dropIfExists('learning_reward_rules');
    }
};
