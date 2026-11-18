<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_lesson_comments')) {
            Schema::create('course_lesson_comments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('course_id');
                $table->unsignedBigInteger('lesson_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->text('body');
                $table->string('status', 20)->default('visible');
                $table->boolean('is_pinned')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['lesson_id', 'status', 'is_pinned', 'created_at'], 'lesson_comments_listing_idx');
                $table->index(['course_id', 'lesson_id'], 'lesson_comments_course_lesson_idx');
                $table->index(['user_id', 'created_at'], 'lesson_comments_user_idx');
                $table->foreign('parent_id')->references('id')->on('course_lesson_comments')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('live_class_discussions')) {
            Schema::create('live_class_discussions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('live_class_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->text('body');
                $table->string('status', 20)->default('visible');
                $table->boolean('is_pinned')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['live_class_id', 'status', 'is_pinned', 'created_at'], 'live_discussions_listing_idx');
                $table->index(['user_id', 'created_at'], 'live_discussions_user_idx');
                $table->foreign('parent_id')->references('id')->on('live_class_discussions')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('live_class_discussion_votes')) {
            Schema::create('live_class_discussion_votes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('discussion_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('created_at')->nullable();
                $table->unique(['discussion_id', 'user_id'], 'live_discussion_vote_unique');
                $table->index('user_id', 'live_discussion_vote_user_idx');
                $table->foreign('discussion_id')->references('id')->on('live_class_discussions')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_class_discussion_votes');
        Schema::dropIfExists('live_class_discussions');
        Schema::dropIfExists('course_lesson_comments');
    }
};
