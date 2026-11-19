<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['course_gifts', 'course_gift_vouchers'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'notification_delivery_status')) {
                    $table->string('notification_delivery_status', 20)->default('pending')->index();
                }
                if (!Schema::hasColumn($tableName, 'notification_attempt_count')) {
                    $table->unsignedSmallInteger('notification_attempt_count')->default(0);
                }
                if (!Schema::hasColumn($tableName, 'notification_last_error')) {
                    $table->text('notification_last_error')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Delivery history is retained for operational and financial auditability.
    }
};
