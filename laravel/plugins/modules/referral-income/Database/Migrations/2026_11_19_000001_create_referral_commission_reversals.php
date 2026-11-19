<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('referral_commission_reversals')) {
            Schema::create('referral_commission_reversals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('commission_id')->constrained('referral_commissions')->restrictOnDelete();
                $table->foreignId('payment_refund_id')->constrained('payment_refunds')->restrictOnDelete();
                $table->decimal('refund_amount', 12, 2);
                $table->decimal('commission_reversal_amount', 12, 2);
                $table->string('currency', 8)->default('BDT');
                $table->string('status', 24)->default('applied'); // applied, recovery_due, recovered, waived
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique('payment_refund_id', 'ref_commission_refund_unique');
                $table->index(['commission_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Financial audit records are intentionally preserved on plugin uninstall.
    }
};
