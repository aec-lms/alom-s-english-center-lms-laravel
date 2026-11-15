<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('academic_sessions')) {
            Schema::create('academic_sessions', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->date('starts_on');
                $table->date('ends_on');
                $table->boolean('is_current')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('courses')) {
            Schema::create('courses', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('code', 40)->unique();
                $table->string('title', 180);
                $table->string('slug', 200)->unique();
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('status', 32)->default('draft');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('course_lessons')) {
            Schema::create('course_lessons', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->string('title', 180);
                $table->text('content')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('batches')) {
            Schema::create('batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('name', 120);
                $table->unsignedInteger('capacity')->default(30);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('enrollments')) {
            Schema::create('enrollments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('status', 32)->default('pending');
                $table->timestamps();
                $table->unique(['user_id', 'course_id']);
            });
        }
    }

    public function down(): void {}
};
