<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('staff_attendance_records') && !Schema::hasColumn('staff_attendance_records', 'leave_paid')) {
            Schema::table('staff_attendance_records', function (Blueprint $table): void {
                $table->boolean('leave_paid')->default(true)->after('status');
            });
        }
        if (Schema::hasTable('staff_payrolls')) {
            Schema::table('staff_payrolls', function (Blueprint $table): void {
                if (!Schema::hasColumn('staff_payrolls', 'present_days')) $table->unsignedSmallInteger('present_days')->default(0);
                if (!Schema::hasColumn('staff_payrolls', 'late_days')) $table->unsignedSmallInteger('late_days')->default(0);
                if (!Schema::hasColumn('staff_payrolls', 'absent_days')) $table->unsignedSmallInteger('absent_days')->default(0);
                if (!Schema::hasColumn('staff_payrolls', 'paid_leave_days')) $table->unsignedSmallInteger('paid_leave_days')->default(0);
                if (!Schema::hasColumn('staff_payrolls', 'unpaid_leave_days')) $table->unsignedSmallInteger('unpaid_leave_days')->default(0);
                if (!Schema::hasColumn('staff_payrolls', 'attendance_deduction')) $table->decimal('attendance_deduction', 12, 2)->default(0);
                if (!Schema::hasColumn('staff_payrolls', 'late_deduction')) $table->decimal('late_deduction', 12, 2)->default(0);
                if (!Schema::hasColumn('staff_payrolls', 'late_deduction_per_day')) $table->decimal('late_deduction_per_day', 10, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        // Payroll and attendance calculation history is retained.
    }
};
