<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('study_batches')) {
            Schema::create('study_batches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('course_title', 180)->nullable();
                $table->string('batch_code', 40)->unique();
                $table->string('name', 160);
                $table->string('schedule', 160);
                $table->string('room_number', 60)->nullable();
                $table->unsignedInteger('max_capacity')->default(30);
                $table->unsignedInteger('enrolled_count')->default(0);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('status', 24)->default('ongoing'); // upcoming, ongoing, completed
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
