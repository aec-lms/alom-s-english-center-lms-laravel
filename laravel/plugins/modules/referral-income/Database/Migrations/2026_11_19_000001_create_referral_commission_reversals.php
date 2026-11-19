<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('referral_commission_reversals')) {
            return;
        }

        Schema::create('referral_commission_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_commission_id')->constrained('referral_commissions')->restrictOnDelete();
            $table->unsignedBigInteger('payment_refund_id');
            $table->decimal('refunded_amount', 12, 2);
            $table->decimal('commission_reversal_amount', 12, 2);
            $table->string('currency', 8)->default('BDT');
            $table->string('status', 24)->default('applied');
            $table->text('admin_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique('payment_refund_id', 'ref_commission_reversal_refund_unique');
            $table->index(['referral_commission_id', 'status'], 'ref_commission_reversal_status_idx');
        });
    }

    public function down(): void
    {
        // Financial reversal history is retained when the optional plugin is updated or removed.
    }
};
