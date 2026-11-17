<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payment_transactions')) {
            Schema::create('payment_transactions', function (Blueprint $table): void {
                $table->id();
                $table->string('transaction_ref', 80)->unique();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('gateway_slug', 64); // offline, gateway-sslcommerz, gateway-card
                $table->decimal('amount', 10, 2);
                $table->string('currency', 8)->default('BDT');
                $table->string('status', 32)->default('initiated'); // initiated, settled, failed, refunded
                $table->string('external_reference', 160)->nullable();
                $table->json('metadata')->nullable(); // Strictly sanitized: never store PAN or CVV
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('payment_refunds')) {
            Schema::create('payment_refunds', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
                $table->decimal('amount', 10, 2);
                $table->string('reason', 255);
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
