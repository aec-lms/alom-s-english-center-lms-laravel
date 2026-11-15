<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('universal_payment_transactions')) {
            Schema::create('universal_payment_transactions', function (Blueprint $table): void {
                $table->id();
                $table->string('reference', 80)->unique();
                $table->string('payer_name', 160);
                $table->string('payer_contact', 120)->nullable();
                $table->string('item_title', 200);
                $table->string('gateway', 40)->default('sslcommerz');
                $table->string('currency', 10)->default('BDT');
                $table->decimal('amount', 12, 2);
                $table->string('external_trx_id', 120)->nullable();
                $table->string('status', 30)->default('pending');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall per core policy
    }
};
