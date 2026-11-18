<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('commerce_carts')) {
            Schema::create('commerce_carts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('currency', 8)->default('BDT');
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }

        if (!Schema::hasTable('commerce_cart_items')) {
            Schema::create('commerce_cart_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cart_id')->constrained('commerce_carts')->cascadeOnDelete();
                $table->string('item_type', 32);
                $table->unsignedBigInteger('item_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 10, 2);
                $table->string('currency', 8)->default('BDT');
                $table->string('title_snapshot', 200);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['cart_id', 'item_type', 'item_id']);
                $table->index(['item_type', 'item_id']);
            });
        }

        if (!Schema::hasTable('commerce_orders')) {
            Schema::create('commerce_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('order_number', 32)->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('currency', 8)->default('BDT');
                $table->decimal('subtotal', 10, 2);
                $table->decimal('discount_total', 10, 2)->default(0);
                $table->decimal('grand_total', 10, 2);
                $table->string('status', 24)->default('awaiting_payment')->index();
                $table->string('payment_status', 24)->default('unpaid')->index();
                $table->json('billing_snapshot')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }

        if (!Schema::hasTable('commerce_order_items')) {
            Schema::create('commerce_order_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->constrained('commerce_orders')->cascadeOnDelete();
                $table->string('item_type', 32);
                $table->unsignedBigInteger('item_id')->nullable()->index();
                $table->string('title_snapshot', 200);
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 10, 2);
                $table->decimal('line_total', 10, 2);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['item_type', 'item_id']);
            });
        }

        if (!Schema::hasTable('commerce_sellables')) {
            Schema::create('commerce_sellables', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 32)->index();
                $table->string('title', 200);
                $table->string('sku', 80)->nullable()->unique();
                $table->unsignedBigInteger('resource_id')->nullable()->index();
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2);
                $table->string('currency', 8)->default('BDT');
                $table->json('entitlement')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('available_from')->nullable();
                $table->timestamp('available_until')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('commerce_entitlements')) {
            Schema::create('commerce_entitlements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('order_item_id')->constrained('commerce_order_items')->cascadeOnDelete();
                $table->string('entitlement_type', 32);
                $table->unsignedBigInteger('resource_id')->nullable()->index();
                $table->json('details')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique(['user_id', 'order_item_id']);
            });
        }
    }

    public function down(): void
    {
        // Commerce orders and entitlements are retained under the LMS data-preservation policy.
    }
};
