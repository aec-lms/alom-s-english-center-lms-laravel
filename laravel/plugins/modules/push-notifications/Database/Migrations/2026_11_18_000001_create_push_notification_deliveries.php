<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('push_notification_deliveries')) {
            Schema::create('push_notification_deliveries', function (Blueprint $table): void {
                $table->id();
                // No foreign keys to optional Notification Center or Mobile App plugins.
                $table->unsignedBigInteger('notification_id')->index();
                $table->unsignedBigInteger('device_token_id')->index();
                $table->string('status', 20)->default('pending');
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->unique(['notification_id', 'device_token_id'], 'push_delivery_notification_device_unique');
            });
        }
    }

    public function down(): void
    {
        // Delivery receipts are retained for audit and retry safety.
    }
};
