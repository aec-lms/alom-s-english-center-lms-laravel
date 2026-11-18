<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_lessons')) {
            return;
        }

        Schema::table('course_lessons', function (Blueprint $table): void {
            if (!Schema::hasColumn('course_lessons', 'duration_minutes')) {
                $table->unsignedSmallInteger('duration_minutes')->default(15);
            }
            if (!Schema::hasColumn('course_lessons', 'is_preview')) {
                $table->boolean('is_preview')->default(false);
            }
            if (!Schema::hasColumn('course_lessons', 'video_url')) {
                $table->string('video_url', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Keep lesson metadata when the module is disabled or updated.
    }
};
