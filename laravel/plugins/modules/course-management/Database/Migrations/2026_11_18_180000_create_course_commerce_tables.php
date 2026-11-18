<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('course_carts')) {
            Schema::create('course_carts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('status', 16)->default('active')->index();
                $table->string('currency', 3)->default('BDT');
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }

        if (!Schema::hasTable('course_cart_items')) {
            Schema::create('course_cart_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cart_id')->constrained('course_carts')->cascadeOnDelete();
                $table->unsignedBigInteger('course_id')->index();
                $table->decimal('unit_price', 10, 2);
                $table->timestamps();
                $table->unique(['cart_id', 'course_id']);
            });
        }

        if (!Schema::hasTable('course_gifts')) {
            Schema::create('course_gifts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
                $table->string('recipient_email', 190)->index();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->string('redeem_token_hash', 64)->unique();
                $table->string('status', 16)->default('awaiting_payment')->index();
                $table->string('currency', 3)->default('BDT');
                $table->decimal('amount', 10, 2);
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('redeemed_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('promo_coupons')) {
            Schema::table('promo_coupons', function (Blueprint $table): void {
                if (!Schema::hasColumn('promo_coupons', 'description')) $table->string('description', 200)->nullable();
                if (!Schema::hasColumn('promo_coupons', 'discount_type')) $table->string('discount_type', 16)->default('percent');
                if (!Schema::hasColumn('promo_coupons', 'discount_amount')) $table->decimal('discount_amount', 10, 2)->nullable();
                if (!Schema::hasColumn('promo_coupons', 'minimum_subtotal')) $table->decimal('minimum_subtotal', 10, 2)->default(0);
                if (!Schema::hasColumn('promo_coupons', 'maximum_discount')) $table->decimal('maximum_discount', 10, 2)->nullable();
                if (!Schema::hasColumn('promo_coupons', 'per_user_limit')) $table->unsignedInteger('per_user_limit')->nullable();
                if (!Schema::hasColumn('promo_coupons', 'starts_at')) $table->timestamp('starts_at')->nullable();
                if (!Schema::hasColumn('promo_coupons', 'applies_to_course_ids')) $table->json('applies_to_course_ids')->nullable();
                if (!Schema::hasColumn('promo_coupons', 'first_purchase_only')) $table->boolean('first_purchase_only')->default(false);
            });
            if (!Schema::hasTable('promo_coupon_redemptions')) {
                Schema::create('promo_coupon_redemptions', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('coupon_id')->constrained('promo_coupons')->cascadeOnDelete();
                    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                    $table->string('reservation_key', 80)->unique();
                    $table->string('status', 16)->default('reserved')->index();
                    $table->decimal('subtotal', 10, 2);
                    $table->decimal('discount_amount', 10, 2);
                    $table->timestamp('expires_at')->nullable()->index();
                    $table->timestamp('redeemed_at')->nullable();
                    $table->timestamps();
                    $table->index(['coupon_id', 'user_id', 'status']);
                });
            }
        }
    }

    public function down(): void
    {
        // Commerce records are financial/audit history and are intentionally retained.
    }
};
