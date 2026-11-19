<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('commerce_orders')) return;
        Schema::table('commerce_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('commerce_orders', 'coupon_id')) $table->unsignedBigInteger('coupon_id')->nullable()->index();
            if (!Schema::hasColumn('commerce_orders', 'coupon_code')) $table->string('coupon_code', 40)->nullable();
            if (!Schema::hasColumn('commerce_orders', 'coupon_reservation_key')) $table->string('coupon_reservation_key', 80)->nullable()->unique();
        });
    }

    public function down(): void
    {
        // Preserve coupon redemption references on financial orders for audit/reconciliation.
    }
};
