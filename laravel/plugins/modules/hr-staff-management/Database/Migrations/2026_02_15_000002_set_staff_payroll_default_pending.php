<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('staff_payrolls') && Schema::hasColumn('staff_payrolls', 'status')) {
            Schema::table('staff_payrolls', function (Blueprint $table): void {
                $table->string('status', 24)->default('pending')->change();
            });
        }
    }

    public function down(): void
    {
        // Keep pending as the safe default; never silently mark new payroll as disbursed.
    }
};
