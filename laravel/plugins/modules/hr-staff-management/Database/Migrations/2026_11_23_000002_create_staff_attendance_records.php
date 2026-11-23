<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('staff_attendance_records')) return;

        Schema::create('staff_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->date('attendance_date');
            $table->string('status', 16); // present, absent, late, leave
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('source', 16)->default('admin');
            $table->string('remarks', 255)->nullable();
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->timestamps();
            $table->unique(['staff_profile_id', 'attendance_date'], 'staff_attendance_member_day_unique');
            $table->index(['branch_id', 'attendance_date']);
        });
    }

    public function down(): void
    {
        // Attendance history is retained when the optional HR module is disabled.
    }
};
