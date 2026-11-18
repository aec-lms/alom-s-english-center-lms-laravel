<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('lms_notifications') && Schema::hasColumn('lms_notifications', 'branch_id')) {
            Schema::table('lms_notifications', function (Blueprint $table): void {
                $table->index(['branch_id', 'user_id', 'status'], 'lms_notifications_branch_delivery_idx');
            });
        }
    }

    public function down(): void
    {
        // Retain branch delivery index metadata during safe plugin rollback.
    }
};
