<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_content_categories')) {
            Schema::create('course_content_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 140)->unique();
                $table->string('description', 320)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_visible')->default(true);
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('course_content_posts')) {
            Schema::create('course_content_posts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
                $table->string('title', 180);
                $table->string('slug', 200)->unique();
                $table->string('short_description', 500)->nullable();
                $table->longText('long_description');
                $table->string('video_url', 500)->nullable();
                $table->string('thumbnail_path', 255)->nullable();
                $table->string('content_file_path', 255)->nullable();
                $table->string('content_file_name', 255)->nullable();
                $table->string('content_file_mime', 120)->nullable();
                $table->unsignedBigInteger('content_file_size')->nullable();
                $table->string('access_type', 24)->default('course_enrollment')->index();
                $table->string('seo_title', 180)->nullable();
                $table->string('seo_description', 320)->nullable();
                $table->json('tags')->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamps();
                $table->index(['status', 'published_at']);
            });
        }
        if (!Schema::hasTable('course_content_post_category')) {
            Schema::create('course_content_post_category', function (Blueprint $table): void {
                $table->foreignId('course_content_post_id')->constrained('course_content_posts')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('course_content_categories')->cascadeOnDelete();
                $table->primary(['course_content_post_id', 'category_id']);
            });
        }
        if (!Schema::hasTable('course_curriculum_items')) {
            Schema::create('course_curriculum_items', function (Blueprint $table): void {
                $table->id();
                // Course Management is optional; avoid a cross-plugin foreign key.
                $table->unsignedBigInteger('course_id')->index();
                $table->foreignId('course_content_post_id')->constrained('course_content_posts')->cascadeOnDelete();
                $table->string('section_title', 120)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_preview')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('duration_minutes')->nullable();
                $table->timestamps();
                $table->unique(['course_id', 'course_content_post_id'], 'ccs_course_post_unique');
                $table->index(['course_id', 'position']);
            });
        }
        if (!Schema::hasTable('course_content_progress')) {
            Schema::create('course_content_progress', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('course_curriculum_item_id')->constrained('course_curriculum_items')->cascadeOnDelete();
                $table->timestamp('last_viewed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'course_curriculum_item_id'], 'ccs_progress_user_item_unique');
            });
        }
    }

    public function down(): void
    {
        // Content, curriculum and student progress are retained on plugin uninstall.
    }
};
