<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_categories')) {
            Schema::create('course_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 140)->unique();
                $table->string('description', 320)->nullable();
                $table->string('color', 32)->default('blue');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table): void {
                if (!Schema::hasColumn('courses', 'category_id')) {
                    $table->unsignedBigInteger('category_id')->nullable()->after('branch_id')->index();
                }
                if (!Schema::hasColumn('courses', 'category')) {
                    $table->string('category', 100)->default('General')->after('title');
                }
                if (!Schema::hasColumn('courses', 'thumbnail')) {
                    $table->string('thumbnail', 255)->nullable()->after('description');
                }
                if (!Schema::hasColumn('courses', 'original_price')) {
                    $table->decimal('original_price', 10, 2)->nullable()->after('price');
                }
                if (!Schema::hasColumn('courses', 'duration_weeks')) {
                    $table->unsignedInteger('duration_weeks')->default(12)->after('original_price');
                }
            });
        }

        if (!Schema::hasTable('live_classes')) {
            Schema::create('live_classes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->string('title', 180);
                $table->string('instructor_name', 120)->default("Engr. Mahbubul Alom");
                $table->string('platform', 40)->default('zoom');
                $table->string('meeting_url', 255);
                $table->string('recording_url', 255)->nullable();
                $table->dateTime('starts_at');
                $table->string('status', 32)->default('upcoming'); // upcoming, live, completed, cancelled
                $table->timestamps();
            });
        }
    }

    public function down(): void {}
};
