<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('course_lessons')) {
            Schema::table('course_lessons', function (Blueprint $table): void {
                if (!Schema::hasColumn('course_lessons', 'video_url')) $table->string('video_url', 255)->nullable();
                if (!Schema::hasColumn('course_lessons', 'duration_minutes')) $table->unsignedSmallInteger('duration_minutes')->default(0);
                if (!Schema::hasColumn('course_lessons', 'is_preview')) $table->boolean('is_preview')->default(false);
            });
        }
    }

    public function down(): void
    {
        // Lesson learning content is preserved when the mobile authoring feature is disabled.
    }
};
