<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('course_gifts')) {
            Schema::table('course_gifts', function (Blueprint $table): void {
                if (!Schema::hasColumn('course_gifts', 'commerce_order_item_id')) {
                    $table->unsignedBigInteger('commerce_order_item_id')->nullable()->unique();
                }
                if (!Schema::hasColumn('course_gifts', 'unified_payment_transaction_id')) {
                    $table->unsignedBigInteger('unified_payment_transaction_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('course_gift_vouchers')) {
            Schema::table('course_gift_vouchers', function (Blueprint $table): void {
                if (!Schema::hasColumn('course_gift_vouchers', 'commerce_order_item_id')) {
                    $table->unsignedBigInteger('commerce_order_item_id')->nullable()->unique();
                }
                if (!Schema::hasColumn('course_gift_vouchers', 'unified_payment_transaction_id')) {
                    $table->unsignedBigInteger('unified_payment_transaction_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        // Gift/payment linkage is financial audit history; retain it on rollback.
    }
};
