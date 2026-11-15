<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('academic_classes')) {
            Schema::create('academic_classes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('code', 40)->unique();
                $table->string('name', 160);
                $table->string('section', 60)->default('Section A');
                $table->string('subject', 120)->default('General English');
                $table->string('room_number', 60)->nullable();
                $table->string('day_of_week', 24)->default('Sunday');
                $table->string('start_time', 20)->default('10:00');
                $table->string('end_time', 20)->default('11:30');
                $table->string('instructor_name', 120)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
