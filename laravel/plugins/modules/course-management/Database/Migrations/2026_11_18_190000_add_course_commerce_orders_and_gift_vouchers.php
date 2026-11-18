<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_cart_orders')) {
            Schema::create('course_cart_orders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cart_id')->constrained('course_carts')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('payment_transaction_id')->nullable()->index();
                $table->unsignedBigInteger('coupon_id')->nullable()->index();
                $table->string('coupon_code', 40)->nullable();
                $table->string('coupon_reservation_key', 80)->nullable()->unique();
                $table->decimal('subtotal_amount', 10, 2);
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('net_amount', 10, 2);
                $table->string('currency', 3)->default('BDT');
                $table->string('status', 24)->default('awaiting_payment')->index();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }

        if (!Schema::hasTable('course_cart_order_items')) {
            Schema::create('course_cart_order_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->constrained('course_cart_orders')->cascadeOnDelete();
                $table->unsignedBigInteger('course_id')->index();
                $table->string('course_title', 180);
                $table->decimal('unit_price', 10, 2);
                $table->unsignedInteger('position')->default(1);
                $table->timestamps();
                $table->unique(['order_id', 'course_id']);
            });
        }

        if (Schema::hasTable('course_gifts')) {
            Schema::table('course_gifts', function (Blueprint $table): void {
                if (!Schema::hasColumn('course_gifts', 'recipient_name')) $table->string('recipient_name', 160)->nullable();
                if (!Schema::hasColumn('course_gifts', 'message')) $table->text('message')->nullable();
                if (!Schema::hasColumn('course_gifts', 'redeem_token_encrypted')) $table->text('redeem_token_encrypted')->nullable();
                if (!Schema::hasColumn('course_gifts', 'payment_transaction_id')) $table->unsignedBigInteger('payment_transaction_id')->nullable()->index();
            });
        }

        if (!Schema::hasTable('course_gift_vouchers')) {
            Schema::create('course_gift_vouchers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('recipient_email', 190)->index();
                $table->string('recipient_name', 160)->nullable();
                $table->text('message')->nullable();
                $table->char('redeem_token_hash', 64)->unique();
                $table->text('redeem_token_encrypted');
                $table->string('currency', 3)->default('BDT');
                $table->decimal('amount', 10, 2);
                $table->decimal('remaining_amount', 10, 2);
                $table->string('status', 24)->default('awaiting_payment')->index();
                $table->unsignedBigInteger('payment_transaction_id')->nullable()->index();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('redeemed_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
                $table->index(['sender_user_id', 'status']);
            });
        }

        if (!Schema::hasTable('course_gift_voucher_redemptions')) {
            Schema::create('course_gift_voucher_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('voucher_id')->constrained('course_gift_vouchers')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->decimal('amount', 10, 2);
                $table->timestamps();
                $table->unique(['voucher_id', 'course_id']);
                $table->index(['user_id', 'course_id']);
            });
        }
    }

    public function down(): void
    {
        // Keep course orders, gifts, voucher balances, and redemption history for financial auditability.
    }
};
