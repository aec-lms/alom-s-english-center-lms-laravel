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

        if (!Schema::hasColumn('staff_payrolls', 'payment_reference')) {
            Schema::table('staff_payrolls', function (Blueprint $table): void {
                $table->string('payment_reference', 120)->nullable()->after('paid_at');
            });
        }

        // Older versions marked a payroll as paid immediately when it was created.
        // Without a disbursement reference, that status was not verified.
        DB::table('staff_payrolls')
            ->whereNull('payment_reference')
            ->update([
                'status' => 'pending',
                'paid_at' => null,
            ]);
    }

    public function down(): void
    {
        // Preserve payroll history and disbursement evidence.
    }
};
