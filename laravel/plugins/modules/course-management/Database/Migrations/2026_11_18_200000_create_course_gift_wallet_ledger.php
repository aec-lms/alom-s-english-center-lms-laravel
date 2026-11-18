<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('course_cart_orders')) {
            Schema::table('course_cart_orders', function (Blueprint $table): void {
                if (!Schema::hasColumn('course_cart_orders', 'wallet_credit_amount')) $table->decimal('wallet_credit_amount', 10, 2)->default(0);
                if (!Schema::hasColumn('course_cart_orders', 'wallet_reservation_key')) $table->string('wallet_reservation_key', 80)->nullable()->unique();
            });
        }

        if (Schema::hasTable('course_gift_vouchers')) {
            Schema::table('course_gift_vouchers', function (Blueprint $table): void {
                if (!Schema::hasColumn('course_gift_vouchers', 'recipient_user_id')) $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
                if (!Schema::hasColumn('course_gift_vouchers', 'notification_sent_at')) $table->timestamp('notification_sent_at')->nullable();
                if (!Schema::hasColumn('course_gift_vouchers', 'invoice_id')) $table->unsignedBigInteger('invoice_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('course_gift_voucher_redemptions') && Schema::hasColumn('course_gift_voucher_redemptions', 'course_id')) {
            Schema::table('course_gift_voucher_redemptions', function (Blueprint $table): void {
                $table->unsignedBigInteger('course_id')->nullable()->change();
            });
        }

        if (!Schema::hasTable('course_gift_wallets')) {
            Schema::create('course_gift_wallets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('currency', 3)->default('BDT');
                $table->decimal('balance', 12, 2)->default(0);
                $table->timestamps();
                $table->unique(['user_id', 'currency']);
            });
        }

        if (!Schema::hasTable('course_gift_wallet_transactions')) {
            Schema::create('course_gift_wallet_transactions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('wallet_id')->constrained('course_gift_wallets')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('voucher_id')->nullable()->index();
                $table->string('type', 16);
                $table->string('status', 16)->default('completed')->index();
                $table->string('reference', 100)->unique();
                $table->decimal('amount', 12, 2);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'type', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Wallet balances and financial ledger entries are retained for auditability.
    }
};
