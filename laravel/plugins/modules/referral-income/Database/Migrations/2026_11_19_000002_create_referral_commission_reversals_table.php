<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('referral_commissions') || Schema::hasTable('referral_commission_reversals')) {
            return;
        }

        Schema::create('referral_commission_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_commission_id')->constrained('referral_commissions')->restrictOnDelete();
            $table->unsignedBigInteger('payment_refund_id')->unique();
            $table->decimal('eligible_refund_amount', 12, 2);
            $table->decimal('commission_reversal_amount', 12, 2);
            $table->string('currency', 8)->default('BDT');
            $table->string('status', 24)->default('applied');
            $table->timestamps();
            $table->index(['referral_commission_id', 'status'], 'ref_commission_reversal_status_idx');
        });
    }

    public function down(): void
    {
        // Financial reversal history is intentionally retained during plugin lifecycle operations.
    }
};
