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
            if (!Schema::hasColumn('course_lessons', 'media_path')) {
                $table->string('media_path', 500)->nullable();
            }
            if (!Schema::hasColumn('course_lessons', 'media_mime')) {
                $table->string('media_mime', 120)->nullable();
            }
            if (!Schema::hasColumn('course_lessons', 'protection_enabled')) {
                $table->boolean('protection_enabled')->default(true);
            }
            if (!Schema::hasColumn('course_lessons', 'is_downloadable')) {
                $table->boolean('is_downloadable')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('course_lessons')) {
            return;
        }

        Schema::table('course_lessons', function (Blueprint $table): void {
            foreach (['media_path', 'media_mime', 'protection_enabled', 'is_downloadable'] as $column) {
                if (Schema::hasColumn('course_lessons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
