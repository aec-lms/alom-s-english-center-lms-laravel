<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('staff_payrolls')) {
            return;
        }

        $duplicate = DB::table('staff_payrolls')
            ->select('staff_profile_id', 'payroll_month')
            ->groupBy('staff_profile_id', 'payroll_month')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(
                "Cannot enforce one payroll per staff member per month: duplicate payroll exists for staff {$duplicate->staff_profile_id}, month {$duplicate->payroll_month}. Reconcile the duplicate payroll records before rerunning migrations."
            );
        }

        Schema::table('staff_payrolls', function (Blueprint $table): void {
            $table->unique(
                ['staff_profile_id', 'payroll_month'],
                'staff_payroll_member_month_unique'
            );
        });
    }

    public function down(): void
    {
        // Payroll uniqueness is retained to protect historical disbursement records.
    }
};
