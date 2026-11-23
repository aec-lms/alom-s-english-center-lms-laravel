<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('teacher_attendance_records')) {
            Schema::create('teacher_attendance_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('teacher_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->date('attendance_date');
                $table->string('status', 20)->default('present');
                $table->timestamp('check_in_at')->nullable();
                $table->timestamp('check_out_at')->nullable();
                $table->string('source', 20)->default('admin');
                $table->string('remarks', 255)->nullable();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['teacher_id', 'attendance_date'], 'teacher_attendance_date_unique');
                $table->index(['branch_id', 'attendance_date', 'status'], 'teacher_attendance_report_idx');
            });
        }
    }

    public function down(): void
    {
        // Attendance history is retained for payroll and compliance.
    }
};
